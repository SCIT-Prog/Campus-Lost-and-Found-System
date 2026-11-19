<?php
declare(strict_types=1);

// Staff authentication.
//   GET    -> who is signed in (never creates a session)
//   POST   -> sign in with {username, password}
//   DELETE -> sign out (requires the CSRF token)
define('LF_APP', true);
require __DIR__ . '/bootstrap.php';

try {
    $method = requireMethod(['GET', 'POST', 'DELETE']);
    $c = config();

    if ($method === 'GET') {
        $admin = currentAdmin();
        respond(200, $admin
            ? ['authenticated' => true, 'username' => $admin['username'], 'name' => $admin['name'], 'role' => $admin['role'], 'id' => $admin['id'], 'csrf' => $_SESSION['csrf']]
            : ['authenticated' => false]);
    }

    if ($method === 'DELETE') {
        requireAdmin(true);
        endSession();
        respond(200, ['authenticated' => false]);
    }

    $input = readJson();
    $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    if ($username === '' || $password === '' || mb_strlen($username) > 50 || strlen($password) > 1024) {
        fail(422, 'Enter your username and password.');
    }

    $ip = clientIp();
    $userKey = $ip . '|' . $username;
    $window = $c['login_window'];
    if (rateCount('login', $userKey, $window) >= $c['login_limit'] || rateCount('login_ip', $ip, $window) >= $c['login_limit'] * 4) {
        tooManyRequests('Too many failed sign-in attempts. Please wait a few minutes and try again.', $window);
    }

    $stmt = db()->prepare('SELECT id, username, password_hash, role, full_name, email, is_active FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    // Always run password_verify so unknown usernames take as long as wrong passwords.
    $hash = $row['password_hash'] ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $valid = password_verify($password, $hash) && $row && (int) $row['is_active'] === 1;

    if (!$valid) {
        rateHit('login', $userKey);
        rateHit('login_ip', $ip);
        fail(401, 'That username or password is not correct.');
    }

    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    rateClear('login', $userKey);

    startSession();
    session_regenerate_id(true); // stops session fixation
    $_SESSION = [
        'admin' => staffSummary($row),
        'csrf' => bin2hex(random_bytes(32)),
        'started' => time(),
        'seen' => time(),
    ];
    $me = $_SESSION['admin'];
    respond(200, ['authenticated' => true, 'username' => $me['username'], 'name' => $me['name'], 'role' => $me['role'], 'id' => $me['id'], 'csrf' => $_SESSION['csrf']]);
} catch (Throwable $error) {
    error_log('[lost-found] ' . $error);
    fail(500, 'Something went wrong on the server. Please try again in a moment.');
}
