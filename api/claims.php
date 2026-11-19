<?php
declare(strict_types=1);

// Ownership claims for found items.
//   POST           -> public: submit a claim (JSON or multipart with an optional "photo"); starts as Pending
//   GET            -> staff: all claims with item details and status history
//   PATCH  ?id=... -> staff: {status: approved|rejected|pending, note?, markFound?}; emails the claimant
define('LF_APP', true);
require __DIR__ . '/bootstrap.php';

const CLAIM_LIMITS = ['name' => 80, 'phone' => 30, 'proof' => 1000, 'note' => 500];

function claimSelect(): string
{
    return "SELECT c.id, c.reference, c.report_id AS reportId, c.claimant_name AS claimantName, c.claimant_email AS claimantEmail,
            c.claimant_phone AS claimantPhone, c.proof, (c.proof_photo IS NOT NULL) AS hasProofPhoto, c.status, c.review_note AS reviewNote,
            COALESCE(NULLIF(a.full_name, ''), a.username) AS reviewedBy,
            DATE_FORMAT(c.reviewed_at, '%Y-%m-%dT%H:%i:%sZ') AS reviewedAt,
            DATE_FORMAT(c.created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            r.reference AS itemReference, r.title AS itemTitle, r.report_type AS itemType, r.category AS itemCategory,
            r.location AS itemLocation, DATE_FORMAT(r.report_date, '%Y-%m-%d') AS itemDate, r.description AS itemDescription,
            r.status AS itemStatus, (r.photo IS NOT NULL) AS itemHasPhoto
        FROM claims c
        JOIN reports r ON r.id = c.report_id
        LEFT JOIN admins a ON a.id = c.reviewed_by";
}

function presentClaim(array $row, array $history): array
{
    $row['id'] = (int) $row['id'];
    $row['hasProofPhoto'] = (bool) $row['hasProofPhoto'];
    $row['proofPhotoUrl'] = $row['hasProofPhoto'] ? 'api/image.php?claim=' . $row['id'] : null;
    $row['itemHasPhoto'] = (bool) $row['itemHasPhoto'];
    $row['itemPhotoUrl'] = $row['itemHasPhoto'] ? 'api/image.php?report=' . rawurlencode($row['reportId']) : null;
    $row['itemStatusLabel'] = statusLabel($row['itemStatus']);
    $row['statusLabel'] = CLAIM_LABELS[$row['status']] ?? $row['status'];
    $row['history'] = $history;
    return $row;
}

function claimHistory(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT h.claim_id, h.from_status AS fromStatus, h.to_status AS toStatus, h.note,
                COALESCE(NULLIF(a.full_name, ''), a.username) AS changedBy,
                DATE_FORMAT(h.changed_at, '%Y-%m-%dT%H:%i:%sZ') AS changedAt
         FROM claim_status_history h LEFT JOIN admins a ON a.id = h.changed_by
         WHERE h.claim_id IN ({$marks}) ORDER BY h.changed_at ASC, h.id ASC"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $h) {
        $cid = (int) $h['claim_id'];
        unset($h['claim_id']);
        $out[$cid][] = $h;
    }
    return $out;
}

