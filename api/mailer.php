<?php
declare(strict_types=1);

// Email: templates, an SMTP client (no extra libraries needed on XAMPP), delivery
// logging, and the report status change that triggers notifications.
// Included by bootstrap.php; never requested directly.

if (!defined('LF_APP')) {
    http_response_code(403);
    exit;
}

final class MailError extends RuntimeException
{
}

const EMAIL_TEMPLATES = [
    'report_received' => 'Report received',
    'status_changed' => 'Status update',
    'match_found' => 'Matching item found',
    'claim_received' => 'Claim received',
    'claim_approved' => 'Claim approved',
    'claim_rejected' => 'Claim rejected',
    'staff_assigned' => 'Staff assignment',
    'test' => 'Test email',
    'resend' => 'Resent email',
];

function mailSettings(): array
{
    $defaults = [
        'enabled' => true,
        'transport' => 'smtp',
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'Campus Lost & Found',
        'reply_to' => '',
        'timeout' => 15,
        'verify_certificate' => true,
    ];
    return array_merge($defaults, config()['mail'] ?? []);
}

/** What staff may see about the mail setup. Never includes the username or password. */
function mailStatus(): array
{
    $s = mailSettings();
    return [
        'enabled' => (bool) $s['enabled'],
        'transport' => $s['transport'],
        'host' => $s['transport'] === 'smtp' ? (string) $s['host'] : null,
        'port' => $s['transport'] === 'smtp' ? (int) $s['port'] : null,
        'encryption' => $s['transport'] === 'smtp' ? (string) $s['encryption'] : null,
        'authentication' => $s['transport'] === 'smtp' && (string) $s['username'] !== '',
        'fromEmail' => (string) $s['from_email'],
        'fromName' => (string) $s['from_name'],
        'problem' => mailConfigProblem($s),
    ];
}

function mailConfigProblem(array $s): ?string
{
    if (!$s['enabled']) {
        return 'Email sending is turned off. Set LF_MAIL_ENABLED=true in the Apache environment.';
    }
    if (!in_array($s['transport'], ['smtp', 'mail'], true)) {
        return 'The configured mail transport must be "smtp" or "mail".';
    }
    if (!isValidEmail((string) $s['from_email'])) {
        return 'Set LF_MAIL_FROM_EMAIL to the address emails are sent from.';
    }
    if ($s['transport'] === 'smtp') {
        if (trim((string) $s['host']) === '') {
            return 'Set LF_MAIL_HOST to your SMTP server (for example smtp.gmail.com).';
        }
        if (!in_array($s['encryption'], ['tls', 'ssl', 'none'], true)) {
            return 'LF_MAIL_ENCRYPTION must be "tls", "ssl" or "none".';
        }
        if ((string) $s['username'] !== '' && (string) $s['password'] === '') {
            return 'LF_MAIL_USERNAME is set but LF_MAIL_PASSWORD is empty.';
        }
    }
    return null;
}

/* ------------------------------------------------------------------------- */
/* Delivery                                                                    */
/* ------------------------------------------------------------------------- */

/**
 * Renders a template, sends it and records the attempt in email_notifications.
 *
 * @param array{report_id?: ?string, claim_id?: ?int, match_id?: ?int, item_title?: ?string} $links
 * @return array{status: string, message: string, recipient?: string, id?: int}
 */
function sendEmail(string $template, ?string $to, array $data, array $links = [], ?int $triggeredBy = null): array
{
    $to = trim((string) $to);
    if ($to === '') {
        return ['status' => 'skipped', 'message' => 'No email address is on file, so no email was sent.'];
    }
    [$subject, $text, $html, $images] = renderEmail($template, $data);
    return deliverAndLog($template, $to, $subject, $text, $html, $images, $links, $triggeredBy);
}

