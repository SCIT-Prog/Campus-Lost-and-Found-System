<?php
declare(strict_types=1);

if (!defined('LF_APP')) {
    http_response_code(403);
    exit;
}

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : $value;
};

$dbPort = filter_var($env('LF_DB_PORT', '3307'), FILTER_VALIDATE_INT);
if ($dbPort === false || $dbPort < 1 || $dbPort > 65535) {
    throw new RuntimeException('LF_DB_PORT must be a valid TCP port.');
}

// XAMPP defaults to MySQL/MariaDB on 127.0.0.1:3306 with root and no password.
// For deployment, use a limited MySQL user instead of root.
return [
    'db' => [
        'host'     => $env('LF_DB_HOST', '127.0.0.1'),
        'port'     => $dbPort,
        'name'     => $env('LF_DB_NAME', 'campus_lost_found'),
        'user'     => $env('LF_DB_USER', 'root'),
        'password' => $env('LF_DB_PASSWORD'),
    ],

    // Any random string. Used only to hash visitor IP addresses for rate limiting.
    'rate_limit_salt' => 'change-me-to-a-random-string',

    // Public report submissions allowed per visitor IP in the window below.
    'submit_limit'  => 5,
    'submit_window' => 3600,   // seconds

    // Failed staff sign-ins allowed per username+IP (and 4x that per IP) in the window.
    'login_limit'  => 5,
    'login_window' => 900,     // seconds

    // Staff sessions end after this much inactivity, or this long in total.
    'session_idle'     => 1800,   // 30 minutes
    'session_lifetime' => 28800,  // 8 hours

    // Public address of this site, used for links inside emails. Leave blank to omit links.
    'app_url' => 'http://localhost/campus-lost-found/',

    // Dates in emails are shown in this time zone.
    'timezone' => 'Asia/Manila',

    // Shown in emails as the place to collect items.
    'office' => [
        'name'     => 'Campus Security Office',
        'location' => 'Admin Building, Room 104',
        'hours'    => 'Monday to Friday, 8:00 AM to 5:00 PM',
    ],

    // Outgoing email. These values are only read on the server and are never sent to
    // the browser, not even to staff.
    //
    // Gmail: host smtp.gmail.com, port 587, encryption 'tls', username = the Gmail
    // address, password = an App Password (Google Account > Security > 2-Step
    // Verification > App passwords). Your normal Gmail password will not work.
    // Outlook/Office 365: host smtp.office365.com, port 587, encryption 'tls'.
    // Port 465 uses encryption 'ssl'.
    'mail' => [
        'enabled'    => filter_var($env('LF_MAIL_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),
        'transport'  => 'smtp',          // 'smtp' (recommended) or 'mail' (PHP mail(), needs sendmail set up)
        'host'       => $env('LF_MAIL_HOST'), // e.g. smtp.gmail.com
        'port'       => (int) $env('LF_MAIL_PORT', '587'),
        'encryption' => $env('LF_MAIL_ENCRYPTION', 'tls'),
        'username'   => $env('LF_MAIL_USERNAME'),
        'password'   => $env('LF_MAIL_PASSWORD'),
        'from_email' => $env('LF_MAIL_FROM_EMAIL'),
        'from_name'  => 'Campus Lost & Found',
        'reply_to'   => '',              // optional: where replies should go, e.g. the security office inbox
        'timeout'    => 15,              // seconds
        'verify_certificate' => true,    // keep true; only set false for a local test server
    ],

    // Public claim submissions and reference look-ups allowed per visitor IP per hour.
    'claim_limit'  => 5,
    'lookup_limit' => 30,

    'categories' => [
        'Accessories', 'Bags', 'Books & stationery', 'Clothing',
        'Electronics', 'Keys & cards', 'Water bottles', 'Other',
    ],
];