function findClaim(int $id): ?array
{
    $stmt = db()->prepare(claimSelect() . ' WHERE c.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? presentClaim($row, claimHistory([$id])[$id] ?? []) : null;
}

function claimCounts(): array
{
    $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach (db()->query('SELECT status, COUNT(*) AS n FROM claims GROUP BY status')->fetchAll() as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}

try {
    $method = requireMethod(['GET', 'POST', 'PATCH']);

    /* ---------------- Public: submit a claim ---------------- */
    if ($method === 'POST') {
        $input = readInput();
        if (trim((string) ($input['website'] ?? '')) !== '') {
            respond(201, ['ok' => true]);
        }
        $reportId = cleanText($input['reportId'] ?? '');
        $name = preg_replace('/\s+/u', ' ', cleanText($input['name'] ?? ''));
        $email = cleanText($input['email'] ?? '');
        $phone = cleanText($input['phone'] ?? '');
        $proof = cleanText($input['proof'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Enter your full name.';
        } elseif (mb_strlen($name) > CLAIM_LIMITS['name']) {
            $errors['name'] = 'Keep your name to ' . CLAIM_LIMITS['name'] . ' characters or fewer.';
        }
        if (!isValidEmail($email)) {
            $errors['email'] = 'Enter a valid email address so we can tell you the result.';
        }
        if ($phone !== '' && (mb_strlen($phone) > CLAIM_LIMITS['phone'] || !preg_match('/^[0-9+()\-.\s]{6,30}$/', $phone))) {
            $errors['phone'] = 'Enter a valid phone number, or leave this blank.';
        }
        if (mb_strlen($proof) < 15) {
            $errors['proof'] = 'Describe something only the owner would know (at least 15 characters).';
        } elseif (mb_strlen($proof) > CLAIM_LIMITS['proof']) {
            $errors['proof'] = 'Keep this to ' . CLAIM_LIMITS['proof'] . ' characters or fewer.';
        }
        if ($errors) {
            fail(422, 'Please fix the highlighted fields.', ['fields' => $errors]);
        }

        $report = isValidId($reportId) ? loadReportRow($reportId) : null;
        if (!$report || $report['status'] !== 'active') {
            fail(404, 'This item is no longer available to claim. It may already have been returned.');
        }
        if ($report['type'] !== 'found') {
            fail(422, 'Only found items can be claimed.');
        }

        $c = config();
        $ip = clientIp();
        if (rateCount('claim', $ip, 3600) >= (int) ($c['claim_limit'] ?? 5)) {
            tooManyRequests('You have sent several claims recently. Please try again later.', 3600);
        }
        $dupe = db()->prepare("SELECT reference FROM claims WHERE report_id = ? AND claimant_email = ? AND status = 'pending'");
        $dupe->execute([$reportId, $email]);
        if ($existing = $dupe->fetchColumn()) {
            fail(409, "You already have a pending claim for this item (reference {$existing}). We will email you when it has been reviewed.");
        }

        [$photo, $mime, $photoError] = storeUploadedImage('photo', 'claims');
        if ($photoError !== null) {
            fail(422, 'Please fix the highlighted fields.', ['fields' => ['photo' => $photoError]]);
        }

        $reference = newReference('CL', 'claims');
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO claims (reference, report_id, claimant_name, claimant_email, claimant_phone, proof, proof_photo, proof_mime)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$reference, $reportId, $name, $email, $phone !== '' ? $phone : null, $proof, $photo, $mime]);
            $claimId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO claim_status_history (claim_id, from_status, to_status, note) VALUES (?, NULL, 'pending', 'Claim submitted')")
                ->execute([$claimId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            deleteStoredFile('claims', $photo);
            throw $e;
        }
        rateHit('claim', $ip);

        $mail = sendEmail('claim_received', $email, [
            'claim' => ['reference' => $reference, 'claimant_name' => $name],
            'report' => $report,
        ], ['report_id' => $reportId, 'claim_id' => $claimId, 'item_title' => $report['title']]);

        respond(201, ['ok' => true, 'reference' => $reference, 'status' => 'pending', 'statusLabel' => CLAIM_LABELS['pending'], 'emailSent' => $mail['status'] === 'sent']);
    }

    /* ---------------- Staff ---------------- */
    $me = requireAdmin($method !== 'GET');

    if ($method === 'GET') {
        $rows = db()->query(claimSelect() . " ORDER BY (c.status = 'pending') DESC, c.created_at DESC LIMIT 500")->fetchAll();
        $history = claimHistory(array_map(static fn ($r) => (int) $r['id'], $rows));
        respond(200, [
            'claims' => array_map(static fn ($r) => presentClaim($r, $history[(int) $r['id']] ?? []), $rows),
            'counts' => claimCounts(),
            'stats' => staffStats(),
        ]);
    }

    // PATCH: review a claim.
    $id = (int) ($_GET['id'] ?? 0);
    $input = readJson();
    $status = $input['status'] ?? '';
    if (!is_string($status) || !isset(CLAIM_LABELS[$status])) {
        fail(422, 'Choose Approve or Reject.');
    }
    $note = cleanText($input['note'] ?? '');
    if (mb_strlen($note) > CLAIM_LIMITS['note']) {
        fail(422, 'Keep the note to ' . CLAIM_LIMITS['note'] . ' characters or fewer.');
    }
    $markFound = !empty($input['markFound']) && $status === 'approved';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, reference, report_id, claimant_name, claimant_email, status FROM claims WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $claim = $stmt->fetch();
        if (!$claim) {
            $pdo->rollBack();
            fail(404, 'Claim not found.');
        }
        if ($claim['status'] === $status) {
            $pdo->rollBack();
            fail(409, 'This claim is already ' . strtolower(CLAIM_LABELS[$status]) . '.');
        }
        $pdo->prepare('UPDATE claims SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ' . ($status === 'pending' ? 'NULL' : 'NOW()') . ' WHERE id = ?')
            ->execute([$status, $note !== '' ? $note : null, $status === 'pending' ? null : $me['id'], $id]);
        $pdo->prepare('INSERT INTO claim_status_history (claim_id, from_status, to_status, note, changed_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $claim['status'], $status, $note !== '' ? $note : null, $me['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $report = loadReportRow($claim['report_id']);
    $emails = [];
    if ($status !== 'pending') {
        $emails['claimant'] = sendEmail('claim_' . $status, $claim['claimant_email'], [
            'claim' => $claim, 'report' => $report, 'note' => $note,
        ], ['report_id' => $claim['report_id'], 'claim_id' => $id, 'item_title' => $report['title']], $me['id']);
    }
    if ($markFound) {
        $change = changeReportStatus($claim['report_id'], 'resolved', $me);
        if ($change['changed']) {
            $emails['reporter'] = $change['email'];
        }
    }

    respond(200, ['claim' => findClaim($id), 'emails' => $emails, 'counts' => claimCounts(), 'stats' => staffStats()]);
} catch (Throwable $error) {
    error_log('[lost-found] ' . $error);
    fail(500, 'Something went wrong on the server. Please try again in a moment.');
}
