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
$ins("INSERT INTO invoices (id,public_id,tenant_id,membership_id,enrolment_id,number,status,currency,total_minor,base_currency,base_total_minor,fx_rate,fx_source,issued_at)
      VALUES (1,?,1,1,1,'INV-0001','issued','NGN',12000000,'USD',7500,1600.00000000,'openexchange','2026-09-01 00:00:00.000')", [$ulid()]);
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
$rejects("INSERT INTO invoices (public_id,tenant_id,membership_id,number,currency,total_minor,base_currency)
          VALUES ('01JBADFX000000000000000000',1,1,'INV-BAD','NGN',5000,'USD')",
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
