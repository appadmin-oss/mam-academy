<?php
/**
 * schema-v2 — the schema is executable, and its constraints actually bite.
 *
 * A schema file that has never been run is a document, not a schema. These
 * tests build the SQLite mirror in memory, drive a complete learner journey
 * through it — tenant → person → membership → catalogue → cohort → enrolment →
 * session → attendance → invoice → payment → ledger — and then attempt fourteen
 * things the design says must be impossible.
 *
 * The second half is the point. v1's schema accepted an attendance status it
 * had no vocabulary for, a payment whose currency nobody had converted, and two
 * parallel enrolments in the same course; each was caught in production rather
 * than by a constraint. Every one of those is asserted here as a REJECTION.
 *
 * The mirror is generated from the canonical MySQL file, so drift between the
 * two engines is itself a test failure rather than something discovered on a
 * deploy.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/schema-v2-sqlite.php';

$V2_MYSQL  = dirname(__DIR__, 2) . '/db/schema-v2.sql';
$V2_SQLITE = dirname(__DIR__, 2) . '/db/schema-v2-sqlite.sql';

t_group('schema-v2 — the mirror tracks the canonical file');

$mysqlSrc = (string) file_get_contents($V2_MYSQL);
t_ok($mysqlSrc !== '', 'db/schema-v2.sql exists');
// t_ok, not t_eq: a mismatch here is a 70KB file and dumping both copies buries
// every other result in the run.
t_ok((string) file_get_contents($V2_SQLITE) === mam_v2_to_sqlite($mysqlSrc),
    'db/schema-v2-sqlite.sql is in sync (regenerate: php api/tools/schema-v2-sqlite.php)');

// v1 drifted precisely because a MySQL-only construct could be added without
// anything noticing. Catch the ones that would silently not translate.
foreach (['ENUM(' => 'ENUM columns', 'GENERATED ALWAYS' => 'generated columns', 'FULLTEXT' => 'FULLTEXT indexes'] as $needle => $what) {
    t_ok(stripos($mysqlSrc, $needle) === false, "the canonical file uses no $what (they do not mirror)");
}

t_group('schema-v2 — the schema executes');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Strip comments before splitting: a `--` comment containing a semicolon would
// otherwise cut a statement in half, which is how this failed the first time.
$clean      = (string) preg_replace('~^\s*--.*$~m', '', (string) file_get_contents($V2_SQLITE));
$statements = array_values(array_filter(array_map('trim', explode(';', $clean))));
$applied    = 0;
$failed     = [];
foreach ($statements as $s) {
    try { $db->exec($s); $applied++; }
    catch (Throwable $e) { $failed[] = substr($s, 0, 60) . ' — ' . $e->getMessage(); }
}
t_eq(count($failed), 0, 'every statement applies cleanly' . ($failed ? ': ' . implode(' | ', $failed) : ''));
t_ok($applied > 100, 'the whole file ran (' . $applied . ' statements)');

$tables = (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
t_ok($tables >= 60, "the seven layers are present ($tables tables)");
$idx = (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name LIKE 'ix_%'")->fetchColumn();
t_ok($idx >= 50, "secondary indexes survived the translation ($idx) — a mirror with none would make dev a table scan");
t_eq(count($db->query('PRAGMA foreign_key_check')->fetchAll()), 0, 'no dangling foreign key targets');

// Every domain table must carry tenant_id, or the multi-tenancy claim is
// aspirational. The exceptions are named, so adding a new one is a decision.
$tenantless = ['tenants', 'currencies', 'users', 'user_credentials', 'user_identities',
               'permissions', 'fx_rates', 'idempotency_keys', 'jobs', 'schema_version',
               'role_permissions', 'membership_roles', 'cohort_staff', 'programme_courses',
               'rubric_criteria', 'invoice_lines', 'payment_allocations', 'price_items'];
$missing = [];
foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (in_array($t, $tenantless, true)) continue;
    $cols = array_column($db->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('tenant_id', $cols, true)) $missing[] = $t;
}
t_eq(count($missing), 0, 'every domain table carries tenant_id' . ($missing ? ': ' . implode(', ', $missing) : ''));

t_group('schema-v2 — a complete learner journey');

$db->exec('PRAGMA foreign_keys = ON');
$n = 0;
$ulid = function () use (&$n): string { return str_pad('01JMAMTEST' . str_pad((string) (++$n), 16, '0', STR_PAD_LEFT), 26, '0'); };
$ins = function (string $sql, array $p = []) use ($db): void { $db->prepare($sql)->execute($p); };

$ins("INSERT INTO tenants (id,public_id,slug,legal_name,display_name,base_currency) VALUES (1,?,'mam','MAM Centre Ltd','MAM Academy','USD')", [$ulid()]);
$ins("INSERT INTO centres (id,public_id,tenant_id,code,name) VALUES (1,?,1,'HQ','Lagos HQ')", [$ulid()]);
$ins("INSERT INTO users (id,public_id,email,full_name) VALUES (1,?,'ada@example.test','Ada Obi')", [$ulid()]);
$ins("INSERT INTO users (id,public_id,email,full_name) VALUES (2,?,'tutor@example.test','Femi Bello')", [$ulid()]);
$ins("INSERT INTO memberships (id,public_id,tenant_id,user_id,centre_id,reference,stage) VALUES (1,?,1,1,1,'MAM-0001','enrolled')", [$ulid()]);
$ins("INSERT INTO memberships (id,public_id,tenant_id,user_id,centre_id,reference,stage) VALUES (2,?,1,2,1,'MAM-S001','active')", [$ulid()]);
$ins("INSERT INTO lifecycle_events (tenant_id,membership_id,from_stage,to_stage,reason_code,actor_user_id) VALUES (1,1,'applicant','enrolled','payment_settled',2)");

$ins("INSERT INTO programmes (id,public_id,tenant_id,code,name) VALUES (1,?,1,'MUS','Music')", [$ulid()]);
$ins("INSERT INTO levels (id,tenant_id,code,name,rank_order) VALUES (1,1,'L1','Foundation',1)");
$ins("INSERT INTO courses (id,public_id,tenant_id,code,name) VALUES (1,?,1,'PIANO','Piano')", [$ulid()]);
$ins("INSERT INTO programme_courses (programme_id,course_id,level_id,sort_order) VALUES (1,1,1,1)");
$ins("INSERT INTO modules (id,public_id,tenant_id,course_id,level_id,week_no,title) VALUES (1,?,1,1,1,1,'Posture & the keyboard')", [$ulid()]);

$ins("INSERT INTO cohorts (id,public_id,tenant_id,centre_id,course_id,level_id,programme_id,title,timezone,status,capacity,lead_instructor_membership_id)
      VALUES (1,?,1,1,1,1,1,'Piano L1 — Autumn','Africa/Lagos','active',12,2)", [$ulid()]);
$ins("INSERT INTO cohort_staff (cohort_id,membership_id,role) VALUES (1,2,'instructor')");
$ins("INSERT INTO enrolments (id,public_id,tenant_id,membership_id,cohort_id,status,enrolled_at)
      VALUES (1,?,1,1,1,'active','2026-09-01 09:00:00.000')", [$ulid()]);

$ins("INSERT INTO rooms (id,tenant_id,centre_id,name,capacity) VALUES (1,1,1,'Studio A',15)");
$ins("INSERT INTO class_sessions (id,public_id,tenant_id,cohort_id,module_id,room_id,scheduled_start_local,timezone,scheduled_start_utc)
      VALUES (1,?,1,1,1,1,'2026-09-10 16:00:00','Africa/Lagos','2026-09-10 15:00:00.000')", [$ulid()]);
$ins("INSERT INTO attendance (tenant_id,session_id,enrolment_id,status,delivery_mode,clocked_in_at,minutes_present,minutes_late)
      VALUES (1,1,1,'late','onsite','2026-09-10 16:07:00.000',53,7)");
$ins("INSERT INTO module_progress (tenant_id,enrolment_id,module_id,status,mastery_pct) VALUES (1,1,1,'completed',82.5)");

// ₦120,000 tuition at 1600/USD = $75.00. The base figures are what any total
// may be summed on; the naira pair is what the learner actually owes.
$ins("INSERT INTO invoices (id,public_id,tenant_id,membership_id,enrolment_id,number,status,currency,total_minor,base_currency,base_total_minor,fx_rate,fx_source,issued_at)
      VALUES (1,?,1,1,1,'INV-0001','issued','NGN',12000000,'USD',7500,1600.00000000,'openexchange','2026-09-01 00:00:00.000')", [$ulid()]);
$ins("INSERT INTO invoice_lines (invoice_id,item_type,description,quantity,unit_amount_minor,line_total_minor) VALUES (1,'tuition','Piano L1 tuition',1,12000000,12000000)");
$ins("INSERT INTO payments (id,public_id,tenant_id,membership_id,provider,status,currency,amount_minor,base_currency,base_amount_minor,fx_rate,fx_source,received_at)
      VALUES (1,?,1,1,'paystack','settled','NGN',12000000,'USD',7500,1600.00000000,'openexchange','2026-09-02 10:00:00.000')", [$ulid()]);
$ins("INSERT INTO payment_allocations (payment_id,invoice_id,amount_minor) VALUES (1,1,12000000)");

// Signed minor units: positive debit, negative credit. Cash rises, tuition
// income is credited, and the pair sums to zero.
$ins("INSERT INTO ledger_accounts (id,tenant_id,code,name,type,currency) VALUES (1,1,'cash','Cash','asset','USD')");
$ins("INSERT INTO ledger_accounts (id,tenant_id,code,name,type,currency) VALUES (2,1,'revenue','Tuition income','income','USD')");
$ins("INSERT INTO ledger_entries (tenant_id,transaction_id,account_id,membership_id,amount_minor,currency,base_amount_minor,source_type,source_id,occurred_at)
      VALUES (1,?,1,1,7500,'USD',7500,'payment',1,'2026-09-02 10:00:00.000')", [$txn = $ulid()]);
$ins("INSERT INTO ledger_entries (tenant_id,transaction_id,account_id,membership_id,amount_minor,currency,base_amount_minor,source_type,source_id,occurred_at)
      VALUES (1,?,2,1,-7500,'USD',-7500,'payment',1,'2026-09-02 10:00:00.000')", [$txn]);

t_eq((int) $db->query('SELECT COUNT(*) FROM enrolments')->fetchColumn(), 1, 'the learner is enrolled');
t_eq((int) $db->query('SELECT COUNT(*) FROM attendance')->fetchColumn(), 1, 'the session was attended');

// The double-entry invariant: every transaction balances. This is the property
// that makes "why does this student owe ₦8,000" answerable without archaeology.
$unbalanced = $db->query("SELECT transaction_id FROM ledger_entries
    GROUP BY transaction_id HAVING SUM(base_amount_minor) <> 0")->fetchAll();
t_eq(count($unbalanced), 0, 'the ledger transaction balances (debits = credits)');

// The whole journey is reachable from the learner in one join path — the
// question v1 could not answer without dual-reading two enrolment models.
$row = $db->query("SELECT u.full_name, c.title AS cohort, co.name AS course, e.status,
                          a.status AS attendance, mp.mastery_pct, i.total_minor, i.base_total_minor
    FROM memberships m
    JOIN users u ON u.id = m.user_id
    JOIN enrolments e ON e.membership_id = m.id
    JOIN cohorts c ON c.id = e.cohort_id
    JOIN courses co ON co.id = c.course_id
    LEFT JOIN attendance a ON a.enrolment_id = e.id
    LEFT JOIN module_progress mp ON mp.enrolment_id = e.id
    LEFT JOIN invoices i ON i.enrolment_id = e.id
    WHERE m.id = 1")->fetch(PDO::FETCH_ASSOC);
t_eq($row['full_name'] ?? '', 'Ada Obi',  'the learner resolves from one join path');
t_eq($row['course'] ?? '',       'Piano',    '…through a single delivery spine');
t_eq((int) ($row['base_total_minor'] ?? 0), 7500, '…to a USD-denominated total, not a bare amount');

t_group('schema-v2 — the constraints bite');

/** Assert a statement is REJECTED. A constraint that has never fired proves nothing. */
$rejects = function (string $sql, string $why) use ($db): void {
    try { $db->exec($sql); t_ok(false, "REJECTED: $why (it was accepted)"); }
    catch (Throwable $e) { t_ok(true, "REJECTED: $why"); }
};

