<?php
declare(strict_types=1);

if (!defined('LF_APP')) {
    http_response_code(403);
    exit;
}

const LF_MAX_BODY = 32768;
const LF_LIMITS = ['title' => 70, 'location' => 90, 'description' => 500, 'contact' => 120];
const LF_SESSION_NAME = 'lf_staff';
const LF_MAX_IMAGE_BYTES = 6 * 1024 * 1024;
const LF_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

// One place for the wording shown to people. The database values never change,
// so existing records keep working; only the labels do.
const STATUS_LABELS = ['pending' => 'Awaiting review', 'active' => 'On the board', 'resolved' => 'Found'];
const CLAIM_LABELS = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];

ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

function config(): array
{
    static $config = null;
    return $config ??= require __DIR__ . '/config.php';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config()['db'];
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']),
            $c['user'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        // Store and read every TIMESTAMP as UTC so the "Z" timestamps we send are true.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function respond(int $status, $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(int $status, string $message, array $extra = []): never
{
    respond($status, ['error' => $message] + $extra);
}

function requireMethod(array $allowed): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        fail(405, 'Method not allowed.');
    }
    return $method;
}

/** Reads a JSON object body. Requiring the JSON content type also blocks plain cross-site form posts. */
function readJson(): array
{
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($type, 'application/json')) {
        fail(415, 'Requests must be sent as application/json.');
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, LF_MAX_BODY + 1);
    if (strlen($raw) > LF_MAX_BODY) {
        fail(413, 'The request is too large.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || array_is_list($data) && $data !== []) {
        fail(400, 'The request body must be a JSON object.');
    }
    return $data;
}

function isValidId(string $id): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
}

function newUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function clientIp(): string
{
    // REMOTE_ADDR only: forwarded headers can be forged by the visitor.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function limiterKey(string $value): string
{
    return hash('sha256', config()['rate_limit_salt'] . '|' . $value);
}

function rateCount(string $bucket, string $key, int $window): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM rate_limits
         WHERE bucket = ? AND key_hash = ? AND created_at > (NOW() - INTERVAL ' . (int) $window . ' SECOND)'
    );
    $stmt->execute([$bucket, limiterKey($key)]);
    return (int) $stmt->fetchColumn();
}

function rateHit(string $bucket, string $key): void
{
    db()->prepare('INSERT INTO rate_limits (bucket, key_hash) VALUES (?, ?)')->execute([$bucket, limiterKey($key)]);
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
}

function rateClear(string $bucket, string $key): void
{
    db()->prepare('DELETE FROM rate_limits WHERE bucket = ? AND key_hash = ?')->execute([$bucket, limiterKey($key)]);
}