function deliverAndLog(string $template, string $to, string $subject, string $text, ?string $html, array $images, array $links, ?int $triggeredBy): array
{
    $error = null;
    try {
        if (!isValidEmail($to)) {
            throw new MailError('The recipient address is not a valid email address.');
        }
        deliverEmail($to, $subject, $text, $html, $images);
    } catch (MailError $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[lost-found] mail ' . $e);
        $error = 'The email could not be sent because of an unexpected server error.';
    }

    $status = $error === null ? 'sent' : 'failed';
    $id = null;
    try {
        db()->prepare(
            'INSERT INTO email_notifications
               (template, recipient, subject, body_text, status, error, item_title, report_id, claim_id, match_id, triggered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $template, mb_substr($to, 0, 120), mb_substr($subject, 0, 200), $text, $status,
            $error !== null ? mb_substr($error, 0, 500) : null,
            isset($links['item_title']) ? mb_substr((string) $links['item_title'], 0, 70) : null,
            $links['report_id'] ?? null, $links['claim_id'] ?? null, $links['match_id'] ?? null, $triggeredBy,
        ]);
        $id = (int) db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[lost-found] could not log email ' . $e);
    }

    return $error === null
        ? ['status' => 'sent', 'message' => "Email sent to {$to}.", 'recipient' => $to, 'id' => $id]
        : ['status' => 'failed', 'message' => "Email to {$to} failed: {$error}", 'error' => $error, 'recipient' => $to, 'id' => $id];
}

/** @param list<array{cid: string, path: string, mime: string, name: string}> $images */
function deliverEmail(string $to, string $subject, string $text, ?string $html, array $images = []): void
{
    $s = mailSettings();
    $problem = mailConfigProblem($s);
    if ($problem !== null) {
        throw new MailError($problem);
    }
    $from = (string) $s['from_email'];
    [$headers, $body] = buildMimeMessage($s, $to, $subject, $text, $html, $images);

    if ($s['transport'] === 'mail') {
        // PHP's mail() adds To and Subject itself.
        $extra = preg_replace('/^(To|Subject): .*\r\n/m', '', $headers);
        $encodedSubject = encodeHeader($subject);
        if (!@mail($to, $encodedSubject, $body, rtrim((string) $extra, "\r\n"))) {
            throw new MailError('PHP mail() could not send the message. Configure sendmail in php.ini, or switch mail.transport to "smtp".');
        }
        return;
    }
    smtpSend($s, $from, $to, $headers . "\r\n" . $body);
}

function encodeHeader(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    return preg_match('/[^\x20-\x7E]/', $value) ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n") : $value;
}

function formatAddress(string $email, string $name = ''): string
{
    $name = trim(str_replace(["\r", "\n", '"'], ' ', $name));
    if ($name === '') {
        return "<{$email}>";
    }
    $name = preg_match('/[^\x20-\x7E]/', $name) ? mb_encode_mimeheader($name, 'UTF-8', 'B') : '"' . $name . '"';
    return "{$name} <{$email}>";
}