$rejects("INSERT INTO attendance (tenant_id,session_id,enrolment_id,status) VALUES (1,1,1,'maybe')",
    "an attendance status outside the seven-value vocabulary");
$rejects("INSERT INTO attendance (tenant_id,session_id,enrolment_id,status,delivery_mode) VALUES (1,1,1,'present','carrier-pigeon')",
    "a delivery mode that is not onsite/online/hybrid");
$rejects("INSERT INTO enrolments (public_id,tenant_id,membership_id,cohort_id,status) VALUES ('01JDUPE00000000000000000000',1,1,1,'active')",
    "a second live enrolment for the same learner in the same cohort");
$rejects("INSERT INTO module_progress (tenant_id,enrolment_id,module_id,status) VALUES (1,1,1,'vibes')",
    "a module_progress status outside its vocabulary");
$rejects("INSERT INTO enrolments (public_id,tenant_id,membership_id,cohort_id) VALUES ('01JORPHAN0000000000000000',1,1,999)",
    "an enrolment pointing at a cohort that does not exist");
$rejects("INSERT INTO invoices (public_id,tenant_id,membership_id,number,currency,total_minor,base_currency)
          VALUES ('01JBADFX000000000000000000',1,1,'INV-BAD','NGN',5000,'USD')",
    "a cross-currency invoice with no frozen FX rate — the v1 bug that summed naira into dollars");
