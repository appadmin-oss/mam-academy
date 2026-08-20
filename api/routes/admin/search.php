<?php
/**
 * GET /api/admin/search?q=…  — the ⌘K palette's lookup.
 *
 * Three groups: people, applications, payments. Deliberately narrow — the
 * palette is for getting somewhere, not for reporting, so each group returns at
 * most six rows and no group carries a figure a screen would have to explain.
 *
 * The route is gated by mw_admin_cap('users') because every group can name a
 * person. "Searches only what your role can see" is printed on the palette, and
 * it is the middleware that makes it true.
 */
declare(strict_types=1);

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) mam_ok(['users' => [], 'applications' => [], 'payments' => []]);
if (mb_strlen($q) > 80) $q = mb_substr($q, 0, 80);

/* LIKE, with the user's own wildcards escaped. Without this a query of "100%"
   matches every row and "_" matches any single character — harmless here, but a
   search box that quietly ignores what was typed is a bug.
 *
 * The escape character is `!`, NOT a backslash, and that is load-bearing rather
 * than a matter of taste. `ESCAPE '\'` is one backslash in the SQL text; SQLite
 * accepts it, and MariaDB reads the backslash as escaping the closing quote, so
 * the string literal runs on and the whole statement is a syntax error. It
 * passed every test on SQLite and 500'd on the first MySQL request. A character
 * that is not itself an escape needs no escaping to write. */
$like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';

$users = mam_db_all(
    "SELECT id, name, email, role, status FROM users
      WHERE name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR enrollment_ref LIKE ? ESCAPE '!'
      ORDER BY (role = 'student') DESC, name LIMIT 6",
    [$like, $like, $like]
);

/* The applicant's name is composed in PHP, not by the database: `||` is
   concatenation in SQLite and OR in MySQL, so a SQL-side concat is silently
   wrong on one of the two engines this repo runs on. */
$applications = mam_db_all(
    "SELECT a.id, a.reference, a.status, a.program, c.first_name, c.last_name
       FROM applications a LEFT JOIN customers c ON c.id = a.customer_id
      WHERE a.reference LIKE ? ESCAPE '!' OR c.first_name LIKE ? ESCAPE '!'
         OR c.last_name LIKE ? ESCAPE '!' OR c.email LIKE ? ESCAPE '!'
      ORDER BY a.created_at DESC LIMIT 6",
    [$like, $like, $like, $like]
);
foreach ($applications as &$a) {
    $a['name'] = trim(((string) ($a['first_name'] ?? '')) . ' ' . ((string) ($a['last_name'] ?? '')));
    unset($a['first_name'], $a['last_name']);
}
unset($a);

$payments = mam_db_all(
    "SELECT id, reference, provider_reference, status, amount, currency, created_at
       FROM payments
      WHERE reference LIKE ? ESCAPE '!' OR provider_reference LIKE ? ESCAPE '!'
      ORDER BY created_at DESC LIMIT 6",
    [$like, $like]
);

mam_ok(['users' => $users, 'applications' => $applications, 'payments' => $payments]);
