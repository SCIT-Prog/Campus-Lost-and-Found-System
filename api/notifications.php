<?php
declare(strict_types=1);

// Email notifications (staff only). SMTP usernames and passwords are never returned.
//   GET                                   -> notification history and a safe summary of the mail setup
//   GET  ?id=...                          -> one notification including its full message text
//   POST {action: "match", lostId, foundId} -> email a lost item's owner that a matching item was found
//   POST {action: "resend", id}           -> send a recorded email again (e.g. after a failure)
//   POST {action: "test", to}             -> send a test email (administrators only)
define('LF_APP', true);
require __DIR__ . '/bootstrap.php';

const NOTIFICATION_COLUMNS = "n.id, n.template, n.recipient, n.subject, n.status, n.error, n.item_title AS itemTitle,
        n.report_id AS reportId, n.claim_id AS claimId, n.match_id AS matchId,
        COALESCE(NULLIF(a.full_name, ''), a.username) AS triggeredBy,
        DATE_FORMAT(n.created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt";

function presentNotification(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['templateLabel'] = EMAIL_TEMPLATES[$row['template']] ?? $row['template'];
    $row['triggeredBy'] = $row['triggeredBy'] ?? 'System (automatic)';
    return $row;
}

try {
    $method = requireMethod(['GET', 'POST']);
    $me = requireAdmin($method !== 'GET');

    if ($method === 'GET' && isset($_GET['id'])) {
        $stmt = db()->prepare('SELECT ' . NOTIFICATION_COLUMNS . ', n.body_text AS body FROM email_notifications n LEFT JOIN admins a ON a.id = n.triggered_by WHERE n.id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            fail(404, 'Notification not found.');
        }
        respond(200, ['notification' => presentNotification($row)]);
    }

    if ($method === 'GET') {
        $rows = db()->query('SELECT ' . NOTIFICATION_COLUMNS . ' FROM email_notifications n LEFT JOIN admins a ON a.id = n.triggered_by ORDER BY n.created_at DESC, n.id DESC LIMIT 1000')->fetchAll();
        $counts = ['sent' => 0, 'failed' => 0];
        foreach (db()->query('SELECT status, COUNT(*) AS n FROM email_notifications GROUP BY status')->fetchAll() as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        respond(200, [
            'notifications' => array_map('presentNotification', $rows),
            'counts' => $counts,
            'templates' => EMAIL_TEMPLATES,
            'mail' => mailStatus(),
        ]);
    }

    $input = readJson();
    $action = (string) ($input['action'] ?? '');

    if ($action === 'match') {
        $lostId = (string) ($input['lostId'] ?? '');
        $foundId = (string) ($input['foundId'] ?? '');
        $lost = isValidId($lostId) ? loadReportRow($lostId) : null;
        $found = isValidId($foundId) ? loadReportRow($foundId) : null;
        if (!$lost || $lost['type'] !== 'lost') {
            fail(422, 'Choose the lost item whose owner should be emailed.');
        }
        if (!$found || $found['type'] !== 'found') {
            fail(422, 'Choose the found item that matches.');
        }
        if (!$lost['contact']) {
            fail(422, 'This lost item report has no email address, so no email can be sent.');
        }

        // Link the email to a match record, creating one if the pair is not matched yet.
        $stmt = db()->prepare('SELECT id FROM matches WHERE lost_report_id = ? AND found_report_id = ?');
        $stmt->execute([$lostId, $foundId]);
        $matchId = $stmt->fetchColumn();
        $created = false;
        if (!$matchId) {
            db()->prepare('INSERT INTO matches (lost_report_id, found_report_id, notes, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$lostId, $foundId, 'Created when the owner was emailed.', $me['id']]);
            $matchId = db()->lastInsertId();
            $created = true;
        }

        $result = sendEmail('match_found', $lost['contact'], ['lost' => $lost, 'found' => $found],
            ['report_id' => $lostId, 'match_id' => (int) $matchId, 'item_title' => $lost['title']], $me['id']);
        respond($result['status'] === 'sent' ? 200 : 502, [
            'email' => $result,
            'matchId' => (int) $matchId,
            'matchCreated' => $created,
            'error' => $result['status'] === 'sent' ? null : $result['message'],
        ]);
    }

    if ($action === 'resend') {
        $stmt = db()->prepare('SELECT * FROM email_notifications WHERE id = ?');
        $stmt->execute([(int) ($input['id'] ?? 0)]);
        $row = $stmt->fetch();
        if (!$row) {
            fail(404, 'Notification not found.');
        }
        $result = deliverAndLog($row['template'], $row['recipient'], $row['subject'], $row['body_text'], null, [], [
            'report_id' => $row['report_id'], 'claim_id' => $row['claim_id'] !== null ? (int) $row['claim_id'] : null,
            'match_id' => $row['match_id'] !== null ? (int) $row['match_id'] : null, 'item_title' => $row['item_title'],
        ], $me['id']);
        respond($result['status'] === 'sent' ? 200 : 502, ['email' => $result, 'error' => $result['status'] === 'sent' ? null : $result['message']]);
    }

    if ($action === 'test') {
        if ($me['role'] !== 'admin') {
            fail(403, 'Only administrators can send test emails.', ['code' => 'forbidden']);
        }
        $to = cleanText($input['to'] ?? '');
        if (!isValidEmail($to)) {
            fail(422, 'Enter a valid email address.');
        }
        $result = sendEmail('test', $to, ['by' => $me['name']], [], $me['id']);
        respond($result['status'] === 'sent' ? 200 : 502, ['email' => $result, 'error' => $result['status'] === 'sent' ? null : $result['message']]);
    }

    fail(400, 'Unknown action.');
} catch (Throwable $error) {
    error_log('[lost-found] ' . $error);
    fail(500, 'Something went wrong on the server. Please try again in a moment.');
}