$rejects("INSERT INTO payments (public_id,tenant_id,membership_id,provider,currency,amount_minor,base_currency,status)
          VALUES ('01JBADFY000000000000000000',1,1,'paystack','NGN',5000,'USD','settled')",
    "a cross-currency payment with no frozen FX rate");
$rejects("INSERT INTO payment_allocations (payment_id,invoice_id,amount_minor) VALUES (1,1,0)",
    "a zero-amount payment allocation");
$rejects("INSERT INTO fx_rates (base_currency,quote_currency,rate,source,observed_at) VALUES ('USD','NGN',-1,'test','2026-09-01 00:00:00.000')",
    "a negative FX rate");
$rejects("INSERT INTO users (public_id,email,display_name) VALUES ('01JDUPMAIL00000000000000000','ada@example.test','Impostor')",
    "a second global user on one email address");
$rejects("INSERT INTO memberships (public_id,tenant_id,user_id,reference) VALUES ('01JDUPREF000000000000000000',1,1,'MAM-0002')",
    "a second live membership for one user at one tenant");
$rejects("INSERT INTO memberships (public_id,tenant_id,user_id,reference) VALUES ('01JDUPREF200000000000000000',1,2,'MAM-0001')",
    "a second live membership reusing another learner's reference");
$rejects("INSERT INTO accountability_charges (public_id,tenant_id,subject_membership_id,reason,amount_minor,currency,status)
          VALUES ('01JBADCHG00000000000000000',1,1,'late',50000,'NGN','forgiven')",
    "an accountability charge status outside the §15 vocabulary");

