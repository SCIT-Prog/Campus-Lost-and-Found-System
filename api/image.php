<?php
declare(strict_types=1);

// Serves uploaded photos. Files live in storage/, which the web server refuses to
// serve directly, so every image goes through these checks:
//   ?report=<id>             published reports, or any report for signed-in staff
//   ?report=<id>&ref=<code>  the reporter's own report, using their reference code
//   ?claim=<id>              claim proof photos, staff only
define('LF_APP', true);
require __DIR__ . '/bootstrap.php';

function sendImage(string $kind, ?string $name, ?string $mime): never
{
    if (!$name || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name) || !isset(LF_IMAGE_TYPES[(string) $mime])) {
        fail(404, 'Image not found.');
    }
    $path = dirname(__DIR__) . '/storage/' . $kind . '/' . $name;
    if (!is_file($path)) {
        fail(404, 'Image not found.');
    }
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($path);
    exit;
}

try {
    requireMethod(['GET']);

    if (isset($_GET['claim'])) {
        requireAdmin(false);
        $stmt = db()->prepare('SELECT proof_photo, proof_mime FROM claims WHERE id = ?');
        $stmt->execute([(int) $_GET['claim']]);
        $row = $stmt->fetch();
        sendImage('claims', $row['proof_photo'] ?? null, $row['proof_mime'] ?? null);
    }

    $id = (string) ($_GET['report'] ?? '');
    if (!isValidId($id)) {
        fail(400, 'A valid report ID is required.');
    }
    $stmt = db()->prepare('SELECT status, reference, photo, photo_mime FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail(404, 'Image not found.');
    }
    $ref = normaliseReference((string) ($_GET['ref'] ?? ''));
    $allowed = $row['status'] === 'active'
        || ($ref !== '' && hash_equals((string) $row['reference'], $ref))
        || currentAdmin(false) !== null;
    if (!$allowed) {
        fail(404, 'Image not found.');
    }
    sendImage('reports', $row['photo'], $row['photo_mime']);
} catch (Throwable $error) {
    error_log('[lost-found] ' . $error);
    fail(500, 'Something went wrong on the server.');
}