/** @return array{0: string, 1: string} headers (CRLF-terminated) and body */
function buildMimeMessage(array $s, string $to, string $subject, string $text, ?string $html, array $images): array
{
    $from = (string) $s['from_email'];
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . formatAddress($from, (string) $s['from_name']),
        'To: <' . $to . '>',
        'Subject: ' . encodeHeader($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
        'X-Mailer: Campus Lost & Found',
    ];
    if (isValidEmail((string) $s['reply_to'])) {
        $headers[] = 'Reply-To: <' . $s['reply_to'] . '>';
    }

    $part = static fn (string $type, string $content): string =>
        "Content-Type: {$type}\r\nContent-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode($content), 76, "\r\n")) . "\r\n";

    $textPart = $part('text/plain; charset=UTF-8', $text);
    if ($html === null) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        return [implode("\r\n", $headers) . "\r\n", rtrim(chunk_split(base64_encode($text), 76, "\r\n")) . "\r\n"];
    }

    $htmlPart = $part('text/html; charset=UTF-8', $html);
    $usable = array_values(array_filter($images, static fn ($img) => is_file($img['path']) && filesize($img['path']) <= 4 * 1024 * 1024));
    if ($usable) {
        $related = 'rel_' . bin2hex(random_bytes(8));
        $relatedBody = "--{$related}\r\n{$htmlPart}";
        foreach ($usable as $img) {
            $relatedBody .= "--{$related}\r\n"
                . "Content-Type: {$img['mime']}; name=\"{$img['name']}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-ID: <{$img['cid']}>\r\n"
                . "Content-Disposition: inline; filename=\"{$img['name']}\"\r\n\r\n"
                . rtrim(chunk_split(base64_encode((string) file_get_contents($img['path'])), 76, "\r\n")) . "\r\n";
        }
        $relatedBody .= "--{$related}--\r\n";
        $htmlPart = "Content-Type: multipart/related; boundary=\"{$related}\"\r\n\r\n{$relatedBody}";
    }

    $alt = 'alt_' . bin2hex(random_bytes(8));
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$alt}\"";
    $body = "This is a multi-part message in MIME format.\r\n\r\n"
        . "--{$alt}\r\n{$textPart}"
        . "--{$alt}\r\n{$htmlPart}"
        . "--{$alt}--\r\n";
    return [implode("\r\n", $headers) . "\r\n", $body];
}

/** Minimal SMTP client: SSL or STARTTLS, AUTH LOGIN/PLAIN, one recipient. */
function smtpSend(array $s, string $from, string $to, string $message): void
{
    $host = trim((string) $s['host']);
    $port = (int) $s['port'];
    $timeout = max(5, (int) $s['timeout']);
    $encryption = (string) $s['encryption'];
    $verify = (bool) $s['verify_certificate'];

    $context = stream_context_create(['ssl' => [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
        'peer_name' => $host,
        'SNI_enabled' => true,
    ]]);
    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        $reason = trim($errstr) !== '' ? trim($errstr) : 'no response';
        throw new MailError("Could not connect to the mail server {$host}:{$port} ({$reason}). Check LF_MAIL_HOST, LF_MAIL_PORT and LF_MAIL_ENCRYPTION.");
    }
    stream_set_timeout($socket, $timeout);

    $read = static function () use ($socket, $host): array {
        $lines = '';
        while (($line = fgets($socket, 2048)) !== false) {
            $lines .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($lines === '') {
            $meta = stream_get_meta_data($socket);
            throw new MailError($meta['timed_out'] ?? false
                ? "The mail server {$host} stopped responding (timed out)."
                : "The mail server {$host} closed the connection unexpectedly.");
        }
        return [(int) substr($lines, 0, 3), $lines];
    };
    $command = static function (?string $line, array $expect, string $what) use ($socket, $read): string {
        if ($line !== null) {
            fwrite($socket, $line . "\r\n");
        }
        [$code, $reply] = $read();
        if (!in_array($code, $expect, true)) {
            $last = trim((string) preg_replace('/\s+/', ' ', $reply));
            throw new MailError("The mail server rejected {$what}: {$last}");
        }
        return $reply;
    };

    try {
        $command(null, [220], 'the connection');
        $helo = preg_replace('/[^A-Za-z0-9.-]/', '', (string) (gethostname() ?: 'localhost')) ?: 'localhost';
        $ehlo = $command("EHLO {$helo}", [250], 'the greeting (EHLO)');

        if ($encryption === 'tls') {
            if (!preg_match('/^250[ -]STARTTLS/mi', $ehlo)) {
                throw new MailError("The mail server {$host} does not support STARTTLS. Try LF_MAIL_ENCRYPTION=ssl with LF_MAIL_PORT=465.");
            }
            $command('STARTTLS', [220], 'STARTTLS');
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!@stream_socket_enable_crypto($socket, true, $method)) {
                throw new MailError("Could not start a secure (TLS) connection with {$host}. Check the server name and certificate.");
            }
            $ehlo = $command("EHLO {$helo}", [250], 'the greeting after STARTTLS');
        }

        $user = (string) $s['username'];
        if ($user !== '') {
            $pass = (string) $s['password'];
            if (preg_match('/^250[ -]AUTH[ =](.*)$/mi', $ehlo, $m) && stripos($m[1], 'LOGIN') === false && stripos($m[1], 'PLAIN') !== false) {
                fwrite($socket, 'AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}") . "\r\n");
            } else {
                $command('AUTH LOGIN', [334], 'the sign-in request');
                $command(base64_encode($user), [334], 'the username');
                fwrite($socket, base64_encode($pass) . "\r\n");
            }
            [$code, $reply] = $read();
            if ($code !== 235) {
                throw new MailError('The mail server did not accept the SMTP username or password (' . $code . '). '
                    . 'Check LF_MAIL_USERNAME and LF_MAIL_PASSWORD. For Gmail, use an App Password, not your normal password.');
            }
        }

        $command("MAIL FROM:<{$from}>", [250], 'the sender address');
        $command("RCPT TO:<{$to}>", [250, 251], "the recipient address {$to}");
        $command('DATA', [354], 'the message');
        $data = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
        $data = preg_replace('/^\./m', '..', (string) $data);
        fwrite($socket, $data . (str_ends_with((string) $data, "\r\n") ? '' : "\r\n") . ".\r\n");
        $command(null, [250], 'the message content');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }
}

