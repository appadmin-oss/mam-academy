<?php
/** Application invoice lifecycle — owes/paid, status states, the auto-invoice +
 *  expiry sweep, idempotency, and the master switch. */
declare(strict_types=1);
t_group('invoice lifecycle');

$cid = mam_db_insert('customers', ['email' => 't_inv@x.dev', 'first_name' => 'Inv', 'last_name' => 'Oice']);

function _inv_app(int $cid, string $ref, int $daysAgo, array $extra = []): int {
    $created = date('Y-m-d H:i:s', time() - $daysAgo * 86400);
    return mam_db_insert('applications', array_merge([
        'reference' => $ref, 'customer_id' => $cid, 'pathway' => 'standard',
        'status' => 'submitted', 'total_usd' => 120, 'created_at' => $created, 'updated_at' => $created,
    ], $extra));
}
function _inv_row(int $id): array { return mam_db_row('SELECT * FROM applications WHERE id = ?', [$id]) ?? []; }
$rnd = fn() => bin2hex(random_bytes(3));

$aPaid   = _inv_app($cid, 'INV-PAID-' . $rnd(), 20, ['booking_fee_paid_at' => date('Y-m-d H:i:s')]);
$aRecent = _inv_app($cid, 'INV-NEW-'  . $rnd(), 0);
$aDue    = _inv_app($cid, 'INV-DUE-'  . $rnd(), 5);
$aOld    = _inv_app($cid, 'INV-OLD-'  . $rnd(), 20);

// owes / paid
t_ok(!mam_invoice_owes(_inv_row($aPaid)),  'a settled app (booking fee paid) owes nothing');
t_ok(mam_invoice_owes(_inv_row($aRecent)), 'a fresh unpaid submitted app owes its processing fee');

// status states (pre-sweep)
t_eq(mam_invoice_status(_inv_row($aPaid))['state'],   'paid',   'settled app → state paid');
t_eq(mam_invoice_status(_inv_row($aRecent))['state'], 'active', 'recent unpaid app → state active');
t_eq(mam_invoice_status(_inv_row($aDue))['state'],    'due',    'past due-days unpaid app → state due');
t_ok(mam_invoice_status(_inv_row($aRecent))['days_left'] > 0, 'active app reports days_left until expiry');

// the sweep
mam_invoices_process();
t_eq(_inv_row($aOld)['status'],    'expired',   'overdue app is expired by the sweep');
t_ok(mam_invoice_reminded($aDue),               'due app gets its auto-invoice reminder logged');
t_eq(_inv_row($aRecent)['status'], 'submitted', 'recent app left untouched');
t_eq(_inv_row($aPaid)['status'],   'submitted', 'settled app is never expired');

// idempotent — a second sweep neither re-invoices nor re-expires
$before = (int)mam_db_row("SELECT COUNT(*) c FROM application_events WHERE application_id = ? AND reason = 'invoice_reminded'", [$aDue])['c'];
mam_invoices_process();
$after  = (int)mam_db_row("SELECT COUNT(*) c FROM application_events WHERE application_id = ? AND reason = 'invoice_reminded'", [$aDue])['c'];
t_eq($after, $before, 're-running the sweep does not duplicate the reminder');

// expired semantics + restart path
t_eq(mam_invoice_status(_inv_row($aOld))['state'], 'expired', 'expired app reports state expired');
t_ok(!mam_invoice_owes(_inv_row($aOld)), 'expired app leaves the owes/auto-expire path');

// master switch off → sweep is a no-op
mam_setting_set('invoice_auto', '0');
$aOff = _inv_app($cid, 'INV-OFF-' . $rnd(), 20);
mam_invoices_process();
t_eq(_inv_row($aOff)['status'], 'submitted', 'with invoice_auto=0 the sweep does nothing');
mam_setting_set('invoice_auto', '1');

// ── Scholarship exemption (#4): a sponsored student never owes or expires ──
t_group('invoice scholarship exemption');
$schEmail = 'sch_' . $rnd() . '@x.dev';
$schUid = mam_db_insert('users', ['email' => $schEmail, 'name' => 'Sch O', 'role' => 'student', 'password_hash' => 'x', 'student_type' => 'scholarship']);
$schCid = mam_db_insert('customers', ['email' => $schEmail, 'first_name' => 'Sch', 'last_name' => 'O']);
$aSch = _inv_app($schCid, 'INV-SCH-' . $rnd(), 20);  // 20 days old → would normally expire
t_ok(mam_invoice_is_scholarship(_inv_row($aSch)), 'application maps to a scholarship student');
t_ok(!mam_invoice_owes(_inv_row($aSch)), 'scholarship application owes nothing');
t_eq(mam_invoice_status(_inv_row($aSch))['state'], 'scholarship', 'scholarship app → state scholarship');
mam_invoices_process();
t_eq(_inv_row($aSch)['status'], 'submitted', 'the sweep never expires a scholarship application');

// flipping the student back to paying re-enables billing
mam_db_update('users', ['student_type' => null], ['id' => $schUid]);
t_ok(mam_invoice_owes(_inv_row($aSch)), 'reverting to paying re-enables the invoice');