function tooManyRequests(string $message, int $retryAfter): never
{
    header('Retry-After: ' . $retryAfter);
    fail(429, $message);
}

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(LF_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

/** Returns the signed-in staff member, or null. Only resumes a session the browser already holds. */
function currentAdmin(bool $touch = true): ?array
{
    if (empty($_COOKIE[LF_SESSION_NAME])) {
        return null;
    }
    startSession();
    $now = time();
    $c = config();
    $admin = $_SESSION['admin'] ?? null;
    $expired = !is_array($admin)
        || $now - (int) ($_SESSION['started'] ?? 0) > $c['session_lifetime']
        || $now - (int) ($_SESSION['seen'] ?? 0) > $c['session_idle'];
    if ($expired) {
        endSession();
        return null;
    }
    // Re-read the account on every request so a disabled account or a role change
    // takes effect immediately instead of at the next sign-in.
    $stmt = db()->prepare('SELECT id, username, role, full_name, email, is_active FROM admins WHERE id = ?');
    $stmt->execute([(int) $admin['id']]);
    $row = $stmt->fetch();
    if (!$row || !(int) $row['is_active']) {
        endSession();
        return null;
    }
    $admin = staffSummary($row);
    $_SESSION['admin'] = $admin;
    if ($touch) {
        $_SESSION['seen'] = $now;
    }
    return $admin;
}

function staffSummary(array $row): array
{
    $name = trim((string) ($row['full_name'] ?? ''));
    return [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'role' => $row['role'] === 'admin' ? 'admin' : 'staff',
        'name' => $name !== '' ? $name : $row['username'],
    ];
}

function endSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        startSession();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(LF_SESSION_NAME, '', [
            'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

/**
 * Requires a signed-in staff member. Pass $role = 'admin' for administrator-only actions.
 * Both roles ("staff" and "admin") are authorised staff; only admins can assign, delete and send test emails.
 */
function requireAdmin(bool $needsCsrf, ?string $role = null): array
{
    $admin = currentAdmin();
    if ($admin === null) {
        fail(401, 'Please sign in to continue.', ['code' => 'auth_required']);
    }
    if ($role === 'admin' && $admin['role'] !== 'admin') {
        fail(403, 'Only administrators can do this.', ['code' => 'forbidden']);
    }
    if ($needsCsrf) {
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals((string) $_SESSION['csrf'], $sent)) {
            fail(403, 'Your session token is invalid. Reload the page and try again.', ['code' => 'csrf']);
        }
    }
    return $admin;
}

const REPORT_COLUMNS = "id, report_type AS type, category, title, location,
        DATE_FORMAT(report_date, '%Y-%m-%d') AS date,
        description, status, (photo IS NOT NULL) AS hasPhoto,
        DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt";

/** Staff see the reference code, contact email and when the status last changed. */
const ADMIN_REPORT_COLUMNS = REPORT_COLUMNS . ", reference, contact,
        DATE_FORMAT(status_changed_at, '%Y-%m-%dT%H:%i:%sZ') AS statusChangedAt";

/** Casts database values into the shapes the browser expects and adds the photo URL. */
function presentReport(array $row, ?string $ref = null): array
{
    $row['hasPhoto'] = (bool) $row['hasPhoto'];
    $row['statusLabel'] = STATUS_LABELS[$row['status']] ?? $row['status'];
    $row['photoUrl'] = $row['hasPhoto']
        ? 'api/image.php?report=' . rawurlencode($row['id']) . ($ref !== null ? '&ref=' . rawurlencode($ref) : '')
        : null;
    return $row;
}

function statusLabel(string $status): string
{
    return STATUS_LABELS[$status] ?? ucfirst($status);
}

/** Short, unambiguous code people can type: LF-7KQ2M9XA. Retries on the rare collision. */
function newReference(string $prefix, string $table): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = $prefix . '-';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, 31)];
        }
        $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE reference = ?");
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique reference.');
}

function normaliseReference(string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', trim($value)));
}

function isValidEmail(string $email): bool
{
    return $email !== '' && mb_strlen($email) <= LF_LIMITS['contact']
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && !preg_match('/[\r\n]/', $email);
}

/**
 * Reads the request body for endpoints that accept a photo: JSON or multipart/form-data.
 * Public endpoints only; staff endpoints keep using readJson() plus the CSRF token.
 */
function readInput(): array
{
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_starts_with($type, 'multipart/form-data')) {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 0 && $_POST === [] && $_FILES === []) {
            // PHP drops the whole body when it exceeds post_max_size.
            fail(413, 'The photo is too large. Please choose a smaller image (under 5 MB).', ['fields' => ['photo' => 'This photo is too large.']]);
        }
        return $_POST;
    }
    return readJson();
}

function storageDir(string $kind): string
{
    $dir = dirname(__DIR__) . '/storage/' . $kind;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create {$dir}");
    }
    return $dir;
}

/**
 * Validates an uploaded image and stores it under storage/<kind>/ with a random name.
 * Returns [filename, mime] or [null, null] when no file was sent. Errors are returned
 * as a user-facing message in the third element.
 *
 * @return array{0: ?string, 1: ?string, 2: ?string}
 */
function storeUploadedImage(string $field, string $kind): array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null, null];
    }
    if (is_array($file['error'])) {
        return [null, null, 'Upload one photo only.'];
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > LF_MAX_IMAGE_BYTES) {
        return [null, null, 'This photo is too large. Please choose an image under 5 MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, null, 'The photo could not be uploaded. Please try again.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $info = @getimagesize($file['tmp_name']);
    if (!isset(LF_IMAGE_TYPES[$mime]) || !$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
        return [null, null, 'Choose a JPG, PNG or WebP photo.'];
    }

    $name = bin2hex(random_bytes(16)) . '.' . LF_IMAGE_TYPES[$mime];
    $target = storageDir($kind) . '/' . $name;

    // When GD is available, re-encode the image: this strips hidden metadata (such as
    // GPS location) and anything that is not image data, and caps the size.
    if (reencodeImage($file['tmp_name'], $mime, $target, $info[0], $info[1])) {
        return [$name, $mime, null];
    }
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not store uploaded file.');
    }
    return [$name, $mime, null];
}

