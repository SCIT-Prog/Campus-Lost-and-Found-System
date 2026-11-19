<?php
declare(strict_types=1);

// Staff-only API. Every request needs a signed-in session; changes also need the CSRF token.
//   GET            -> all reports, including contact emails and reference codes
//   GET    ?id=... -> one report with its status history, emails, claims and matches
//   PATCH  ?id=... -> {status: pending|active|resolved}; emails the reporter when it changes
//   DELETE ?id=... -> remove a report (administrators only)
define('LF_APP', true);
require __DIR__ . '/bootstrap.php';

function findAdminReport(string $id): ?array
{
    $stmt = db()->prepare('SELECT ' . ADMIN_REPORT_COLUMNS . ' FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? presentReport($row) : null;
}

try {
    $method = requireMethod(['GET', 'PATCH', 'DELETE']);
    $me = requireAdmin($method !== 'GET', $method === 'DELETE' ? 'admin' : null);

    if ($method === 'GET' && !isset($_GET['id'])) {
        $rows = db()->query(
            'SELECT ' . ADMIN_REPORT_COLUMNS . ',
                (SELECT COUNT(*) FROM claims c WHERE c.report_id = reports.id AND c.status = \'pending\') AS pendingClaims,
                (SELECT COUNT(*) FROM matches m WHERE (m.lost_report_id = reports.id OR m.found_report_id = reports.id) AND m.status <> \'dismissed\') AS matchCount
             FROM reports ORDER BY created_at DESC LIMIT 1000'
        )->fetchAll();
        $rows = array_map(static function ($row) {
            $row = presentReport($row);
            $row['pendingClaims'] = (int) $row['pendingClaims'];
            $row['matchCount'] = (int) $row['matchCount'];
            return $row;
        }, $rows);
        respond(200, ['reports' => $rows, 'stats' => staffStats(), 'me' => $me]);
    }

    $id = trim((string) ($_GET['id'] ?? ''));
    if (!isValidId($id)) {
        fail(400, 'A valid report ID is required.');
    }

    if ($method === 'GET') {
        $report = findAdminReport($id);
        if (!$report) {
            fail(404, 'Report not found.');
        }
        $history = db()->prepare(
            "SELECT h.from_status AS fromStatus, h.to_status AS toStatus, COALESCE(NULLIF(a.full_name, ''), a.username) AS changedBy,
                    DATE_FORMAT(h.changed_at, '%Y-%m-%dT%H:%i:%sZ') AS changedAt
             FROM report_status_history h LEFT JOIN admins a ON a.id = h.changed_by
             WHERE h.report_id = ? ORDER BY h.changed_at DESC, h.id DESC"
        );
        $history->execute([$id]);
        $emails = db()->prepare(
            "SELECT id, template, recipient, subject, status, error, DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt
             FROM email_notifications WHERE report_id = ? ORDER BY created_at DESC, id DESC LIMIT 50"
        );
        $emails->execute([$id]);
        $claims = db()->prepare(
            "SELECT id, reference, claimant_name AS claimantName, status, DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt
             FROM claims WHERE report_id = ? ORDER BY created_at DESC"
        );
        $claims->execute([$id]);
        respond(200, [
            'report' => $report,
            'history' => $history->fetchAll(),
            'emails' => $emails->fetchAll(),
            'claims' => $claims->fetchAll(),
        ]);
    }

    if ($method === 'PATCH') {
        $input = readJson();
        $status = $input['status'] ?? '';
        if (!is_string($status) || !isset(STATUS_LABELS[$status])) {
            fail(422, 'Choose a valid report status.');
        }
        $result = changeReportStatus($id, $status, $me);
        if (!$result['found']) {
            fail(404, 'Report not found.');
        }
        respond(200, [
            'report' => findAdminReport($id),
            'changed' => $result['changed'],
            'email' => $result['email'],
            'stats' => staffStats(),
        ]);
    }

    // DELETE (administrators only): remove the stored photos too.
    $report = db()->prepare('SELECT photo FROM reports WHERE id = ?');
    $report->execute([$id]);
    $photo = $report->fetchColumn();
    if ($photo === false) {
        fail(404, 'Report not found.');
    }
    $proofs = db()->prepare('SELECT proof_photo FROM claims WHERE report_id = ? AND proof_photo IS NOT NULL');
    $proofs->execute([$id]);
    $proofFiles = $proofs->fetchAll(PDO::FETCH_COLUMN);

    db()->prepare('DELETE FROM reports WHERE id = ?')->execute([$id]);
    deleteStoredFile('reports', $photo ?: null);
    foreach ($proofFiles as $file) {
        deleteStoredFile('claims', $file);
    }
    respond(200, ['deleted' => true, 'stats' => staffStats()]);
} catch (Throwable $error) {
    error_log('[lost-found] ' . $error);
    fail(500, 'Something went wrong on the server. Please try again in a moment.');
}
