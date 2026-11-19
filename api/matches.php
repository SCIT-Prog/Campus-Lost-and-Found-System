<?php
declare(strict_types=1);

// Matches between a lost report and a found report, with staff assignment.
//   GET            -> matches, suggested pairs and the list of staff (any staff)
//   POST           -> {lostId, foundId, notes?} create a match (any staff)
//   PATCH  ?id=... -> {assignedStaffId?} (admins), {assignmentStatus?, status?, notes?}
//                     (admins, the assigned staff member, or anyone while unassigned)
//   DELETE ?id=... -> remove a match (admins)
define('LF_APP', true);
require __DIR__ . '/bootstrap.php';

const MATCH_STATUSES = ['open', 'confirmed', 'dismissed'];
const ASSIGNMENT_STATUSES = ['unassigned', 'assigned', 'in_progress', 'completed'];

function matchSelect(): string
{
    $side = static fn (string $a, string $p): string => "
        {$a}.id AS {$p}_id, {$a}.reference AS {$p}_reference, {$a}.title AS {$p}_title, {$a}.category AS {$p}_category,
        {$a}.location AS {$p}_location, DATE_FORMAT({$a}.report_date, '%Y-%m-%d') AS {$p}_date, {$a}.description AS {$p}_description,
        {$a}.status AS {$p}_status, {$a}.contact AS {$p}_contact, ({$a}.photo IS NOT NULL) AS {$p}_hasPhoto";
    return "SELECT m.id, m.status, m.assignment_status AS assignmentStatus, m.assigned_staff_id AS assignedStaffId,
            COALESCE(NULLIF(s.full_name, ''), s.username) AS assignedStaffName,
            COALESCE(NULLIF(ab.full_name, ''), ab.username) AS assignedByName,
            DATE_FORMAT(m.assigned_at, '%Y-%m-%dT%H:%i:%sZ') AS assignedAt, m.notes,
            COALESCE(NULLIF(cb.full_name, ''), cb.username) AS createdByName,
            DATE_FORMAT(m.created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            DATE_FORMAT(m.updated_at, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt,
            (SELECT COUNT(*) FROM email_notifications e WHERE e.match_id = m.id AND e.template = 'match_found' AND e.status = 'sent') AS emailsSent,
            " . $side('l', 'lost') . ',' . $side('f', 'found') . "
        FROM matches m
        JOIN reports l ON l.id = m.lost_report_id
        JOIN reports f ON f.id = m.found_report_id
        LEFT JOIN admins s ON s.id = m.assigned_staff_id
        LEFT JOIN admins ab ON ab.id = m.assigned_by
        LEFT JOIN admins cb ON cb.id = m.created_by";
}

function presentMatch(array $row): array
{
    $out = [];
    foreach (['lost', 'found'] as $p) {
        $side = [];
        foreach ($row as $key => $value) {
            if (str_starts_with($key, $p . '_')) {
                $side[substr($key, strlen($p) + 1)] = $value;
                unset($row[$key]);
            }
        }
        $side['hasPhoto'] = (bool) $side['hasPhoto'];
        $side['statusLabel'] = statusLabel($side['status']);
        $side['photoUrl'] = $side['hasPhoto'] ? 'api/image.php?report=' . rawurlencode($side['id']) : null;
        $out[$p] = $side;
    }
    $row['assignedStaffId'] = $row['assignedStaffId'] !== null ? (int) $row['assignedStaffId'] : null;
    $row['emailsSent'] = (int) $row['emailsSent'];
    $row['id'] = (int) $row['id'];
    return $row + $out;
}

function findMatch(int $id): ?array
{
    $stmt = db()->prepare(matchSelect() . ' WHERE m.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? presentMatch($row) : null;
}

function staffList(): array
{
    $rows = db()->query(
        "SELECT id, username, COALESCE(NULLIF(full_name, ''), username) AS name, role, (email IS NOT NULL AND email <> '') AS hasEmail
         FROM admins WHERE is_active = 1 ORDER BY name"
    )->fetchAll();
    return array_map(static fn ($r) => ['id' => (int) $r['id'], 'username' => $r['username'], 'name' => $r['name'], 'role' => $r['role'], 'hasEmail' => (bool) $r['hasEmail']], $rows);
}

function words(string $text): array
{
    static $stop = ['the', 'and', 'with', 'for', 'near', 'from', 'that', 'this', 'has', 'have', 'was', 'were', 'its', 'floor', 'room', 'one', 'some', 'color', 'colour'];
    preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);
    return array_values(array_diff(array_unique($m[0]), $stop));
}

/** Pairs of open lost and found reports that look alike and are not matched yet. */
function suggestions(): array
{
    $cols = "id, reference, title, category, location, DATE_FORMAT(report_date, '%Y-%m-%d') AS date, description, status, (photo IS NOT NULL) AS hasPhoto";
    $lost = db()->query("SELECT {$cols} FROM reports WHERE report_type = 'lost' AND status IN ('pending', 'active') ORDER BY created_at DESC LIMIT 300")->fetchAll();
    $found = db()->query("SELECT {$cols} FROM reports WHERE report_type = 'found' AND status IN ('pending', 'active') ORDER BY created_at DESC LIMIT 300")->fetchAll();
    $existing = [];
    foreach (db()->query('SELECT lost_report_id, found_report_id FROM matches')->fetchAll() as $pair) {
        $existing[$pair['lost_report_id'] . '|' . $pair['found_report_id']] = true;
    }
    $prep = static function (array $r): array {
        $r['words'] = words($r['title'] . ' ' . $r['description']);
        $r['places'] = words($r['location']);
        $r['hasPhoto'] = (bool) $r['hasPhoto'];
        $r['photoUrl'] = $r['hasPhoto'] ? 'api/image.php?report=' . rawurlencode($r['id']) : null;
        $r['statusLabel'] = statusLabel($r['status']);
        return $r;
    };
    $lost = array_map($prep, $lost);
    $found = array_map($prep, $found);

    $out = [];
    foreach ($lost as $l) {
        foreach ($found as $f) {
            if (isset($existing[$l['id'] . '|' . $f['id']])) {
                continue;
            }
            $shared = array_intersect($l['words'], $f['words']);
            $places = array_intersect($l['places'], $f['places']);
            $score = count($shared) * 10 + count($places) * 4;
            if ($l['category'] === $f['category']) {
                $score += 8;
            }
            $days = (strtotime($f['date']) - strtotime($l['date'])) / 86400;
            if ($days >= -1 && $days <= 14) {
                $score += 5;
            } elseif ($days < -1) {
                $score -= 6; // found before it was lost
            }
            if ($score >= 18 && ($shared || $places)) {
                $strip = static fn ($r) => array_diff_key($r, ['words' => 1, 'places' => 1]);
                $reasons = [];
                if ($l['category'] === $f['category']) {
                    $reasons[] = 'Same category';
                }
                if ($shared) {
                    $reasons[] = 'Shared words: ' . implode(', ', array_slice(array_values($shared), 0, 4));
                }
                if ($places) {
                    $reasons[] = 'Similar location';
                }
                $out[] = ['score' => $score, 'reasons' => $reasons, 'lost' => $strip($l), 'found' => $strip($f)];
            }
        }
    }
    usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, 25);
}

function canManage(array $me, array $match): bool
{
    return $me['role'] === 'admin' || $match['assignedStaffId'] === null || $match['assignedStaffId'] === $me['id'];
}

try {
    $method = requireMethod(['GET', 'POST', 'PATCH', 'DELETE']);
    $me = requireAdmin($method !== 'GET', $method === 'DELETE' ? 'admin' : null);

    if ($method === 'GET') {
        $rows = db()->query(matchSelect() . ' ORDER BY m.updated_at DESC LIMIT 500')->fetchAll();
        respond(200, [
            'matches' => array_map('presentMatch', $rows),
            'suggestions' => suggestions(),
            'staff' => staffList(),
            'stats' => staffStats(),
        ]);
    }

    if ($method === 'POST') {
        $input = readJson();
        $lostId = (string) ($input['lostId'] ?? '');
        $foundId = (string) ($input['foundId'] ?? '');
        $notes = mb_substr(cleanText($input['notes'] ?? ''), 0, 500);
        if (!isValidId($lostId) || !isValidId($foundId)) {
            fail(422, 'Choose a lost item and a found item.');
        }
        $lost = loadReportRow($lostId);
        $found = loadReportRow($foundId);
        if (!$lost || $lost['type'] !== 'lost' || !$found || $found['type'] !== 'found') {
            fail(422, 'A match needs one lost report and one found report.');
        }
        $stmt = db()->prepare('SELECT id FROM matches WHERE lost_report_id = ? AND found_report_id = ?');
        $stmt->execute([$lostId, $foundId]);
        if ($existing = $stmt->fetchColumn()) {
            fail(409, 'These two items are already matched.', ['match' => findMatch((int) $existing)]);
        }
        db()->prepare('INSERT INTO matches (lost_report_id, found_report_id, notes, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$lostId, $foundId, $notes, $me['id']]);
        respond(201, ['match' => findMatch((int) db()->lastInsertId()), 'stats' => staffStats()]);
    }

    $id = (int) ($_GET['id'] ?? 0);
    $match = $id > 0 ? findMatch($id) : null;
    if (!$match) {
        fail(404, 'Match not found.');
    }

    if ($method === 'DELETE') {
        db()->prepare('DELETE FROM matches WHERE id = ?')->execute([$id]);
        respond(200, ['deleted' => true, 'stats' => staffStats()]);
    }

    // PATCH
    $input = readJson();
    $sets = [];
    $params = [];
    $email = null;

    if (array_key_exists('assignedStaffId', $input)) {
        if ($me['role'] !== 'admin') {
            fail(403, 'Only administrators can assign staff to matches.', ['code' => 'forbidden']);
        }
        $staffId = $input['assignedStaffId'];
        $staff = null;
        if ($staffId !== null && $staffId !== '') {
            $staffId = filter_var($staffId, FILTER_VALIDATE_INT);
            $stmt = db()->prepare('SELECT id, username, full_name, email, role, is_active FROM admins WHERE id = ?');
            $stmt->execute([(int) $staffId]);
            $staff = $stmt->fetch();
            if (!$staff || !(int) $staff['is_active']) {
                fail(422, 'Choose an active staff member.');
            }
        }
        $newId = $staff ? (int) $staff['id'] : null;
        if ($newId !== $match['assignedStaffId']) {
            $sets[] = 'assigned_staff_id = ?';
            $params[] = $newId;
            $sets[] = 'assignment_status = ?';
            $params[] = $newId ? 'assigned' : 'unassigned';
            $sets[] = 'assigned_by = ?';
            $params[] = $newId ? $me['id'] : null;
            $sets[] = 'assigned_at = ' . ($newId ? 'NOW()' : 'NULL');
            $match['assignedStaffId'] = $newId;
            $match['assignmentStatus'] = $newId ? 'assigned' : 'unassigned';
        }
        if ($staff && $newId !== $me['id'] && !empty($staff['email']) && $sets) {
            $email = ['staff' => staffSummary($staff), 'to' => $staff['email']];
        }
    }

    $wantsManage = array_intersect(['assignmentStatus', 'status', 'notes'], array_keys($input));
    if ($wantsManage && !canManage($me, $match)) {
        fail(403, 'Only the assigned staff member or an administrator can update this match.', ['code' => 'forbidden']);
    }
    if (array_key_exists('assignmentStatus', $input)) {
        $value = $input['assignmentStatus'];
        if (!in_array($value, ['assigned', 'in_progress', 'completed'], true)) {
            fail(422, 'Choose a valid assignment status.');
        }
        if ($match['assignedStaffId'] === null) {
            fail(422, 'Assign a staff member before updating the assignment status.');
        }
        $sets[] = 'assignment_status = ?';
        $params[] = $value;
    }
    if (array_key_exists('status', $input)) {
        if (!in_array($input['status'], MATCH_STATUSES, true)) {
            fail(422, 'Choose a valid match status.');
        }
        $sets[] = 'status = ?';
        $params[] = $input['status'];
    }
    if (array_key_exists('notes', $input)) {
        $notes = cleanText($input['notes']);
        if (mb_strlen($notes) > 500) {
            fail(422, 'Keep notes to 500 characters or fewer.');
        }
        $sets[] = 'notes = ?';
        $params[] = $notes;
    }
    if (!$sets) {
        respond(200, ['match' => $match, 'changed' => false, 'stats' => staffStats()]);
    }

    $params[] = $id;
    db()->prepare('UPDATE matches SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    $updated = findMatch($id);

    $emailResult = null;
    if ($email) {
        $emailResult = sendEmail('staff_assigned', $email['to'], [
            'match' => [
                'lostTitle' => $updated['lost']['title'], 'lostReference' => $updated['lost']['reference'],
                'foundTitle' => $updated['found']['title'], 'foundReference' => $updated['found']['reference'],
                'status' => $updated['status'],
            ],
            'staff' => $email['staff'],
            'assignedBy' => $me['name'],
        ], ['match_id' => $id, 'report_id' => $updated['lost']['id'], 'item_title' => $updated['lost']['title']], $me['id']);
    }
    respond(200, ['match' => $updated, 'changed' => true, 'email' => $emailResult, 'stats' => staffStats()]);
} catch (Throwable $error) {
    error_log('[lost-found] ' . $error);
    fail(500, 'Something went wrong on the server. Please try again in a moment.');
}