function reencodeImage(string $source, string $mime, string $target, int $width, int $height): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }
    $image = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        default => false,
    };
    if (!$image) {
        return false;
    }
    $max = 1600;
    if ($width > $max || $height > $max) {
        $scale = $max / max($width, $height);
        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        if ($resized) {
            imagedestroy($image);
            $image = $resized;
        }
    }
    if ($mime !== 'image/jpeg') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($image, $target, 85),
        'image/png' => imagepng($image, $target, 6),
        'image/webp' => function_exists('imagewebp') && imagewebp($image, $target, 85),
    };
    imagedestroy($image);
    return (bool) $ok;
}

function deleteStoredFile(string $kind, ?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name)) {
        $path = dirname(__DIR__) . '/storage/' . $kind . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function stats(): array
{
    $rows = db()->query('SELECT status, COUNT(*) AS n FROM reports GROUP BY status')->fetchAll();
    $out = ['pending' => 0, 'active' => 0, 'resolved' => 0];
    foreach ($rows as $row) {
        $out[$row['status']] = (int) $row['n'];
    }
    return $out;
}

function staffStats(): array
{
    $out = stats();
    $out['pendingClaims'] = (int) db()->query("SELECT COUNT(*) FROM claims WHERE status = 'pending'")->fetchColumn();
    $out['openMatches'] = (int) db()->query("SELECT COUNT(*) FROM matches WHERE status = 'open'")->fetchColumn();
    $out['failedEmails'] = (int) db()->query(
        "SELECT COUNT(*) FROM email_notifications WHERE status = 'failed' AND created_at > (NOW() - INTERVAL 7 DAY)"
    )->fetchColumn();
    return $out;
}

/** Normalises text: valid UTF-8, no control characters (newlines and tabs allowed), trimmed. */
function cleanText($value): string
{
    $value = is_string($value) ? $value : '';
    if (!mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = (string) preg_replace('/[^\P{C}\n\t]+/u', '', $value);
    return trim($value);
}

/** @return array{0: array, 1: array<string,string>} cleaned values and per-field errors */
function validateReport(array $input): array
{
    $categories = config()['categories'];
    $type = cleanText($input['type'] ?? '');
    $category = cleanText($input['category'] ?? '');
    $title = preg_replace('/\s+/u', ' ', cleanText($input['title'] ?? ''));
    $location = preg_replace('/\s+/u', ' ', cleanText($input['location'] ?? ''));
    $description = cleanText($input['description'] ?? '');
    $contact = cleanText($input['contact'] ?? '');
    $date = cleanText($input['date'] ?? '');

    $errors = [];
    if (!in_array($type, ['lost', 'found'], true)) {
        $errors['type'] = 'Choose whether the item was lost or found.';
    }
    if (!in_array($category, $categories, true)) {
        $errors['category'] = 'Choose a category from the list.';
    }
    if ($title === '') {
        $errors['title'] = 'Tell us what the item is.';
    } elseif (mb_strlen($title) > LF_LIMITS['title']) {
        $errors['title'] = 'Keep the item name to ' . LF_LIMITS['title'] . ' characters or fewer.';
    }
    if ($location === '') {
        $errors['location'] = 'Tell us where it was last seen or found.';
    } elseif (mb_strlen($location) > LF_LIMITS['location']) {
        $errors['location'] = 'Keep the location to ' . LF_LIMITS['location'] . ' characters or fewer.';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        $errors['date'] = 'Enter a valid date.';
    } elseif ($date > (new DateTimeImmutable('today'))->format('Y-m-d')) {
        $errors['date'] = 'The date cannot be in the future.';
    }
    if (mb_strlen($description) > LF_LIMITS['description']) {
        $errors['description'] = 'Keep the details to ' . LF_LIMITS['description'] . ' characters or fewer.';
    }
    if ($contact !== '' && (mb_strlen($contact) > LF_LIMITS['contact'] || !filter_var($contact, FILTER_VALIDATE_EMAIL))) {
        $errors['contact'] = 'Enter a valid email address, or leave this blank.';
    }

    return [compact('type', 'category', 'title', 'location', 'date', 'description', 'contact'), $errors];
}

require __DIR__ . '/mailer.php';
