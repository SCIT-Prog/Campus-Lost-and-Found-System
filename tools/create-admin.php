<?php
declare(strict_types=1);

// Create a staff account, or update it (password, role, name, email) if the username exists.
//
//   Windows (XAMPP):  C:\xampp\php\php.exe tools\create-admin.php security --role=admin
//   macOS / Linux:    php tools/create-admin.php jdelacruz --role=staff --name="Juan Dela Cruz" --email=juan@campus.edu
//
// Options:
//   --role=admin|staff   admin: everything, including assigning matches, deleting reports and test emails.
//                        staff: review reports and claims, send emails, work on assigned matches.
//                        New accounts default to staff; existing accounts keep their role unless given.
//   --name="Full Name"   shown on match assignments
//   --email=address      receives "you have been assigned a match" emails
//   --disable / --enable turn an account off or on without deleting it (no password prompt)
//
// Command line only; refuses to run when requested through the web server.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

define('LF_APP', true);
require __DIR__ . '/../api/bootstrap.php';

$args = array_slice($argv, 1);
$username = '';
$opts = [];
foreach ($args as $arg) {
    if (preg_match('/^--(role|name|email)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = trim($m[2]);
    } elseif ($arg === '--disable' || $arg === '--enable') {
        $opts['active'] = $arg === '--enable' ? 1 : 0;
    } elseif ($username === '' && !str_starts_with($arg, '--')) {
        $username = mb_strtolower(trim($arg));
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}
if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
    fwrite(STDERR, "Usage: php tools/create-admin.php <username> [--role=admin|staff] [--name=\"Full Name\"] [--email=address]\n");
    fwrite(STDERR, "Usernames are 3-50 characters: letters, numbers, dot, dash, underscore.\n");
    exit(1);
}
if (isset($opts['role']) && !in_array($opts['role'], ['admin', 'staff'], true)) {
    fwrite(STDERR, "--role must be admin or staff.\n");
    exit(1);
}
if (isset($opts['email']) && $opts['email'] !== '' && !isValidEmail($opts['email'])) {
    fwrite(STDERR, "--email is not a valid email address.\n");
    exit(1);
}
if (isset($opts['name']) && mb_strlen($opts['name']) > 80) {
    fwrite(STDERR, "--name must be 80 characters or fewer.\n");
    exit(1);
}

$existing = db()->prepare('SELECT id, role FROM admins WHERE username = ?');
$existing->execute([$username]);
$account = $existing->fetch();

if (isset($opts['active'])) {
    if (!$account) {
        fwrite(STDERR, "No account named \"{$username}\".\n");
        exit(1);
    }
    db()->prepare('UPDATE admins SET is_active = ? WHERE id = ?')->execute([$opts['active'], $account['id']]);
    echo ($opts['active'] ? 'Enabled' : 'Disabled') . " account \"{$username}\".\n";
    exit(0);
}

function promptHidden(string $label): string
{
    echo $label;
    $hidden = PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec') && trim((string) @shell_exec('stty -g 2>/dev/null')) !== '';
    if ($hidden) {
        shell_exec('stty -echo');
    } else {
        echo "(input will be visible) ";
    }
    $line = fgets(STDIN);
    if ($hidden) {
        shell_exec('stty echo');
        echo "\n";
    }
    return rtrim((string) $line, "\r\n");
}

$password = promptHidden('New password (at least 12 characters): ');
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}
if ($password !== promptHidden('Repeat password: ')) {
    fwrite(STDERR, "Passwords did not match.\n");
    exit(1);
}

try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($account) {
        $sets = ['password_hash = ?'];
        $params = [$hash];
        foreach (['role' => 'role', 'name' => 'full_name', 'email' => 'email'] as $opt => $column) {
            if (isset($opts[$opt])) {
                $sets[] = "{$column} = ?";
                $params[] = $opts[$opt] !== '' ? $opts[$opt] : null;
            }
        }
        $params[] = $account['id'];
        db()->prepare('UPDATE admins SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        $role = $opts['role'] ?? $account['role'];
    } else {
        $role = $opts['role'] ?? 'staff';
        db()->prepare('INSERT INTO admins (username, password_hash, role, full_name, email) VALUES (?, ?, ?, ?, ?)')
            ->execute([$username, $hash, $role, ($opts['name'] ?? '') ?: null, ($opts['email'] ?? '') ?: null]);
    }
    echo "Saved {$role} account \"{$username}\".\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Could not save the account: ' . $error->getMessage() . "\n");
    exit(1);
}
