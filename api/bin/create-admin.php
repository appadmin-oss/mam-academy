<?php
/**
 * Create or update the first administrator from the shell.
 *
 *   php api/bin/create-admin.php <email> [password] [name]
 *
 * Omitted password is prompted (hidden where the terminal supports it).
 * Idempotent by email — re-running updates the password. After this, sign in
 * at /portal/login.html (admins are redirected to /admin/).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/bootstrap.php';

$email = $argv[1] ?? null;
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php api/bin/create-admin.php <email> [password] [name]\n");
    exit(1);
}
$password = $argv[2] ?? null;
if ($password === null) {
    fwrite(STDOUT, 'Password (min 8 chars): ');
    @shell_exec('stty -echo 2>/dev/null');
    $password = trim((string)fgets(STDIN));
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
}
$name = $argv[3] ?? 'Administrator';

try {
    $r = mam_create_admin($email, $password, $name);
    fwrite(STDOUT, ($r['created'] ? "Created" : "Updated") . " admin {$r['email']} (id {$r['id']}).\n");
    fwrite(STDOUT, "Sign in at /portal/login.html\n");
    exit(0);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() === 'weak_password' ? "Password must be at least 8 characters.\n" : "Invalid email address.\n");
    exit(1);
}