// Soft delete must NOT block reuse of a natural key, but a LIVE duplicate must
// still be refused. These pull in opposite directions and the obvious spelling
// — scoping the unique key by a nullable `deleted_at` — satisfies only the
// first, because both engines treat NULLs as distinct in a unique index. That
// is why the scoping column is the NOT NULL `deleted_seq`.
$db->exec("UPDATE enrolments SET deleted_at = '2026-10-01 00:00:00.000', deleted_seq = id WHERE id = 1");
try {
    $db->exec("INSERT INTO enrolments (public_id,tenant_id,membership_id,cohort_id,status) VALUES ('01JREENROL0000000000000000',1,1,1,'active')");
    t_ok(true, 'a soft-deleted enrolment does not block re-enrolment in the same cohort');
} catch (Throwable $e) {
    t_ok(false, 'a soft-deleted enrolment does not block re-enrolment: ' . $e->getMessage());
}
$rejects("INSERT INTO enrolments (public_id,tenant_id,membership_id,cohort_id,status) VALUES ('01JDUPE20000000000000000000',1,1,1,'active')",
    "…and the re-enrolment itself is then the only live one");

// The same key can be recycled repeatedly — a learner who withdraws and returns
// three times is ordinary, and a sentinel that only tolerated one deletion
// would fail on the second.
$db->exec("UPDATE enrolments SET deleted_at = '2026-11-01 00:00:00.000', deleted_seq = id WHERE deleted_seq = 0");
try {
    $db->exec("INSERT INTO enrolments (public_id,tenant_id,membership_id,cohort_id,status) VALUES ('01JREENROL2000000000000000',1,1,1,'active')");
    t_ok(true, 'a natural key survives being recycled more than once');
} catch (Throwable $e) {
    t_ok(false, 'a natural key survives being recycled more than once: ' . $e->getMessage());
}