/* ------------------------------------------------------------------------- */
/* Templates                                                                   */
/* ------------------------------------------------------------------------- */

function appUrl(string $fragment = ''): ?string
{
    $base = trim((string) (config()['app_url'] ?? ''));
    if ($base === '' || !preg_match('#^https?://#i', $base)) {
        return null;
    }
    return rtrim($base, '/') . '/' . $fragment;
}

function emailDate(?string $value = null, bool $withTime = false): string
{
    $tz = new DateTimeZone((string) (config()['timezone'] ?? 'UTC'));
    if ($value === null) {
        $date = new DateTimeImmutable('now', $tz);
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $date = new DateTimeImmutable($value, $tz);
        $withTime = false;
    } else {
        $date = (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($tz);
    }
    return $date->format($withTime ? 'F j, Y, g:i A' : 'F j, Y');
}

function officeInfo(): array
{
    $c = config();
    return [
        'name' => (string) ($c['office']['name'] ?? 'Campus Security Office'),
        'location' => (string) ($c['office']['location'] ?? 'Admin Building, Room 104'),
        'hours' => (string) ($c['office']['hours'] ?? 'Monday to Friday, 8:00 AM to 5:00 PM'),
    ];
}

function reportImage(array $report, string $cid): ?array
{
    if (empty($report['photo'])) {
        return null;
    }
    $path = dirname(__DIR__) . '/storage/reports/' . $report['photo'];
    return is_file($path) ? ['cid' => $cid, 'path' => $path, 'mime' => (string) $report['photo_mime'], 'name' => $report['photo']] : null;
}

/** Item details shown in most emails. */
function itemRows(array $r, bool $withStatus = true): array
{
    $rows = [
        'Item' => $r['title'],
        'Description' => trim((string) $r['description']) !== '' ? $r['description'] : 'No description provided',
        'Category' => $r['category'],
        ($r['type'] === 'lost' ? 'Last seen at' : 'Found at') => $r['location'],
        ($r['type'] === 'lost' ? 'Date lost' : 'Date found') => emailDate($r['date']),
    ];
    if ($withStatus) {
        $rows['Status'] = statusLabel($r['status']);
    }
    if (!empty($r['reference'])) {
        $rows['Reference'] = $r['reference'];
    }
    return $rows;
}

function statusInstructions(array $r): string
{
    $o = officeInfo();
    $where = "{$o['name']}, {$o['location']} ({$o['hours']})";
    $lost = $r['type'] === 'lost';
    return match ($r['status']) {
        'pending' => 'Your report is being reviewed by Campus Security. It will appear on the community board once it is approved.',
        'active' => $lost
            ? 'Your report is now on the community board. We will email you if a matching item is turned in. Keep your reference code so you can check the status at any time.'
            : 'The item you reported is now on the community board so its owner can find and claim it. Thank you for turning it in.',
        'resolved' => $lost
            ? "Good news: your item has been marked as Found. If you have not collected it yet, visit {$where}. Bring a valid ID and your reference code {$r['reference']}."
            : 'The item you reported has been marked as Found and returned to its owner. Thank you for helping a member of our campus community.',
        default => 'Contact Campus Security if you have any questions.',
    };
}

/**
 * @return array{0: string, 1: string, 2: string, 3: list<array>} subject, text, html, inline images
 */
function renderEmail(string $template, array $d): array
{
    $o = officeInfo();
    $images = [];
    $image = null;
    $link = null;
    $linkLabel = '';
    $note = null;

    switch ($template) {
        case 'report_received':
            $r = $d['report'];
            $kind = $r['type'] === 'lost' ? 'lost' : 'found';
            $subject = "We received your {$kind} item report: {$r['title']} [{$r['reference']}]";
            $heading = 'Thank you, we received your report';
            $intro = "Your {$kind} item report was submitted on " . emailDate(null, true) . '. Keep this email: your reference code is ' . $r['reference'] . '.';
            $rows = itemRows($r);
            $instructions = statusInstructions($r);
            $link = appUrl('#/track/' . rawurlencode($r['reference']));
            $linkLabel = 'Check your report status';
            break;

        case 'status_changed':
            $r = $d['report'];
            $label = statusLabel($r['status']);
            $subject = "Status update: {$r['title']} is now \"{$label}\" [{$r['reference']}]";
            $heading = "Your report is now: {$label}";
            $intro = 'The status of your ' . ($r['type'] === 'lost' ? 'lost' : 'found') . ' item report changed from "'
                . statusLabel((string) $d['oldStatus']) . '" to "' . $label . '" on ' . emailDate(null, true) . '.';
            $rows = itemRows($r);
            $rows['Updated on'] = emailDate(null, true);
            $instructions = statusInstructions($r);
            $link = appUrl('#/track/' . rawurlencode($r['reference']));
            $linkLabel = 'Check your report status';
            break;

        case 'match_found':
            $lost = $d['lost'];
            $found = $d['found'];
            $subject = "A possible match for your lost {$lost['title']} has been turned in [{$lost['reference']}]";
            $heading = 'A matching item has been reported';
            $intro = "Campus Security has received a found item that may be your lost \"{$lost['title']}\" (reference {$lost['reference']}). Here are the details of the found item:";
            $rows = [
                'Found item' => $found['title'],
                'Description' => trim((string) $found['description']) !== '' ? $found['description'] : 'No description provided',
                'Category' => $found['category'],
                'Found at' => $found['location'],
                'Date found' => emailDate($found['date']),
                'Status' => statusLabel($found['status']),
                'Your reference' => $lost['reference'],
            ];
            if ($image = reportImage($found, 'found-item')) {
                $images[] = $image;
            }
            $instructions = "If this is your item, visit {$o['name']}, {$o['location']} ({$o['hours']}). "
                . "Bring a valid ID, your reference code {$lost['reference']}, and any proof of ownership (photos, receipts, or a description of unique marks or contents). "
                . 'You can also submit a claim online from the community board by choosing "Claim this item" on the found item. Staff will verify ownership before releasing it.';
            $link = appUrl('#items');
            $linkLabel = 'Open the community board';
            break;

        case 'claim_received':
            [$c, $r] = [$d['claim'], $d['report']];
            $subject = "We received your claim for: {$r['title']} [{$c['reference']}]";
            $heading = 'Your claim is pending review';
            $intro = "Hi {$c['claimant_name']}, thank you for submitting a claim on " . emailDate(null, true) . '. Your claim reference is ' . $c['reference'] . '.';
            $rows = itemRows($r, false) + ['Claim status' => CLAIM_LABELS['pending'], 'Claim reference' => $c['reference']];
            unset($rows['Reference']);
            $instructions = 'Campus Security will compare the proof you provided with the item. You will receive another email when your claim is approved or rejected. Please do not submit the same claim again.';
            break;

        case 'claim_approved':
        case 'claim_rejected':
            [$c, $r] = [$d['claim'], $d['report']];
            $approved = $template === 'claim_approved';
            $subject = ($approved ? 'Your claim was approved: ' : 'Update on your claim: ') . $r['title'] . " [{$c['reference']}]";
            $heading = $approved ? 'Your claim has been approved' : 'Your claim was not approved';
            $intro = "Hi {$c['claimant_name']}, your claim for \"{$r['title']}\" was reviewed on " . emailDate(null, true) . '.';
            $rows = itemRows($r, false) + ['Claim status' => CLAIM_LABELS[$approved ? 'approved' : 'rejected'], 'Claim reference' => $c['reference']];
            unset($rows['Reference']);
            $note = trim((string) ($d['note'] ?? '')) !== '' ? (string) $d['note'] : null;
            $instructions = $approved
                ? "Please collect your item at {$o['name']}, {$o['location']} ({$o['hours']}). Bring a valid ID and your claim reference {$c['reference']}. Items not collected within 30 days may be released."
                : "Staff could not verify that this item belongs to you based on the information provided. If you believe this is a mistake, visit {$o['name']}, {$o['location']} ({$o['hours']}) with additional proof of ownership and your claim reference {$c['reference']}.";
            break;

        case 'staff_assigned':
            [$m, $staff] = [$d['match'], $d['staff']];
            $subject = "You have been assigned a match: {$m['lostTitle']} ↔ {$m['foundTitle']}";
            $heading = 'New match assignment';
            $intro = "Hi {$staff['name']}, {$d['assignedBy']} assigned you to follow up on a possible match on " . emailDate(null, true) . '.';
            $rows = [
                'Lost item' => "{$m['lostTitle']} ({$m['lostReference']})",
                'Found item' => "{$m['foundTitle']} ({$m['foundReference']})",
                'Match status' => ucfirst($m['status']),
            ];
            $instructions = 'Sign in to the staff dashboard and open Matches → "Assigned to me" to review the items, contact the owner and record your progress.';
            $link = appUrl('#/staff');
            $linkLabel = 'Open the staff dashboard';
            break;

        case 'test':
            $subject = 'Test email from Campus Lost & Found';
            $heading = 'Email is working';
            $intro = 'This test message was sent on ' . emailDate(null, true) . ' by ' . $d['by'] . ' to confirm that the email settings are correct.';
            $rows = ['Sent from' => mailSettings()['from_email']];
            $instructions = 'No action is needed.';
            break;

        default:
            throw new InvalidArgumentException("Unknown email template {$template}");
    }

    $footer = "{$o['name']} · {$o['location']} · {$o['hours']}\nThis is an automated message from Campus Lost & Found.";

    // Plain text version.
    $text = $heading . "\n\n" . $intro . "\n\n";
    foreach ($rows as $label => $value) {
        $text .= str_pad($label . ':', 16) . str_replace("\n", "\n" . str_repeat(' ', 16), (string) $value) . "\n";
    }
    if ($note !== null) {
        $text .= "\nNote from staff: {$note}\n";
    }
    $text .= "\nWhat to do next:\n{$instructions}\n";
    if ($link) {
        $text .= "\n{$linkLabel}: {$link}\n";
    }
    $text .= "\n--\n{$footer}\n";

    // HTML version (inline styles for email clients).
    $e = static fn ($v): string => nl2br(htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= '<tr><td style="padding:8px 12px 8px 0;color:#5b6962;font-size:13px;font-weight:bold;vertical-align:top;white-space:nowrap">'
            . $e($label) . '</td><td style="padding:8px 0;color:#18352e;font-size:14px;vertical-align:top">' . $e($value) . '</td></tr>';
    }
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f5f4ef;font-family:Segoe UI,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4ef;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fffefa;border:1px solid #e2e2d9;border-radius:10px">'
        . '<tr><td style="background:#174a38;color:#f4f0dc;padding:16px 24px;border-radius:10px 10px 0 0;font-size:15px;font-weight:bold;letter-spacing:1px">CAMPUS LOST &amp; FOUND</td></tr>'
        . '<tr><td style="padding:24px">'
        . '<h1 style="margin:0 0 10px;color:#18352e;font-size:22px">' . $e($heading) . '</h1>'
        . '<p style="margin:0 0 18px;color:#46574f;font-size:15px;line-height:1.6">' . $e($intro) . '</p>'
        . ($image ? '<p style="margin:0 0 16px"><img src="cid:' . $image['cid'] . '" alt="Photo of the found item" style="max-width:100%;max-height:320px;border-radius:8px;border:1px solid #e2e2d9"></p>' : '')
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-top:1px solid #e2e2d9;border-bottom:1px solid #e2e2d9;margin-bottom:18px">' . $rowsHtml . '</table>'
        . ($note !== null ? '<p style="margin:0 0 16px;padding:12px;background:#f5ebd8;border-radius:6px;color:#5c4320;font-size:14px"><strong>Note from staff:</strong> ' . $e($note) . '</p>' : '')
        . '<h2 style="margin:0 0 6px;color:#174a38;font-size:15px">What to do next</h2>'
        . '<p style="margin:0 0 18px;color:#46574f;font-size:14px;line-height:1.6">' . $e($instructions) . '</p>'
        . ($link ? '<p style="margin:0 0 6px"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#174a38;color:#ffffff;padding:11px 18px;border-radius:6px;font-size:14px;font-weight:bold;text-decoration:none">' . $e($linkLabel) . '</a></p>' : '')
        . '</td></tr>'
        . '<tr><td style="padding:14px 24px;border-top:1px solid #e2e2d9;color:#5b6962;font-size:12px;line-height:1.6">' . $e($footer) . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    return [str_replace(["\r", "\n"], ' ', $subject), $text, $html, $images];
}

/* ------------------------------------------------------------------------- */
/* Report status changes                                                       */
/* ------------------------------------------------------------------------- */

function loadReportRow(string $id, bool $lock = false): ?array
{
    $stmt = db()->prepare(
        "SELECT id, reference, report_type AS type, category, title, location,
                DATE_FORMAT(report_date, '%Y-%m-%d') AS date, description, contact, status, photo, photo_mime
         FROM reports WHERE id = ?" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Changes a report's status, records the history and emails the reporter.
 * Does nothing (and sends nothing) when the status is already the requested one.
 *
 * @return array{found: bool, changed: bool, email: array}
 */
function changeReportStatus(string $id, string $status, ?array $admin): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $report = loadReportRow($id, true);
        if (!$report) {
            $pdo->rollBack();
            return ['found' => false, 'changed' => false, 'email' => ['status' => 'skipped', 'message' => '']];
        }
        if ($report['status'] === $status) {
            $pdo->rollBack();
            return ['found' => true, 'changed' => false,
                'email' => ['status' => 'unchanged', 'message' => 'The status was already "' . statusLabel($status) . '", so no email was sent.']];
        }
        $pdo->prepare('UPDATE reports SET status = ?, status_changed_at = NOW() WHERE id = ?')->execute([$status, $id]);
        $pdo->prepare('INSERT INTO report_status_history (report_id, from_status, to_status, changed_by) VALUES (?, ?, ?, ?)')
            ->execute([$id, $report['status'], $status, $admin['id'] ?? null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $old = $report['status'];
    $report['status'] = $status;
    $email = sendEmail('status_changed', $report['contact'], ['report' => $report, 'oldStatus' => $old],
        ['report_id' => $id, 'item_title' => $report['title']], $admin['id'] ?? null);
    return ['found' => true, 'changed' => true, 'email' => $email];
}
