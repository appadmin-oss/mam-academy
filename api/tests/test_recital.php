<?php
/**
 * The Recital Hall.
 *
 * Two things here are worth more than the CRUD: the LIFECYCLE (a published
 * running order must stop moving, and completing a recital is what turns
 * "polished" into "performed" in a student's record) and the PUBLIC SHAPE
 * (a programme is read by anyone with the link, so what it discloses about a
 * child is a decision, not an accident).
 */
declare(strict_types=1);

t_group('recital — lifecycle');

$RC_admin = mam_db_insert('users', ['name' => 'RC Faculty', 'email' => 'rc_fac@test.local', 'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$RC_id = mam_recital_create([
    'title'        => 'Test Michaelmas Recital',
    'venue'        => 'Hall A',
    'starts_at'    => '2026-11-14 18:00:00',
    'interval_min' => 15,
], $RC_admin);
t_ok($RC_id > 0, 'a recital is created');
$RC = mam_recital($RC_id);
t_eq($RC['status'], 'draft', 'and starts as a draft — invisible until staff say otherwise');
t_eq($RC['slug'], 'test-michaelmas-recital', 'the slug is derived from the title');

// A second recital with the same title must not collide on the slug, or the
// public URL would resolve to the wrong programme.
$RC_dupe = mam_recital_create(['title' => 'Test Michaelmas Recital'], $RC_admin);
t_eq(mam_recital($RC_dupe)['slug'], 'test-michaelmas-recital-2', 'a duplicate title gets a distinct slug');

t_ok(mam_recital_can_transition('draft', 'open'), 'draft may open');
t_ok(mam_recital_can_transition('open', 'programmed'), 'open may be programmed');
t_ok(mam_recital_can_transition('programmed', 'completed'), 'a programme may complete');
// The one-way doors. A completed or cancelled recital is history.
t_ok(!mam_recital_can_transition('completed', 'open'), 'a completed recital cannot reopen');
t_ok(!mam_recital_can_transition('completed', 'programmed'), 'nor be re-programmed');
t_ok(!mam_recital_can_transition('cancelled', 'open'), 'a cancelled recital cannot reopen');
t_ok(!mam_recital_can_transition('programmed', 'draft'), 'a published programme cannot slip back to a draft');

$threw = function (callable $fn) { try { $fn(); return null; } catch (\Throwable $e) { return $e->getMessage(); } };
t_eq($threw(fn() => mam_recital_set_status($RC_id, 'completed', $RC_admin)), 'illegal_transition', 'a draft cannot jump straight to completed');
t_eq($threw(fn() => mam_recital_set_status($RC_id, 'nonsense', $RC_admin)), 'bad_status', 'an unknown status is refused');
t_eq($threw(fn() => mam_recital_set_status(999999, 'open', $RC_admin)), 'not_found', 'an unknown recital is not found');

t_group('recital — the call for submissions');

