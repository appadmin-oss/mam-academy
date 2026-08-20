<?php
/**
 * schema-v2 — the schema is executable, and its constraints actually bite.
 *
 * A schema file that has never been run is a document, not a schema. These
 * tests build the SQLite mirror in memory, drive a complete learner journey
 * through it — tenant → person → membership → catalogue → cohort → enrolment →
 * session → attendance → invoice → payment → ledger — and then attempt twenty-one
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
               'rubric_criteria', 'invoice_lines', 'payment_allocations', 'price_items',
               // Preferences are keyed to the USER, deliberately: someone who
               // needs a dyslexia-friendly face needs it at every academy.
               'user_preferences',
               // The glossary's tenant_id is NULLABLE (NULL = platform default),
               // so it is not a tenant-scoped table in the usual sense.
               'status_definitions'];
$missing = [];
foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (in_array($t, $tenantless, true)) continue;
    $cols = array_column($db->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('tenant_id', $cols, true)) $missing[] = $t;
}
t_eq(count($missing), 0, 'every domain table carries tenant_id' . ($missing ? ': ' . implode(', ', $missing) : ''));

// ── The NULL-in-a-unique-key trap, made impossible to reintroduce ───────────
// Both engines treat NULLs as DISTINCT inside a unique index, so a nullable
// column in a unique key silently exempts every row that leaves it NULL. That
// is sometimes exactly what is wanted ("no dedupe key = do not dedupe me") and
// sometimes a hole ("NULL tenant = the platform default", which then permits
// five conflicting defaults). It bit this schema TWICE — deleted_at scoping and
// the glossary's tenant_id — so every instance now has to be registered with
// the reason NULL is safe there. An unregistered one fails the build.
$nullableInUniqueOk = [
    'applications.reference'  => 'NULL = no reference issued yet; only issued ones must be unique',
    'cohorts.reference'       => 'NULL = unreferenced cohort; only named ones must be unique',
    'memberships.reference'   => 'NULL = staff/guardian with no learner reference',
    'notifications.dedupe_key'=> 'NULL = deliberately not deduped (a one-off message)',
    'payments.provider_ref'   => 'NULL = cash/manual payment with no provider reference; many are legitimate',
    'submissions.assignment_id' => 'NULL = work handed in against a module rather than a set assignment; the attempt key does not apply to it',
    'teachbacks.module_id'      => 'NULL = a teach-back not tied to a curriculum week; the one-per-module rule does not apply to it',
    'enrolment_results.term_id' => 'NULL = a result for the whole enrolment rather than one term; the per-term slot does not apply to it',
    'report_moderations.moderator_membership_id' => 'NULL = a sample drawn but not yet allocated to a moderator; many can queue',
    'invoice_credits.award_id' => 'NULL = a gift-aid credit posted with no award row behind it (a goodwill credit); many are legitimate',
    'accountability_charges.session_id' => 'NULL = a charge not tied to a session; two such can coexist',
];
$unregistered = [];
foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $nullable = [];
    foreach ($db->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!(int) $c['notnull']) $nullable[$c['name']] = true;
    }
    foreach ($db->query('PRAGMA index_list(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC) as $ix) {
        if (!(int) $ix['unique']) continue;
        foreach ($db->query('PRAGMA index_info(' . $ix['name'] . ')')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $name = (string) $col['name'];
            if ($name === '' || !isset($nullable[$name])) continue;
            if (!isset($nullableInUniqueOk["$t.$name"])) $unregistered[] = "$t.$name";
        }
    }
}
t_eq(count(array_unique($unregistered)), 0,
    'every nullable column inside a unique key is registered with why NULL is safe there'
    . ($unregistered ? ': ' . implode(', ', array_unique($unregistered)) : ''));

// MySQL coerces PRIMARY KEY columns to NOT NULL; SQLite does not. A nullable
// column inside a primary key therefore means two different things on the two
// engines — MySQL rejects the row, SQLite stores it and allows duplicates —
// which no amount of testing on one engine would reveal.
//
// A lone `INTEGER PRIMARY KEY AUTOINCREMENT` is excluded: it is the rowid alias,
// which PRAGMA reports as notnull=0 even though SQLite can never store a NULL
// there. Composite keys are where the real divergence lives.
$pkNullable = [];
foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $cols = $db->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC);
    $pk   = array_values(array_filter($cols, fn($c) => (int) $c['pk'] > 0));
    if (count($pk) === 1 && strtoupper((string) $pk[0]['type']) === 'INTEGER') continue;  // rowid alias
    foreach ($pk as $c) if (!(int) $c['notnull']) $pkNullable[] = $t . '.' . $c['name'];
}
t_eq(count($pkNullable), 0, 'no nullable column sits inside a PRIMARY KEY (the engines disagree there)'
    . ($pkNullable ? ': ' . implode(', ', $pkNullable) : ''));

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
$ins("INSERT INTO programme_courses (programme_id,course_id,level_id,level_scope,sort_order) VALUES (1,1,1,1,1)");
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
$ins("INSERT INTO invoices (id,public_id,tenant_id,membership_id,enrolment_id,number,status,currency,subtotal_minor,total_minor,base_currency,base_total_minor,fx_rate,fx_source,issued_at,due_at)
      VALUES (1,?,1,1,1,'INV-0001','issued','NGN',12000000,12000000,'USD',7500,1600.00000000,'openexchange','2026-09-01 00:00:00.000','2026-09-15 00:00:00.000')", [$ulid()]);
$ins("INSERT INTO invoice_lines (invoice_id,item_type,description,quantity,unit_amount_minor,line_total_minor) VALUES (1,'tuition','Piano L1 tuition',1,12000000,12000000)");
$ins("INSERT INTO payments (id,public_id,tenant_id,membership_id,provider,status,currency,amount_minor,base_currency,base_amount_minor,fx_rate,fx_source,received_at)
      VALUES (1,?,1,1,'paystack','succeeded','NGN',12000000,'USD',7500,1600.00000000,'openexchange','2026-09-02 10:00:00.000')", [$ulid()]);
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
$rejects("INSERT INTO invoices (public_id,tenant_id,membership_id,number,currency,subtotal_minor,total_minor,base_currency)
          VALUES ('01JBADFX000000000000000000',1,1,'INV-BAD','NGN',5000,5000,'USD')",
    "a cross-currency invoice with no frozen FX rate — the v1 bug that summed naira into dollars");
$rejects("INSERT INTO payments (public_id,tenant_id,membership_id,provider,currency,amount_minor,base_currency,status)
          VALUES ('01JBADFY000000000000000000',1,1,'paystack','NGN',5000,'USD','pending')",
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

t_group('schema-v2 — nobody is left guessing');

// LAYER H exists so every audience can be told where they stand. These assert
// the three questions v1 could not answer are answerable from data.

// 1 · "What does this word mean?" — one glossary, worded per audience.
$ins("INSERT INTO status_definitions (tenant_id,tenant_scope,entity_type,status,audience,label,meaning,next_step,tone,is_actionable,sort_order)
      VALUES (NULL,0,'module_progress','carried_forward','learner','Carried forward',
              'You have more time on this module — it moves with you to next week rather than counting against you.',
              'Have another go before your next class. Ask your instructor if anything is unclear.','progress',1,60)");
$ins("INSERT INTO status_definitions (tenant_id,tenant_scope,entity_type,status,audience,label,meaning,next_step,tone,is_actionable,sort_order)
      VALUES (NULL,0,'module_progress','carried_forward','guardian','Extra time given',
              'Their instructor has given more time on this module. It is a normal part of the pace, not a failure.',
              'Nothing is needed from you.','progress',0,60)");
$ins("INSERT INTO status_definitions (tenant_id,tenant_scope,entity_type,status,audience,label,meaning,next_step,tone,is_actionable,sort_order)
      VALUES (1,1,'module_progress','carried_forward','learner','Still working on it',
              'This one is still in progress and will carry into next week.',
              'Practise the two bars your instructor marked.','progress',1,60)");

$learner  = $db->query("SELECT label FROM status_definitions WHERE entity_type='module_progress' AND status='carried_forward' AND audience='learner' AND tenant_id IS NULL")->fetchColumn();
$guardian = $db->query("SELECT label FROM status_definitions WHERE entity_type='module_progress' AND status='carried_forward' AND audience='guardian' AND tenant_id IS NULL")->fetchColumn();
t_ok($learner !== $guardian, 'one status is worded differently for a learner and a guardian');

// The tenant override wins, which is what lets an academy use its own words
// without forking the vocabulary or shipping a deployment.
$resolved = $db->query("SELECT label FROM status_definitions
    WHERE entity_type='module_progress' AND status='carried_forward' AND audience='learner'
      AND (tenant_id = 1 OR tenant_id IS NULL)
    ORDER BY tenant_id IS NULL LIMIT 1")->fetchColumn();
t_eq((string) $resolved, 'Still working on it', 'a tenant override beats the platform default');

$rejects("INSERT INTO status_definitions (entity_type,status,audience,label,meaning,tone) VALUES ('enrolment','active','learner','Active','You are enrolled.','lime')",
    "a tone outside the shared palette — the same status green here and red there is worse than no colour");
$rejects("INSERT INTO status_definitions (entity_type,status,audience,label,meaning) VALUES ('enrolment','active','everyone','Active','You are enrolled.')",
    "an audience the renderers do not know about");
$rejects("INSERT INTO status_definitions (tenant_id,tenant_scope,entity_type,status,audience,label,meaning) VALUES (NULL,0,'module_progress','carried_forward','learner','Dup','…')",
    "a second platform definition of one status for one audience — two glossaries is no glossary");

// 2 · "What do I need to do?" — one list, deduped.
$ins("INSERT INTO action_items (public_id,tenant_id,membership_id,kind,title,detail,action_url,action_label,due_at,priority,is_blocking,dedupe_key)
      VALUES (?,1,1,'pay_invoice','Pay your September tuition',
              'Your place in Piano L1 is held until 30 September.','/portal/payments','Pay ₦120,000',
              '2026-09-30 23:59:00.000',10,0,'invoice:1')", [$ulid()]);
$ins("INSERT INTO action_items (public_id,tenant_id,membership_id,kind,title,action_url,action_label,priority,dedupe_key)
      VALUES (?,1,1,'sign_consent','Confirm photo permissions for the recital','/portal/consent','Review',50,'consent:recital-2026')", [$ulid()]);
t_eq((int) $db->query("SELECT COUNT(*) FROM action_items WHERE membership_id=1 AND completed_at IS NULL AND dismissed_at IS NULL")->fetchColumn(),
    2, 'the portal can say "you have 2 things to do" in one query');
t_eq((string) $db->query("SELECT title FROM action_items WHERE membership_id=1 AND completed_at IS NULL ORDER BY priority, due_at LIMIT 1")->fetchColumn(),
    'Pay your September tuition', 'and the most urgent one sorts first');

$rejects("INSERT INTO action_items (public_id,tenant_id,membership_id,kind,title,dedupe_key) VALUES ('01JDUPACT00000000000000000',1,1,'pay_invoice','Pay your September tuition','invoice:1')",
    "a nightly sweep stacking a second copy of the same to-do — a duplicated list is one nobody reads");

// Resolving it frees the key: the same obligation recurs next term.
$db->exec("UPDATE action_items SET completed_at = '2026-09-20 10:00:00.000', resolved_seq = id WHERE dedupe_key = 'invoice:1'");
try {
    $db->exec("INSERT INTO action_items (public_id,tenant_id,membership_id,kind,title,dedupe_key) VALUES ('01JOCTOBER0000000000000000',1,1,'pay_invoice','Pay your October tuition','invoice:1')");
    t_ok(true, 'and the same obligation can recur once the first is met');
} catch (Throwable $e) {
    t_ok(false, 'the same obligation can recur once the first is met: ' . $e->getMessage());
}

// 3 · "Why did that happen?" — no negative outcome without a reason.
$rejects("INSERT INTO applications (public_id,tenant_id,type,status) VALUES ('01JREJECT00000000000000000',1,'programme','rejected')",
    "a rejected application with no reason — the most confusing message an academy can send");
$rejects("UPDATE enrolments SET status = 'withdrawn' WHERE id = 1",
    "a withdrawal with no reason recorded");
$rejects("UPDATE class_sessions SET status = 'cancelled' WHERE id = 1",
    "a class vanishing from the timetable with no reason");
$rejects("UPDATE invoices SET voided_at = '2026-09-05 00:00:00.000' WHERE id = 1",
    "an invoice voided with no reason the payer can be shown");
$rejects("INSERT INTO payments (public_id,tenant_id,membership_id,provider,status,currency,amount_minor,base_currency,base_amount_minor,fx_rate)
          VALUES ('01JFAILPAY0000000000000000',1,1,'paystack','failed','NGN',5000,'USD',3,1600.0)",
    "a failed payment with nothing to tell the payer — they will just retry the same card");
$rejects("INSERT INTO accountability_charges (public_id,tenant_id,subject_membership_id,reason,amount_minor,currency,status)
          VALUES ('01JWAIVE000000000000000000',1,1,'late',50000,'NGN','waived')",
    "a charge decision with no note — §15 requires the student can read the outcome");

// The same statements SUCCEED once the reason is supplied, so the constraint
// blocks silence, not the operation.
$db->exec("UPDATE class_sessions SET status = 'cancelled', cancellation_reason = 'Instructor unwell — rescheduled to 17 Sept' WHERE id = 1");
t_eq((string) $db->query("SELECT cancellation_reason FROM class_sessions WHERE id=1")->fetchColumn(),
    'Instructor unwell — rescheduled to 17 Sept', 'a cancellation WITH a reason is accepted');

// 4 · Preferences reach the server, so they apply to email and not just the SPA.
$ins("INSERT INTO user_preferences (user_id,locale,display_currency,text_scale_pct,dyslexia_font,digest_frequency,channel_email)
      VALUES (1,'yo','NGN',150,1,'daily',1)");
$p = $db->query('SELECT * FROM user_preferences WHERE user_id=1')->fetch(PDO::FETCH_ASSOC);
t_eq((string) $p['display_currency'], 'NGN', 'money is shown in the currency the reader thinks in');
t_eq((int) $p['text_scale_pct'], 150, 'and the text scale is stored server-side, so email honours it too');
$rejects("INSERT INTO user_preferences (user_id,text_scale_pct) VALUES (2,500)",
    "a text scale that would make the interface unreadable and unrecoverable");
$rejects("INSERT INTO user_preferences (user_id,digest_frequency) VALUES (2,'hourly')",
    "a digest cadence no worker implements — a preference that silently does nothing is worse than none");

// 5 · A message is said once, and its button says what it does.
$ins("INSERT INTO notifications (tenant_id,membership_id,type,title,action_label,severity,dedupe_key,expires_at)
      VALUES (1,1,'invoice.due','Your September tuition is due Friday','Pay ₦120,000','attention','invoice:1:due','2026-10-01 00:00:00.000')");
$rejects("INSERT INTO notifications (tenant_id,membership_id,type,title,dedupe_key) VALUES (1,1,'invoice.due','Your September tuition is due Friday','invoice:1:due')",
    "the nightly sweep re-notifying — a full inbox costs the reader the ONE message that mattered");
$rejects("INSERT INTO notifications (tenant_id,membership_id,type,title,severity) VALUES (1,1,'x','y','urgent!!')",
    "a severity outside the palette every surface renders");

// 6 · A guardian's scope is one phrase both sides can be shown.
$ins("INSERT INTO users (id,public_id,email,full_name) VALUES (3,?,'parent@example.test','Ngozi Obi')", [$ulid()]);
$ins("INSERT INTO memberships (id,public_id,tenant_id,user_id,reference,stage) VALUES (3,?,1,3,'MAM-G001','active')", [$ulid()]);
$ins("INSERT INTO guardianships (tenant_id,guardian_membership_id,learner_membership_id,relationship,scope,is_primary)
      VALUES (1,3,1,'mother','standard',1)");
t_eq((string) $db->query('SELECT scope FROM guardianships WHERE learner_membership_id=1')->fetchColumn(), 'standard',
    'a guardian relationship carries a named scope, not only free-form JSON');
$rejects("INSERT INTO guardianships (tenant_id,guardian_membership_id,learner_membership_id,scope) VALUES (1,3,1,'everything')",
    "a scope neither the learner nor the guardian could be shown in a sentence");

// 7 · Everything a person might quote on the phone has a reference.
foreach (['memberships' => 'reference', 'applications' => 'reference', 'cohorts' => 'reference',
          'invoices' => 'number'] as $table => $col) {
    $cols = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    t_ok(in_array($col, $cols, true), "$table carries a human reference a caller can read out");
}

t_group('schema-v2 — the guardian layer carried over in v2 idiom');

// A ratified report states its band, its coverage, and who ratified it. The band
// is the only score in this layer — there is no column for the raw figure.
$treportCols = array_column($db->query('PRAGMA table_info(termly_reports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['raw_score', 'score', 'mastery_pct', 'mean'] as $forbidden) {
    t_ok(!in_array($forbidden, $treportCols, true),
        "termly_reports has no `$forbidden` column — a guardian-facing table with room for the precise figure is an invitation to print it");
}

$ins("INSERT INTO termly_reports (id,public_id,tenant_id,membership_id,enrolment_id,course_id,level_id,term_label,status,
        band_low,band_high,coverage_evidenced,coverage_total,coverage_weight_pct,ratified_by,ratified_at)
      VALUES (1,?,1,1,1,1,1,'TONI 2026','ratified',70,79,8,11,72,2,'2026-12-10 09:00:00.000')", [$ulid()]);
t_eq((int) $db->query('SELECT band_high - band_low FROM termly_reports WHERE id=1')->fetchColumn(), 9,
    'a band is ten points wide');

$rejects("INSERT INTO termly_reports (public_id,tenant_id,membership_id,term_label,coverage_evidenced,coverage_total,coverage_weight_pct,band_low,band_high)
          VALUES ('01JBADBAND00000000000000000',1,1,'TONI 2026',8,11,72,70,74)",
    "a five-point band — a narrower band claims a precision the coverage floor exists to withhold");
$ins("INSERT INTO termly_reports (public_id,tenant_id,membership_id,term_label,coverage_evidenced,coverage_total,coverage_weight_pct,band_low,band_high)
      VALUES ('01JTOPBAND00000000000000000',1,1,'TONI 2026',11,11,100,100,100)", [] );
t_ok(true, 'a perfect score clamps to (100,100) — the one legitimate zero-width band');
$rejects("INSERT INTO termly_reports (public_id,tenant_id,membership_id,term_label,coverage_evidenced,coverage_total,coverage_weight_pct,status)
          VALUES ('01JNORATIFY0000000000000000',1,1,'TONI 2026',8,11,72,'ratified')",
    "a ratified report with nobody named as having ratified it");
$rejects("INSERT INTO termly_reports (public_id,tenant_id,membership_id,term_label,coverage_evidenced,coverage_total,coverage_weight_pct)
          VALUES ('01JBADCOV000000000000000000',1,1,'TONI 2026',12,11,72)",
    "more competencies evidenced than exist");

// An unevidenced competency is a ROW, not an absence and not a zero.
$ins("INSERT INTO competencies (id,tenant_id,code,name,weight_pct) VALUES (1,1,'theory','Score/Notation Accuracy',10)");
// competency_id is NOT NULL here on purpose — a criterion on a report resolves to
// the same competency the mastery score weights, which is the thing v1's
// label-matching could not do.
$ins("INSERT INTO termly_report_criteria (tenant_id,report_id,competency_id,label,level,pct,weight_pct)
      VALUES (1,1,1,'Score/Notation Accuracy','insufficient',NULL,10)");
t_ok($db->query("SELECT 1 FROM termly_report_criteria WHERE report_id=1 AND level='insufficient' AND pct IS NULL")->fetchColumn() !== false,
    'an unevidenced competency records a NULL pct — a zero there reports an unassessed beginner as failing');

// The appeal clock. Both timestamps come from one writer at one precision.
$openedAt = '2026-12-11 09:00:00.000';
$ins("INSERT INTO mark_appeals (public_id,tenant_id,report_id,membership_id,reason,status,opened_at)
      VALUES (?,1,1,3,'The recital mark does not match the video','open',?)", [$ulid(), $openedAt]);
$ins("INSERT INTO mark_appeals (public_id,tenant_id,report_id,membership_id,reason,status,outcome_note,closed_at,opened_at)
      VALUES (?,1,1,3,'Second look','declined','Moderation sample confirmed the mark','2026-12-11 09:20:00.000',?)", [$ulid(), $openedAt]);
t_ok(true, 'an appeal declined twenty minutes after it was raised writes — the v1 timestamp-precision bug');
$rejects("INSERT INTO mark_appeals (public_id,tenant_id,report_id,membership_id,reason,status,closed_at,opened_at)
          VALUES ('01JNONOTE000000000000000000',1,1,3,'why','upheld','2026-12-12 09:00:00.000','$openedAt')",
    "an UPHELD appeal closed with no note — a mark overturned and nothing saying why");
$rejects("INSERT INTO mark_appeals (public_id,tenant_id,report_id,membership_id,reason,status,outcome_note,closed_at,opened_at)
          VALUES ('01JBACKDATE00000000000000',1,1,3,'why','upheld','ok','2026-12-10 09:00:00.000','$openedAt')",
    "an appeal closed before it was opened");

// Only a guardian cancellation may cost the family's allowance.
$ins("INSERT INTO makeup_credits (public_id,tenant_id,membership_id,year_label,reason,counts_against_allowance,expires_on)
      VALUES (?,1,1,'2026/27','guardian_notice',1,'2027-07-31')", [$ulid()]);
foreach (['teacher_cancellation', 'academy_closure', 'goodwill'] as $free) {
    $rejects("INSERT INTO makeup_credits (public_id,tenant_id,membership_id,year_label,reason,counts_against_allowance,expires_on)
              VALUES ('01JFREE" . strtoupper(substr(md5($free), 0, 20)) . "',1,1,'2026/27','$free',1,'2027-07-31')",
        "a $free credit charged to the family's allowance — the allowance limits how often a FAMILY cancels");
}
$mkCols = array_column($db->query('PRAGMA table_info(makeup_credits)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['amount_minor', 'currency', 'transfer_to_membership_id'] as $forbidden) {
    t_ok(!in_array($forbidden, $mkCols, true),
        "makeup_credits has no `$forbidden` — a make-up credit is a lesson, not money and not transferable");
}

// The four kinds of relief stay separable, and they add up.
$invCols = array_column($db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['waiver_minor', 'aid_minor', 'sibling_minor', 'discount_minor'] as $stage) {
    t_ok(in_array($stage, $invCols, true), "invoices separates `$stage` — one flat discount cannot say what the books say happened");
}
$rejects("INSERT INTO invoices (public_id,tenant_id,membership_id,number,currency,base_currency,subtotal_minor,waiver_minor,aid_minor,total_minor)
          VALUES ('01JBADRELIEF000000000000000',1,1,'INV-R1','USD','USD',10000,3000,2000,10000)",
    "a total that ignores the relief applied to it");
$rejects("INSERT INTO invoices (public_id,tenant_id,membership_id,number,currency,base_currency,subtotal_minor,aid_minor,total_minor)
          VALUES ('01JOVERRELIEF00000000000000',1,1,'INV-R2','USD','USD',10000,12000,-2000)",
    "more relief than was ever billed — a negative total is not a refund, a refund is its own row");

// A student is never a participant in a guardian thread.
$roleCheck = (string) $db->query("SELECT sql FROM sqlite_master WHERE name='guardian_thread_participants'")->fetchColumn();
t_ok(strpos($roleCheck, "'student'") === false && strpos($roleCheck, "'learner'") === false,
    'the guardian-thread participant role vocabulary admits no learner — there is no signature through which a student can be added');

t_group('schema-v2 — assessment and progress');

// Everything in this layer hangs off the ENROLMENT, so one person taking piano
// and voice has two independent records rather than one interleaved one.
foreach (['submissions', 'assessments', 'teachbacks', 'enrolment_results'] as $table) {
    $cols = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    t_ok(in_array('enrolment_id', $cols, true) && !in_array('student_id', $cols, true),
        "$table is keyed on the enrolment, not on the student");
}

$ins("INSERT INTO assessment_kinds (id,tenant_id,code,name,weight_pct) VALUES (1,1,'recital','Recital',40)");
$ins("INSERT INTO assignment_groups (id,public_id,tenant_id,cohort_id,title) VALUES (1,?,1,1,'Term 1 practice')", [$ulid()]);
$ins("INSERT INTO assignments (id,public_id,tenant_id,group_id,cohort_id,title,points_max,due_at,due_timezone,assessment_kind_id)
      VALUES (1,?,1,1,1,'Play the C major scale, hands together',20,'2026-10-03 17:00:00.000','Africa/Lagos',1)", [$ulid()]);
$rejects("INSERT INTO assignments (public_id,tenant_id,title) VALUES ('01JNOTARGET0000000000000000',1,'Work set at nobody')",
    "an assignment with neither a cohort nor an enrolment to hand it in — work nobody can do");
$rejects("INSERT INTO assignments (public_id,tenant_id,cohort_id,enrolment_id,title) VALUES ('01JBOTHTARGET00000000000000',1,1,1,'Both')",
    "an assignment claiming to be both a cohort's and one learner's");
$rejects("INSERT INTO assignments (public_id,tenant_id,cohort_id,title,due_at) VALUES ('01JNOTZ00000000000000000000',1,1,'Due when?','2026-10-03 17:00:00.000')",
    "a deadline with no timezone — a diaspora learner and a Lagos learner would read different Fridays");

$ins("INSERT INTO submissions (id,public_id,tenant_id,enrolment_id,assignment_id,status,submitted_at)
      VALUES (1,?,1,1,1,'submitted','2026-10-02 20:11:00.000')", [$ulid()]);
$rejects("INSERT INTO submissions (public_id,tenant_id,enrolment_id,assignment_id,attempt_no,status,submitted_at)
          VALUES ('01JDUPATTEMPT000000000000',1,1,1,1,'submitted','2026-10-02 21:00:00.000')",
    "a second attempt numbered the same as the first — the mark awarded against attempt 1 must stay readable");
$ins("INSERT INTO submissions (public_id,tenant_id,enrolment_id,assignment_id,attempt_no,status,submitted_at)
      VALUES (?,1,1,1,2,'submitted','2026-10-02 21:00:00.000')", [$ulid()]);
$rejects("INSERT INTO submissions (public_id,tenant_id,enrolment_id,assignment_id,status) VALUES ('01JNOWHEN000000000000000000',1,1,1,'submitted')",
    "submitted work with no time on it — lateness and the marking clock are both measured from it");

// A mark has an author. This is what an appeal is answered from.
$ins("INSERT INTO submission_markers (tenant_id,submission_id,marker_membership_id,role) VALUES (1,1,2,'primary')");
$ins("INSERT INTO assessments (id,public_id,tenant_id,enrolment_id,assessment_kind_id,submission_id,rubric_id,rubric_version,
        points_awarded,points_max,pct,status,marker_membership_id,marked_at)
      VALUES (1,?,1,1,1,1,NULL,NULL,16,20,80,'marked',2,'2026-10-03 09:00:00.000')", [$ulid()]);
$rejects("INSERT INTO assessments (public_id,tenant_id,enrolment_id,points_awarded,points_max,status)
          VALUES ('01JNOMARKER00000000000000',1,1,16,20,'marked')",
    "a marked assessment with nobody named as having marked it");
$rejects("INSERT INTO assessments (public_id,tenant_id,enrolment_id,points_awarded,points_max,status,marker_membership_id,marked_at)
          VALUES ('01JOVERMAX000000000000000',1,1,21,20,'marked',2,'2026-10-03 09:00:00.000')",
    "more points awarded than the assessment was out of");
$rejects("INSERT INTO assessments (public_id,tenant_id,enrolment_id,points_awarded,points_max,status,marker_membership_id,marked_at)
          VALUES ('01JNOTRELEASED000000000000',1,1,16,20,'released',2,'2026-10-03 09:00:00.000')",
    "a released mark with no release time — released is the only status a learner may be shown");

// The mark-per-criterion table `scores_json` should always have been.
$ins("INSERT INTO assessment_marks (tenant_id,assessment_id,criterion_id,competency_id,label,points_awarded,points_max)
      VALUES (1,1,NULL,1,'Score/Notation Accuracy',8,10)");
$ins("INSERT INTO assessment_marks (tenant_id,assessment_id,competency_id,label,points_max,is_insufficient)
      VALUES (1,1,1,'Ensemble',10,1)");
$rejects("INSERT INTO assessment_marks (tenant_id,assessment_id,competency_id,label,points_awarded,points_max,is_insufficient)
          VALUES (1,1,1,'Sight-reading',0,10,1)",
    "a criterion marked insufficient AND scored zero — the two are different statements and only one can be true");
t_ok((int) $db->query("SELECT count(*) FROM assessment_marks WHERE competency_id IS NOT NULL")->fetchColumn() === 2,
    'every mark carries its competency as a foreign key — the mastery score no longer matches criteria by label');

// The five teach-back dimensions share one scale.
$ins("INSERT INTO teachbacks (public_id,tenant_id,enrolment_id,module_id,status,reviewer_membership_id,decision,
        accuracy,vocabulary,explanation,application,confidence,reviewed_at)
      VALUES (?,1,1,1,'reviewed',2,'developing',4,3,4,3,5,'2026-10-04 10:00:00.000')", [$ulid()]);
$rejects("INSERT INTO teachbacks (public_id,tenant_id,enrolment_id,module_id,accuracy) VALUES ('01JOFFSCALE00000000000000',1,1,1,80)",
    "a teach-back dimension on a 100-point scale beside four on a 5-point one");
$rejects("INSERT INTO teachbacks (public_id,tenant_id,enrolment_id,status,reviewer_membership_id,reviewed_at)
          VALUES ('01JNODECISION0000000000000',1,1,'reviewed',2,'2026-10-04 10:00:00.000')",
    "a reviewed teach-back with no decision — the learner is told it was looked at and nothing else");

// A finalised result is signed; a certificate that stops verifying says why.
$ins("INSERT INTO enrolment_results (public_id,tenant_id,enrolment_id,total_pct,grade,outcome,status,finalised_by,finalised_at)
      VALUES (?,1,1,78.5,'B','passed','finalised',2,'2026-12-12 12:00:00.000')", [$ulid()]);
$rejects("INSERT INTO enrolment_results (public_id,tenant_id,enrolment_id,total_pct,status)
          VALUES ('01JUNSIGNED000000000000000',1,1,78.5,'finalised')",
    "a finalised result nobody signed");
$ins("INSERT INTO certificates (public_id,tenant_id,membership_id,enrolment_id,kind,reference,verification_code)
      VALUES (?,1,1,1,'level','MAM-CERT-0001','A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4')", [$ulid()]);
$rejects("INSERT INTO certificates (public_id,tenant_id,membership_id,kind,reference,verification_code,status,revoked_at)
          VALUES ('01JNOREASON000000000000000',1,1,'level','MAM-CERT-0002','B1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4','revoked','2026-12-13 00:00:00.000')",
    "a revoked certificate with no stated reason — indistinguishable from a database fault to the holder");
$rejects("INSERT INTO certificates (public_id,tenant_id,membership_id,kind,reference,verification_code)
          VALUES ('01JDUPCODE0000000000000000',1,1,'level','MAM-CERT-0003','A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4')",
    "two certificates sharing a verification code — a verifier has no tenant to disambiguate with");

// A rejected curriculum proposal is revisable, so it says what was wrong.
$rejects("INSERT INTO module_proposals (public_id,tenant_id,course_id,week_no,title,status,reviewed_by,reviewed_at)
          VALUES ('01JNONOTE20000000000000000',1,1,3,'Week 3: swing feel','rejected',2,'2026-10-05 09:00:00.000')",
    "a rejected proposal with no review note — the proposer is a colleague who has to revise it");

// Nothing in v2 ranks learners against each other.
$ranking = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND (name LIKE '%leaderboard%' OR name LIKE '%ranking%')")->fetchAll(PDO::FETCH_COLUMN);
t_eq(count($ranking), 0, 'no table in v2 orders learners against each other — the 2026-08 redesign refuses a cohort ranking'
    . ($ranking ? ': ' . implode(', ', $ranking) : ''));

t_group('schema-v2 — teaching operations');

// The two v1 tables that deliberately have no table here.
$allTables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
t_ok(!in_array('instructor_profiles', $allTables, true),
    'no instructor_profiles table: four optional strings live in custom_field_values on the membership, and '
    . 'two homes for one fact is how they drift');
t_ok(!in_array('reschedule_events', $allTables, true),
    'no reschedule_events table: v2 has one stage machine (lifecycle_events)');
t_ok(in_array('session_progress_reports', $allTables, true) && !in_array('progress_reports', $allTables, true),
    'the two-hour lesson note is session_progress_reports — the bare name has already been confused with '
    . 'the board-ratified termly report');

$ins("INSERT INTO instructor_availability (tenant_id,membership_id,day_of_week,start_time,end_time,timezone)
      VALUES (1,2,5,'16:00:00','18:00:00','Africa/Lagos')");
$rejects("INSERT INTO instructor_availability (tenant_id,membership_id,day_of_week,start_time,end_time) VALUES (1,2,7,'16:00:00','18:00:00')",
    "an eighth day of the week");
$rejects("INSERT INTO instructor_availability (tenant_id,membership_id,day_of_week,start_time,end_time) VALUES (1,2,4,'18:00:00','16:00:00')",
    "a window that ends before it starts");

$ins("INSERT INTO instructor_courses (tenant_id,membership_id,course_id,level_id,level_scope) VALUES (1,2,1,NULL,0)");
$rejects("INSERT INTO instructor_courses (tenant_id,membership_id,course_id,level_id,level_scope) VALUES (1,2,1,NULL,0)",
    "a second 'may teach every level of this course' row — the companion level_scope column is what stops "
    . "two NULL levels reading as distinct");
$ins("INSERT INTO instructor_courses (tenant_id,membership_id,course_id,level_id,level_scope) VALUES (1,2,1,1,1)");
t_ok(true, 'while the same instructor at a NAMED level is a different, legitimate row');

// The instructor's clock is the official record of whether a lesson happened.
$ins("INSERT INTO instructor_clockins (tenant_id,session_id,membership_id,clocked_in_at,clocked_out_at,minutes_late,minutes_present,status)
      VALUES (1,1,2,'2026-10-02 16:04:00.000','2026-10-02 17:02:00.000',4,58,'late')");
$rejects("INSERT INTO instructor_clockins (tenant_id,session_id,membership_id,clocked_in_at,clocked_out_at,status)
          VALUES (1,1,2,'2026-10-02 16:04:00.000','2026-10-02 17:02:00.000','present')",
    "a second clock-in for one person on one session in one role — that is an EDIT, not another attendance");
$rejects("INSERT INTO instructor_clockins (tenant_id,session_id,membership_id,role,clocked_in_at,clocked_out_at,status)
          VALUES (1,1,2,'substitute','2026-10-02 17:02:00.000','2026-10-02 16:04:00.000','present')",
    "clocking out before clocking in");
$rejects("INSERT INTO instructor_clockins (tenant_id,session_id,membership_id,role,status) VALUES (1,1,2,'assistant','present')",
    "an instructor recorded present with no time — present means they were in the room at a stated moment");

// A fine is a RECORD. Nothing here deducts anything.
$fineCols = array_column($db->query('PRAGMA table_info(instructor_fines)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['deducted_at', 'payroll_run_id', 'ledger_entry_id'] as $forbidden) {
    t_ok(!in_array($forbidden, $fineCols, true),
        "instructor_fines has no `$forbidden` — Labour Act s.5(1) bars deducting a fine from wages without "
        . "an authorised labour officer's written consent, so the deduction is not this system's to make");
}
$ins("INSERT INTO instructor_fines (public_id,tenant_id,membership_id,session_id,reason,amount_minor,currency,base_currency,status,resolved_by,resolved_at)
      VALUES (?,1,2,1,'late_progress_report',500000,'NGN','NGN','upheld',2,'2026-10-04 09:00:00.000')", [$ulid()]);
$rejects("INSERT INTO instructor_fines (public_id,tenant_id,membership_id,amount_minor,currency,base_currency,status,resolved_by,resolved_at)
          VALUES ('01JWAIVENOTE00000000000000',1,2,500000,'NGN','NGN','waived',2,'2026-10-04 09:00:00.000')",
    "a waived fine with no reason — a decision nobody can account for");
$rejects("INSERT INTO instructor_fines (public_id,tenant_id,membership_id,amount_minor,currency,base_currency)
          VALUES ('01JFINEFX000000000000000000',1,2,500000,'NGN','USD')",
    "a fine in one currency with no frozen rate to the books' currency");

// An invitation is a credential. The table holds no usable token.
$invCols2 = array_column($db->query('PRAGMA table_info(faculty_invites)')->fetchAll(PDO::FETCH_ASSOC), 'name');
t_ok(in_array('nonce_hash', $invCols2, true) && !in_array('nonce', $invCols2, true),
    'faculty_invites stores a HASH, not the nonce: a leaked table of live nonces is a set of credentials');
$ins("INSERT INTO faculty_invites (id,public_id,tenant_id,email,name,status,nonce_hash,expires_at,invited_by,invited_at)
      VALUES (1,?,1,'candidate@example.test','A Candidate','sent',?, '2026-10-10 00:00:00.000',2,'2026-10-03 09:00:00.000')",
    [$ulid(), str_repeat('a', 64)]);
$rejects("INSERT INTO faculty_invites (public_id,tenant_id,email,status) VALUES ('01JNOEXPIRY0000000000000000',1,'x@example.test','sent')",
    "a sent invitation with nothing to verify and no expiry — a permanent credential");

$ins("INSERT INTO instructor_applications (public_id,tenant_id,invite_id,email,full_name,status)
      VALUES (?,1,1,'candidate@example.test','A Candidate','submitted')", [$ulid()]);
$rejects("INSERT INTO instructor_applications (public_id,tenant_id,email,status,decided_by,decided_at)
          VALUES ('01JAPPOINTED000000000000000',1,'x@example.test','appointed',2,'2026-10-05 09:00:00.000')",
    "an appointment that points at no account — the hire and the teacher would be two unconnected records");

// Provider evidence sits beside the portal clock, never inside attendance.
$ins("INSERT INTO meeting_participants (tenant_id,session_id,subject_type,membership_id,display_name,joined_at,left_at,minutes,joined_at_is_derived)
      VALUES (1,1,'learner',1,'Ada O.','2026-10-02 16:01:00.000','2026-10-02 17:00:00.000',59,1)");
$mpCols = array_column($db->query('PRAGMA table_info(meeting_participants)')->fetchAll(PDO::FETCH_ASSOC), 'name');
t_ok(in_array('joined_at_is_derived', $mpCols, true),
    'a joined_at derived by subtraction says so: Google Reports emits only the leave time and a duration, '
    . 'and a derived instant presented as an observation is evidence that loses a dispute');
$rejects("INSERT INTO meeting_participants (tenant_id,session_id,joined_at,left_at) VALUES (1,1,'2026-10-02 17:00:00.000','2026-10-02 16:00:00.000')",
    "leaving before joining");

// The two-hour note, and the deadline stored beside it.
$ins("INSERT INTO session_progress_reports (public_id,tenant_id,session_id,enrolment_id,instructor_membership_id,narrative,due_at,submitted_at,minutes_late)
      VALUES (?,1,1,1,2,'Scales secure; slow the left hand.','2026-10-02 19:00:00.000','2026-10-02 18:10:00.000',0)", [$ulid()]);
$rejects("INSERT INTO session_progress_reports (public_id,tenant_id,session_id,enrolment_id,instructor_membership_id)
          VALUES ('01JDUPSPREP0000000000000000',1,1,1,2)",
    "a second live report for one learner on one session");

// Moving a lesson: server-priced, decided by a human, and the payment is named.
$ins("INSERT INTO reschedule_requests (id,public_id,tenant_id,requester_membership_id,cohort_id,status,session_count,fee_minor,currency,base_currency,payment_id,paid_at,decided_by,decided_at,applied_at)
      VALUES (1,?,1,1,1,'applied',2,1600000,'NGN','NGN',1,'2026-10-03 09:00:00.000',2,'2026-10-03 10:00:00.000','2026-10-03 10:05:00.000')", [$ulid()]);
$rejects("INSERT INTO reschedule_requests (public_id,tenant_id,requester_membership_id,cohort_id,status,fee_minor,currency,base_currency,decision_note,decided_by,decided_at)
          VALUES ('01JRESCHFX00000000000000000',1,1,1,'approved',1000,'USD','NGN','ok',2,'2026-10-03 10:00:00.000')",
    "a fee quoted in dollars with no frozen rate into the books' currency");
$rejects("INSERT INTO reschedule_requests (public_id,tenant_id,requester_membership_id,cohort_id,status,fee_minor,currency,base_currency,decided_by,decided_at)
          VALUES ('01JRESCHNOWHY000000000000',1,1,1,'declined',1600000,'NGN','NGN',2,'2026-10-03 10:00:00.000')",
    "a refusal with no reason — the family paid a fee to ask");
$rejects("INSERT INTO reschedule_requests (public_id,tenant_id,requester_membership_id,cohort_id,status,fee_minor,currency,base_currency,paid_at)
          VALUES ('01JRESCHGHOSTPAY000000000',1,1,1,'pending_approval',1600000,'NGN','NGN','2026-10-03 09:00:00.000')",
    "a fee marked paid that names no payment — reconciling by amount and date is how money goes missing");
$rejects("INSERT INTO reschedule_requests (public_id,tenant_id,requester_membership_id,cohort_id,status,fee_minor,currency,base_currency,is_waived)
          VALUES ('01JRESCHWAIVENOWHY0000000',1,1,1,'pending_approval',0,'NGN','NGN',1)",
    "a waived fee with no reason");
$ins("INSERT INTO reschedule_items (tenant_id,request_id,session_id,old_scheduled_at,new_scheduled_at,is_applied,applied_at)
      VALUES (1,1,1,'2026-10-02 16:00:00.000','2026-10-09 16:00:00.000',1,'2026-10-03 10:05:00.000')");
$rejects("INSERT INTO reschedule_items (tenant_id,request_id,session_id,new_scheduled_at,is_applied) VALUES (1,1,1,'2026-10-16 16:00:00.000',1)",
    "the same session moved twice inside one request");

// The queue, and the two things v1 let it claim without evidence.
$ins("INSERT INTO cohort_waitlist (tenant_id,cohort_id,membership_id,position,status,notified_at,promoted_enrolment_id)
      VALUES (1,1,3,1,'promoted','2026-10-01 09:00:00.000',1)");
$rejects("INSERT INTO cohort_waitlist (tenant_id,cohort_id,membership_id,position,status,notified_at)
          VALUES (1,1,2,2,'promoted','2026-10-01 09:00:00.000')",
    "an entry marked promoted with no enrolment behind it — the v1 bug where promotions enrolled nobody");
$rejects("INSERT INTO cohort_waitlist (tenant_id,cohort_id,membership_id,position,status) VALUES (1,1,2,3,'offered')",
    "a seat offered without telling the family — the notified_at column existed in v1 and nothing wrote it");
$rejects("INSERT INTO cohort_waitlist (tenant_id,cohort_id,membership_id,position) VALUES (1,1,3,4)",
    "a second live place in one queue for one person");

// Shared material: something to open, and somewhere to belong.
$ins("INSERT INTO learning_resources (id,public_id,tenant_id,cohort_id,title,kind,url,visibility,version)
      VALUES (1,?,1,1,'C major scale sheet','score','https://files.example.test/cmaj.pdf','cohort',1)", [$ulid()]);
$ins("INSERT INTO learning_resources (public_id,tenant_id,cohort_id,title,kind,url,visibility,version,supersedes_id)
      VALUES (?,1,1,'C major scale sheet','score','https://files.example.test/cmaj-v2.pdf','cohort',2,1)", [$ulid()]);
t_ok(true, 'a new version is a NEW ROW that supersedes the old one, so a learner can still be shown what they were given');
$rejects("INSERT INTO learning_resources (public_id,tenant_id,cohort_id,title,kind,visibility) VALUES ('01JLRESNOURL000000000000',1,1,'Nothing to open','link','cohort')",
    "a resource with neither a URL nor a stored file — a title in a list that disappoints whoever clicks it");
$rejects("INSERT INTO learning_resources (public_id,tenant_id,title,kind,url,visibility) VALUES ('01JLRESNOSCOPE00000000000',1,'Orphan','link','https://x.test','cohort')",
    "a cohort-visible resource attached to no cohort, course or module — v1 allowed it and nobody could reach it");
