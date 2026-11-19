<?php
declare(strict_types=1);

// Public API: browse published reports, submit new ones, and look up your own report.
//   GET                -> published reports (no contact emails, no reference codes)
//   GET  ?ref=LF-XXXX  -> one report by its reference code (the code is the reporter's key)
//   POST               -> submit a report (JSON or multipart/form-data with an optional "photo")
define('LF_APP', true);
require __DIR__ . '/bootstrap.php';

/** The details a reporter sees about their own report. Never includes the contact email. */
function referencePayload(string $id): ?array
{
    $stmt = db()->prepare('SELECT ' . REPORT_COLUMNS . ", reference,
            DATE_FORMAT(status_changed_at, '%Y-%m-%dT%H:%i:%sZ') AS statusChangedAt
         FROM reports WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? presentReport($row, $row['reference']) : null;
}

try {
    $method = requireMethod(['GET', 'POST']);
    $c = config();
    $ip = clientIp();

    if ($method === 'GET' && isset($_GET['ref'])) {
        $ref = normaliseReference((string) $_GET['ref']);
        if (rateCount('lookup', $ip, 3600) >= (int) ($c['lookup_limit'] ?? 30)) {
            tooManyRequests('Too many look-ups. Please try again later.', 3600);
        }
        if (!preg_match('/^LF-[A-Z0-9]{8}$/', $ref)) {
            fail(422, 'Enter a reference code like LF-7KQ2M9XA.');
        }
        $stmt = db()->prepare('SELECT id FROM reports WHERE reference = ?');
        $stmt->execute([$ref]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            rateHit('lookup', $ip);
            fail(404, 'No report was found with that reference code. Check it and try again.');
        }
        respond(200, ['report' => referencePayload((string) $id)]);
    }

    if ($method === 'GET') {
        $rows = db()->query(
            'SELECT ' . REPORT_COLUMNS . " FROM reports WHERE status = 'active' ORDER BY created_at DESC LIMIT 500"
        )->fetchAll();
        $counts = stats();
        header('Cache-Control: no-cache');
        respond(200, [
            'reports' => array_map(static fn ($row) => presentReport($row), $rows),
            'stats' => ['active' => $counts['active'], 'resolved' => $counts['resolved']],
        ]);
    }

    $input = readInput();

    // Hidden "website" field: people never see it, so anything in it is a bot.
    // Pretend it worked so the bot learns nothing.
    if (trim((string) ($input['website'] ?? '')) !== '') {
        respond(201, ['ok' => true]);
    }

    [$clean, $errors] = validateReport($input);
    if ($errors) {
        fail(422, 'Please fix the highlighted fields.', ['fields' => $errors]);
    }

    if (rateCount('submit', $ip, $c['submit_window']) >= $c['submit_limit']) {
        tooManyRequests('You have sent several reports recently. Please try again later.', $c['submit_window']);
    }

    [$photo, $photoMime, $photoError] = storeUploadedImage('photo', 'reports');
    if ($photoError !== null) {
        fail(422, 'Please fix the highlighted fields.', ['fields' => ['photo' => $photoError]]);
    }

    $id = newUuid();
    $reference = newReference('LF', 'reports');
    try {
        db()->prepare(
            'INSERT INTO reports (id, reference, report_type, category, title, location, report_date, description, contact, photo, photo_mime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $reference, $clean['type'], $clean['category'], $clean['title'], $clean['location'],
            $clean['date'], $clean['description'], $clean['contact'] !== '' ? $clean['contact'] : null, $photo, $photoMime,
        ]);
    } catch (Throwable $e) {
        deleteStoredFile('reports', $photo);
        throw $e;
    }
    rateHit('submit', $ip);

    $email = ['status' => 'skipped', 'message' => ''];
    if ($clean['contact'] !== '') {
        $email = sendEmail('report_received', $clean['contact'], ['report' => loadReportRow($id)],
            ['report_id' => $id, 'item_title' => $clean['title']]);
    }

    respond(201, [
        'ok' => true,
        'report' => referencePayload($id),
        // Only whether it worked; failure details are for staff.
        'emailSent' => $email['status'] === 'sent',
    ]);
} catch (Throwable $error) {
    error_log('[lost-found] ' . $error);
    fail(500, 'Something went wrong on the server. Please try again in a moment.');
}