// Nothing may be proposed until the call is open. A student offering a piece
// for a recital staff have not announced is a race, not a submission.
$RC_s1 = mam_db_insert('users', ['name' => 'Ada Grace Okafor', 'email' => 'rc_s1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$RC_s2 = mam_db_insert('users', ['name' => 'Benjamin Musa',    'email' => 'rc_s2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$RC_p1 = mam_repertoire_add($RC_s1, 'Fur Elise', 'Beethoven', 'polished');
$RC_p2 = mam_repertoire_add($RC_s2, 'Prelude in C', 'J. S. Bach', 'polished');

t_eq($threw(fn() => mam_recital_propose($RC_id, $RC_s1, ['repertoire_id' => $RC_p1])), 'submissions_closed', 'a draft accepts no proposals');
mam_recital_set_status($RC_id, 'open', $RC_admin);
t_ok(mam_recital_accepting(mam_recital($RC_id)), 'an open recital is accepting');

$RC_i1 = mam_recital_propose($RC_id, $RC_s1, ['repertoire_id' => $RC_p1, 'instrument' => 'Piano', 'duration_min' => 5]);
t_ok($RC_i1 > 0, 'a student proposes a polished piece');
$row = mam_db_row('SELECT * FROM recital_items WHERE id = ?', [$RC_i1]);
t_eq($row['status'], 'proposed', 'it lands as proposed');
// The title and composer are copied from the repertoire entry, so a student
// does not retype what the academy already knows.
t_eq($row['title'], 'Fur Elise', 'the title comes from the repertoire entry');
t_eq($row['composer'], 'Beethoven', 'as does the composer');

// Proposing the same piece twice is the same intent, not two performances.
t_eq(mam_recital_propose($RC_id, $RC_s1, ['repertoire_id' => $RC_p1]), $RC_i1, 're-proposing the same piece is idempotent');
// A student may not put forward somebody else's work under their own name.
t_eq($threw(fn() => mam_recital_propose($RC_id, $RC_s1, ['repertoire_id' => $RC_p2])), 'not_your_piece', 'a student cannot propose another student\'s repertoire');
t_eq($threw(fn() => mam_recital_propose($RC_id, $RC_s1, [])), 'title_required', 'a proposal needs a title if there is no repertoire entry');

// A closing date really closes the call.
mam_recital_update($RC_id, ['submissions_close_at' => '2020-01-01 00:00:00'], $RC_admin);
t_ok(!mam_recital_accepting(mam_recital($RC_id)), 'a past closing date stops submissions');
t_eq($threw(fn() => mam_recital_propose($RC_id, $RC_s2, ['repertoire_id' => $RC_p2])), 'submissions_closed', 'and proposals are refused');
mam_recital_update($RC_id, ['submissions_close_at' => null], $RC_admin);
t_ok(mam_recital_accepting(mam_recital($RC_id)), 'clearing the date reopens it');

$RC_i2 = mam_recital_propose($RC_id, $RC_s2, ['repertoire_id' => $RC_p2, 'instrument' => 'Piano', 'duration_min' => 3]);

t_group('recital — curation');

t_eq($threw(fn() => mam_recital_decide($RC_i1, 'maybe', $RC_admin)), 'bad_decision', 'only accept or decline are decisions');
t_ok(mam_recital_decide($RC_i1, 'accepted', $RC_admin), 'a piece is accepted');
// Accepting positions the item, so a curator's first pass is only ever yes/no.
t_ok((int) mam_db_row('SELECT sort_order FROM recital_items WHERE id = ?', [$RC_i1])['sort_order'] > 0, 'accepting places it in the running order');
t_ok(mam_recital_decide($RC_i2, 'accepted', $RC_admin), 'a second piece is accepted');

// A decline is recorded, not deleted — the student put themselves forward and
// that fact belongs to them.
$RC_i3 = mam_recital_propose($RC_id, $RC_s1, ['title' => 'Something unready', 'duration_min' => 9]);
mam_recital_decide($RC_i3, 'declined', $RC_admin, 'Bring this to the spring recital.');
$d = mam_db_row('SELECT * FROM recital_items WHERE id = ?', [$RC_i3]);
t_eq($d['status'], 'declined', 'a declined piece keeps its row');
t_eq($d['note'], 'Bring this to the spring recital.', 'with the teacher\'s note attached');
t_ok($d['decided_by'] !== null && $d['decided_at'] !== null, 'and a record of who decided, and when');

// Reordering rewrites positions in tens, leaving gaps to insert into later.
$moved = mam_recital_reorder($RC_id, [['id' => $RC_i2, 'section' => 'main'], ['id' => $RC_i1, 'section' => 'main']], $RC_admin);
t_eq($moved, 2, 'both items are repositioned');
$order = array_column(mam_recital_items($RC_id, ['accepted']), 'id');
t_eq((int) $order[0], $RC_i2, 'the running order now leads with the second piece');
// An item belonging to another recital must not be draggable into this one.
t_eq(mam_recital_reorder($RC_id, [['id' => 999999, 'section' => 'main']], $RC_admin), 0, 'an unknown item is ignored, not inserted');

t_group('recital — the running order computes real clock times');

mam_recital_update($RC_id, ['interval_after' => 1, 'interval_min' => 20], $RC_admin);
$prog = mam_recital_programme_full($RC_id);
$rows = $prog['items'];
t_eq(count($rows), 3, 'two pieces plus an interval');
t_eq($rows[0]['at'], '18:00', 'the first piece starts at the advertised time');
t_ok(!empty($rows[1]['interval']), 'the interval sits after the first piece, where it was placed');
t_eq($rows[1]['at'], '18:03', 'and begins when that piece ends (3 minutes in)');
t_eq($rows[2]['at'], '18:23', 'the next piece starts after the 20-minute interval');
t_eq($prog['total_min'], 3 + 20 + 5, 'the running time is pieces plus interval');
t_eq($prog['ends_at'], '18:28', 'and the finish time is computed, not guessed');
t_eq($prog['performer_count'], 2, 'two performers');
// Declined work never reaches the programme.
t_ok(!in_array('Something unready', array_column($rows, 'title'), true), 'a declined piece is not on the programme');

// A recital with no start time still produces a programme — it simply has no
// clock. Refusing to render one would make a date-less recital unpublishable.
$RC_noTime = mam_recital_create(['title' => 'Undated Recital'], $RC_admin);
mam_recital_set_status($RC_noTime, 'open', $RC_admin);
$ntItem = mam_recital_propose($RC_noTime, $RC_s1, ['title' => 'A piece', 'duration_min' => 4]);
mam_recital_decide($ntItem, 'accepted', $RC_admin);
$ntProg = mam_recital_programme_full($RC_noTime);
t_ok($ntProg['items'][0]['at'] === null, 'no start time means no clock time, not a crash');
t_eq($ntProg['total_min'], 4, 'but the running time is still known');
t_ok($ntProg['ends_at'] === null, 'and there is no computed finish');

t_group('recital — publishing and completing');

mam_recital_set_status($RC_id, 'programmed', $RC_admin);
t_eq(mam_recital($RC_id)['status'], 'programmed', 'the programme is published');
// Publishing tells the performers.
t_ok((int) (mam_db_row("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND type = 'recital.programmed'", [$RC_s1])['n'] ?? 0) > 0, 'performers are told the programme is out');

// Completing is the moment a piece becomes 'performed' — in the recital AND in
// the student's own repertoire. That promotion is the whole point of the Hall.
t_eq(mam_db_row('SELECT status FROM repertoire WHERE id = ?', [$RC_p1])['status'], 'polished', 'before the recital the piece is merely polished');
mam_recital_set_status($RC_id, 'completed', $RC_admin);
t_eq(mam_db_row('SELECT status FROM recital_items WHERE id = ?', [$RC_i1])['status'], 'performed', 'an accepted item becomes performed');
t_eq(mam_db_row('SELECT status FROM repertoire WHERE id = ?', [$RC_p1])['status'], 'performed', 'and the repertoire entry is promoted to performed');
// A declined piece is not retroactively performed.
t_eq(mam_db_row('SELECT status FROM recital_items WHERE id = ?', [$RC_i3])['status'], 'declined', 'a declined item is untouched by completion');

t_group('recital — what the public may see');

// A programme names its performers — that is the honour. But this academy
// teaches children and runs a consent register, so MEDIA consent is what earns
// a full name on a page anyone can open.
mam_consent_set($RC_s1, ['media' => true, 'photo' => true, 'data' => true]);
mam_consent_set($RC_s2, ['media' => false, 'photo' => true, 'data' => true]);
t_eq(mam_recital_public_name($RC_s1, 'Ada Grace Okafor'), 'Ada Grace Okafor', 'media consent earns a full name');
t_eq(mam_recital_public_name($RC_s2, 'Benjamin Musa'), 'Benjamin M.', 'without it, first name and last initial');
// Never a silent omission — a child dropped from the programme because a form
// was unsigned would be the cruellest reading of a consent flag.
$pubItems = array_map('mam_recital_public_item', mam_recital_programme_full($RC_id)['items']);
$names = array_column(array_filter($pubItems, fn($x) => empty($x['interval'])), 'performer');
t_eq(count($names), 2, 'both students still appear on the public programme');
t_ok(in_array('Benjamin M.', $names, true), 'the unconsented student is named privately, not removed');

// The public item must not leak internals.
$one = $pubItems[0];
t_ok(!array_key_exists('student_id', $one), 'no internal student id reaches the public programme');
t_ok(!array_key_exists('note', $one), 'no staff note reaches the public programme');
// A recording is access-gated (/api/media/take checks the caller). Publishing
// its URL on a page with no session would hand out a recording of a child.
t_ok(!array_key_exists('recording_url', $one), 'no recording URL is published');

$card = mam_recital_public_card(mam_recital($RC_id));
t_ok(!array_key_exists('id', $card), 'the public card exposes no row id');
t_ok(!array_key_exists('created_by', $card), 'nor who created it');
t_eq($card['url'], '/recital/' . mam_recital($RC_id)['slug'], 'it carries its own public URL');

// Only a published or completed recital is publicly listable — a draft must
// never leak, and neither must an open call's running order.
$RC_draft = mam_recital_create(['title' => 'Secret Draft Recital'], $RC_admin);
$slugs = array_column(mam_recital_public_list(50), 'slug');
t_ok(!in_array(mam_recital($RC_draft)['slug'], $slugs, true), 'a draft is not publicly listed');
t_ok(in_array(mam_recital($RC_id)['slug'], $slugs, true), 'a completed recital is');

t_group('recital — the student\'s own view');

$mine = mam_recital_for_student($RC_s1);
t_ok(count($mine) >= 2, 'a student sees everything they put forward');
$statuses = array_column($mine, 'status');
t_ok(in_array('performed', $statuses, true), 'including what they performed');
t_ok(in_array('declined', $statuses, true), 'and what was declined — putting yourself forward is a fact worth keeping');

// Withdrawing is the student's own decision and is theirs alone to make.
$RC_open2 = mam_recital_create(['title' => 'Withdrawal Test Recital'], $RC_admin);
mam_recital_set_status($RC_open2, 'open', $RC_admin);
$wItem = mam_recital_propose($RC_open2, $RC_s1, ['title' => 'Changed my mind', 'duration_min' => 3]);
t_eq($threw(fn() => mam_recital_withdraw($wItem, $RC_s2)), 'forbidden', 'a student cannot withdraw another student\'s piece');
t_ok(mam_recital_withdraw($wItem, $RC_s1), 'the owner withdraws it');
t_eq(mam_db_row('SELECT status FROM recital_items WHERE id = ?', [$wItem])['status'], 'withdrawn', 'and it is marked withdrawn, not deleted');
t_ok(!mam_recital_withdraw($wItem, $RC_s1), 'withdrawing twice is a no-op');
