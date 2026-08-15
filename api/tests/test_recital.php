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

t_group('recital — the listen-back gallery');

// A recital recording is NOT a practice take, and the gate differs on purpose.
// Applying the take rule would give each parent a gallery of exactly one child.
$RG_stu = mam_db_insert('users', ['name' => 'Grace Nwosu', 'email' => 'rg_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$RG_other = mam_db_insert('users', ['name' => 'Other Player', 'email' => 'rg_other@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$RG_rid = mam_recital_create(['title' => 'Gallery Test Recital', 'starts_at' => '2026-06-01 18:00:00'], $RC_admin);
mam_recital_set_status($RG_rid, 'open', $RC_admin);
$RG_item = mam_recital_propose($RG_rid, $RG_stu, ['title' => 'Recorded Piece', 'duration_min' => 4]);
mam_recital_decide($RG_item, 'accepted', $RC_admin);
// A second performer with no recording, proposed now while the call is open —
// the gallery must simply omit them later, without explanation.
$RG_item2 = mam_recital_propose($RG_rid, $RG_other, ['title' => 'Unrecorded piece', 'duration_min' => 3]);
mam_recital_decide($RG_item2, 'accepted', $RC_admin);

// A real audio submission to attach.
$RG_asg = mam_db_insert('assignments', ['student_id' => $RG_stu, 'title' => 'Recital take', 'status' => 'open']);
$RG_take = mam_take_store("\x1A\x45\xDF\xA3" . str_repeat("\x00", 64));
$RG_sub = mam_lms_submit($RG_asg, $RG_stu, $RG_take['url'], ['artifact_kind' => 'audio']);

t_ok(mam_recital_attach_recording($RG_item, $RG_sub, $RC_admin), 'staff attach a recording to a performance');
t_eq((int) mam_db_row('SELECT submission_id FROM recital_items WHERE id = ?', [$RG_item])['submission_id'], $RG_sub, 'and it is stored on the item');

// Publishing one child's playing under another child's name would be the worst
// mistake this feature could make, so ownership is checked, not trusted.
$RG_asg2 = mam_db_insert('assignments', ['student_id' => $RG_other, 'title' => 'Other take', 'status' => 'open']);
$RG_take2 = mam_take_store('OggS' . str_repeat("\x00", 64));
$RG_sub2 = mam_lms_submit($RG_asg2, $RG_other, $RG_take2['url'], ['artifact_kind' => 'audio']);
t_eq($threw(fn() => mam_recital_attach_recording($RG_item, $RG_sub2, $RC_admin)), 'not_their_recording', 'another student\'s recording cannot be attached');
t_eq($threw(fn() => mam_recital_attach_recording($RG_item, 999999, $RC_admin)), 'recording_not_found', 'an unknown recording is refused');
// A link submission is not a recording.
$RG_link = mam_lms_submit($RG_asg, $RG_stu, 'https://example.com/page', ['artifact_kind' => 'link']);
t_eq($threw(fn() => mam_recital_attach_recording($RG_item, $RG_link, $RC_admin)), 'not_audio', 'a non-audio submission is refused');

// RULE 1 — nothing is listenable before the night. A programme is a promise.
t_ok(!mam_recital_recording_published($RG_item), 'a recording is not published while the recital is only programmed');
mam_recital_set_status($RG_rid, 'programmed', $RC_admin);
t_ok(!mam_recital_recording_published($RG_item), 'still not, once merely programmed');
mam_recital_set_status($RG_rid, 'completed', $RC_admin);

// RULE 2 — media consent. The same flag that earns a full name on the
// programme is the one families read as "you may record and share my child".
mam_consent_set($RG_stu, ['media' => false, 'photo' => true, 'data' => true]);
t_ok(!mam_recital_recording_published($RG_item), 'without media consent the recording stays unpublished');
t_eq(count(mam_recital_gallery($RG_rid)), 0, 'and the gallery is empty');
mam_consent_set($RG_stu, ['media' => true, 'photo' => true, 'data' => true]);
t_ok(mam_recital_recording_published($RG_item), 'with media consent it is published');

$gal = mam_recital_gallery($RG_rid);
t_eq(count($gal), 1, 'the gallery carries the performance');
t_eq($gal[0]['title'], 'Recorded Piece', 'with its work title');
t_eq($gal[0]['audio_url'], '/api/media/recital-take?item=' . $RG_item, 'and a recital-scoped audio URL, not a raw storage key');
t_ok(!array_key_exists('student_id', $gal[0]), 'the gallery leaks no internal student id');

// RULE 3 — signed in. A recording of a child never goes on the open web,
// however completed the recital or however freely the family consented.
t_ok(!mam_recital_can_hear(null, $RG_item), 'a logged-out visitor cannot hear a performance');
t_ok(!mam_recital_can_hear(['id' => 0], $RG_item), 'nor can a bogus user');
t_ok(mam_recital_can_hear(['id' => $RC_admin], $RG_item), 'any signed-in academy member can');
// Notably: a signed-in user who is NOT the performer or their family CAN hear
// it. That is the point of a gallery — the audience is the evening's audience.
t_ok(mam_recital_can_hear(['id' => $RG_other], $RG_item), 'another student can hear it — the gallery is the whole evening');

// An item with no recording is simply absent; the gallery explains nothing.
t_ok(!mam_recital_recording_published($RG_item2), 'an item with no recording is not published');
t_ok(!in_array('Unrecorded piece', array_column(mam_recital_gallery($RG_rid), 'title'), true), 'and does not appear in the gallery at all');

// Clearing is how a family withdraws a recording after the fact.
t_ok(mam_recital_attach_recording($RG_item, null, $RC_admin), 'a recording can be cleared');
t_ok(!mam_recital_recording_published($RG_item), 'and is then unpublished');
t_eq(count(mam_recital_gallery($RG_rid)), 0, 'leaving the gallery empty again');
mam_recital_attach_recording($RG_item, $RG_sub, $RC_admin);

// A gallery only exists for a completed recital.
t_eq(count(mam_recital_gallery($RC_dupe)), 0, 'a draft recital has no gallery');

t_group('recital — the guardian view');

$RG_parent = mam_parent_provision('rg_guardian@test.local', 'Ngozi Nwosu');
mam_parent_link('rg_guardian@test.local', $RG_stu, 'mother');
$RG_pid = (int) $RG_parent['user']['id'];

$gv = mam_recital_for_guardian($RG_stu);
t_ok(isset($gv['upcoming']) && isset($gv['past']), 'the guardian view splits upcoming from performed');
t_eq(count($gv['past']), 1, 'the completed recital appears under past');
t_eq($gv['past'][0]['recital_title'], 'Gallery Test Recital', 'named');
t_eq($gv['past'][0]['programme_url'], '/recital/' . mam_recital($RG_rid)['slug'], 'with a link to the programme they can share');
t_ok($gv['past'][0]['audio_url'] !== null, 'and the recording, since it is published');

// The slot time must match the printed programme exactly — a guardian planning
// the evening must not be given a second, drifting answer.
$RG_up = mam_recital_create(['title' => 'Guardian Slot Recital', 'starts_at' => '2027-01-10 15:00:00'], $RC_admin);
mam_recital_set_status($RG_up, 'open', $RC_admin);
$slotA = mam_recital_propose($RG_up, $RG_other, ['title' => 'First up', 'duration_min' => 7]);
$slotB = mam_recital_propose($RG_up, $RG_stu,   ['title' => 'Second up', 'duration_min' => 5]);
mam_recital_decide($slotA, 'accepted', $RC_admin);
mam_recital_decide($slotB, 'accepted', $RC_admin);
mam_recital_set_status($RG_up, 'programmed', $RC_admin);

$gv2 = mam_recital_for_guardian($RG_stu);
$upRow = array_values(array_filter($gv2['upcoming'], fn($x) => $x['recital_title'] === 'Guardian Slot Recital'))[0] ?? null;
t_ok($upRow !== null, 'a programmed recital appears under upcoming');
t_eq($upRow['slot_at'], '15:07', 'the slot time is when this child actually steps out (15:00 + a 7-minute opener)');
$progRow = array_values(array_filter(mam_recital_programme_full($RG_up)['items'], fn($x) => (int) ($x['id'] ?? 0) === $slotB))[0];
t_eq($upRow['slot_at'], $progRow['at'], 'and it matches the printed programme exactly');
t_ok($upRow['audio_url'] === null, 'an upcoming performance has no recording yet');

// A guardian is shown what is real. A proposal still under consideration is
// the student's own business until a teacher has decided.
$RG_pending = mam_recital_create(['title' => 'Pending Recital'], $RC_admin);
mam_recital_set_status($RG_pending, 'open', $RC_admin);
mam_recital_propose($RG_pending, $RG_stu, ['title' => 'Still deciding', 'duration_min' => 3]);
$gv3 = mam_recital_for_guardian($RG_stu);
$titles = array_column(array_merge($gv3['upcoming'], $gv3['past']), 'title');
t_ok(!in_array('Still deciding', $titles, true), 'an undecided proposal is not shown to a guardian');

// And a declined one never is either.
$decl = mam_recital_propose($RG_pending, $RG_stu, ['title' => 'Turned down', 'duration_min' => 3]);
mam_recital_decide($decl, 'declined', $RC_admin);
$titles2 = array_column(array_merge(mam_recital_for_guardian($RG_stu)['upcoming'], mam_recital_for_guardian($RG_stu)['past']), 'title');
t_ok(!in_array('Turned down', $titles2, true), 'nor is a declined one');

// It reaches the guardian payload the portal actually renders.
$detail = mam_parent_child_detail($RG_pid, $RG_stu);
t_ok(isset($detail['recitals']['upcoming']) && isset($detail['recitals']['past']), 'child detail carries the recital block');
t_eq(count($detail['recitals']['past']), 1, 'with the performed recital in it');

t_group('recital — emailing the programme');

// Publishing a running order and leaving it in a portal assumes families log
// in. They do not, reliably, and the one thing they must not miss is when
// their child is on stage.
$RE_rid = mam_recital_create(['title' => 'Email Test Recital', 'venue' => 'Hall B', 'starts_at' => '2027-05-09 17:00:00'], $RC_admin);
mam_recital_set_status($RE_rid, 'open', $RC_admin);

$RE_s1 = mam_db_insert('users', ['name' => 'Tobi Adewale', 'email' => 're_s1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$RE_s2 = mam_db_insert('users', ['name' => 'Zainab Bello', 'email' => 're_s2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$RE_i1 = mam_recital_propose($RE_rid, $RE_s1, ['title' => 'Opening piece', 'duration_min' => 6, 'instrument' => 'Piano']);
$RE_i2 = mam_recital_propose($RE_rid, $RE_s2, ['title' => 'Second piece',  'duration_min' => 4, 'instrument' => 'Voice']);
// A student with TWO pieces must still receive exactly one email.
$RE_i3 = mam_recital_propose($RE_rid, $RE_s1, ['title' => 'Third piece',   'duration_min' => 3, 'instrument' => 'Piano']);
foreach ([$RE_i1, $RE_i2, $RE_i3] as $i) mam_recital_decide($i, 'accepted', $RC_admin);

$RE_g = mam_parent_provision('re_guardian@test.local', 'Bunmi Adewale');
mam_parent_link('re_guardian@test.local', $RE_s1, 'father');

mam_recital_set_status($RE_rid, 'programmed', $RC_admin);

// Publishing sends it. Three performers-worth of rows, but three ADDRESSES:
// two students and one guardian.
$ev = mam_db_row("SELECT payload FROM events WHERE type = 'recital.programme_emailed' AND entity_id = ?", [$RE_rid]);
t_ok($ev !== null && $ev !== false, 'publishing a programme emails it');
$payload = json_decode((string) $ev['payload'], true) ?: [];
t_eq((int) ($payload['recipients'] ?? 0), 3, 'one message per ADDRESS — two students and one guardian, not one per piece');

// Idempotent. Re-publishing after adding a piece is normal staff behaviour and
// must not mean everybody is emailed twice.
$again = mam_recital_email_programme($RE_rid, false, $RC_admin);
t_eq($again['skipped'], 1, 'a second unforced send is skipped');
t_eq($again['sent'], 0, 'and sends nothing');
t_eq((int) (mam_db_row("SELECT COUNT(*) AS n FROM events WHERE type = 'recital.programme_emailed' AND entity_id = ?", [$RE_rid])['n'] ?? 0), 1, 'leaving a single event row');

// The deliberate resend, for when the order really has changed.
$forced = mam_recital_email_programme($RE_rid, true, $RC_admin);
t_eq($forced['recipients'], 3, 'a forced resend reaches everyone again');
t_ok($forced['skipped'] === 0, 'and is not skipped');

// A recital that was never published is never emailed.
$RE_draft = mam_recital_create(['title' => 'Never Published'], $RC_admin);
t_eq(mam_recital_email_programme($RE_draft, true, $RC_admin)['recipients'], 0, 'a draft programme is not emailed');
// Nor is an empty one.
$RE_empty = mam_recital_create(['title' => 'Empty Programme'], $RC_admin);
mam_recital_set_status($RE_empty, 'programmed', $RC_admin);
t_eq(mam_recital_email_programme($RE_empty, true, $RC_admin)['recipients'], 0, 'a programme with no accepted pieces is not emailed');

// It must never throw. A mail server that is down cannot undo a publication.
$noSuch = mam_recital_email_programme(999999, true, $RC_admin);
t_eq($noSuch['recipients'], 0, 'an unknown recital returns an empty result rather than throwing');

t_group('recital — the programme email itself');

$RE_prog  = mam_recital_programme_full($RE_rid);
$RE_items = $RE_prog['items'];
foreach ($RE_items as &$__r) {
    if (!empty($__r['student_id'])) $__r['performer_display'] = mam_recital_public_name((int) $__r['student_id'], (string) $__r['student_name']);
}
unset($__r);

$html = mam_email_recital_programme(mam_recital($RE_rid), $RE_items, [
    'highlight_item_id' => $RE_i1,
    'highlight_lede'    => 'Tobi performs at',
    'url'               => 'https://example.test/recital/email-test-recital',
]);

// A LINK IS NOT A PROGRAMME. The running order has to be in the message.
t_ok(strpos($html, 'Opening piece') !== false, 'the email contains the running order, not just a link');
t_ok(strpos($html, 'Second piece') !== false, 'including other performers');
t_ok(strpos($html, 'Third piece') !== false, 'and every piece');
t_ok(strpos($html, 'Email Test Recital') !== false, 'it names the recital');
t_ok(strpos($html, 'Hall B') !== false, 'and the venue');
t_ok(strpos($html, '17:00') !== false, 'and the start time');

// The recipient's own slot is lifted out — "you play at 17:00" is the fact
// they opened the email for.
t_ok(strpos($html, 'Tobi performs at') !== false, 'the recipient\'s own slot is lifted to the top');
t_ok(strpos($html, 'https://example.test/recital/email-test-recital') !== false, 'with a shareable link to the online programme');

// Table-based and inline-styled: Outlook has no flexbox, and a running order
// that collapses to one column in a desktop client is worse than no email.
t_ok(strpos($html, '<table') !== false, 'it is table-based for Outlook');
t_ok(strpos($html, 'display:flex') === false, 'and uses no flexbox');

// Transactional, not marketing — nobody unsubscribes from being told when
// their own child is on stage.
t_ok(strpos($html, '{{UNSUB_URL}}') === false, 'it carries no unsubscribe token');

// Names follow the SAME consent rule as the printed programme, so a family
// reading both never sees two different versions of a child.
mam_consent_set($RE_s2, ['media' => false, 'photo' => true, 'data' => true]);
$items2 = mam_recital_programme_full($RE_rid)['items'];
foreach ($items2 as &$__q) {
    if (!empty($__q['student_id'])) $__q['performer_display'] = mam_recital_public_name((int) $__q['student_id'], (string) $__q['student_name']);
}
unset($__q);
$html2 = mam_email_recital_programme(mam_recital($RE_rid), $items2, []);
t_ok(strpos($html2, 'Zainab B.') !== false, 'a performer without media consent is named privately in the email too');
t_ok(strpos($html2, 'Zainab Bello') === false, 'and their full name does not appear');
// But they are still ON the programme — never a silent omission.
t_ok(strpos($html2, 'Second piece') !== false, 'their piece is still listed');

// The interval is rendered, not skipped.
mam_recital_update($RE_rid, ['interval_after' => 1, 'interval_min' => 10], $RC_admin);
$items3 = mam_recital_programme_full($RE_rid)['items'];
$html3  = mam_email_recital_programme(mam_recital($RE_rid), $items3, []);
t_ok(strpos($html3, 'Interval') !== false, 'the interval appears in the emailed running order');
t_ok(strpos($html3, '10 minutes') !== false, 'with its length');

// An email is safe to build for a recital with no start time.
$RE_nt = mam_recital_create(['title' => 'Undated Email Recital'], $RC_admin);
mam_recital_set_status($RE_nt, 'open', $RC_admin);
$ntI = mam_recital_propose($RE_nt, $RE_s1, ['title' => 'Undated piece', 'duration_min' => 4]);
mam_recital_decide($ntI, 'accepted', $RC_admin);
mam_recital_set_status($RE_nt, 'programmed', $RC_admin);
$htmlNt = mam_email_recital_programme(mam_recital($RE_nt), mam_recital_programme_full($RE_nt)['items'], ['highlight_item_id' => $ntI]);
t_ok(strpos($htmlNt, 'Date to be confirmed') !== false, 'a recital with no date says so rather than printing a broken date');
t_ok(strpos($htmlNt, 'a time to be confirmed') !== false, 'and the highlighted slot degrades to words');

// HTML injection through a work title must not escape into the markup.
$RE_x = mam_recital_create(['title' => 'Escaping Recital'], $RC_admin);
mam_recital_set_status($RE_x, 'open', $RC_admin);
$xI = mam_recital_propose($RE_x, $RE_s1, ['title' => '<script>alert(1)</script>', 'composer' => '"><b>x', 'duration_min' => 3]);
mam_recital_decide($xI, 'accepted', $RC_admin);
mam_recital_set_status($RE_x, 'programmed', $RC_admin);
$htmlX = mam_email_recital_programme(mam_recital($RE_x), mam_recital_programme_full($RE_x)['items'], []);
t_ok(strpos($htmlX, '<script>alert(1)</script>') === false, 'a work title cannot inject script into the email');
t_ok(strpos($htmlX, '&lt;script&gt;') !== false, 'it is escaped instead');

// Regression: the facts line escaped its OWN separator, so every recipient
// read "Saturday 14 November &middot; Begins 18:00" as literal text. Caught by
// looking at a rendered email, not by any string assertion — which is why the
// assertion now exists.
t_ok(strpos($html, '&amp;middot;') === false, 'the facts separator is a real entity, not double-escaped text');
t_ok(strpos($html, 'Hall B</strong>') !== false || strpos($html, 'Hall B') !== false, 'and the venue survives it');

// Regression: mam_email_button renders align="left" (a float), so anything
// emitted after it wrapped alongside the button instead of below. The share
// line now precedes it and a clearing div closes the float.
t_ok(strpos($html, 'clear:both') !== false, 'the floated CTA is cleared so the footer cannot ride up beside it');
t_ok(strpos($html, 'Share this link') < strpos($html, 'View the programme online'), 'the share line is placed before the floated button, not after it');
