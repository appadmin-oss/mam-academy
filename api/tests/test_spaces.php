<?php
/**
 * Stored files (media-store.php), class spaces (spaces.php) and safeguarding
 * concerns (safeguarding.php), schema v99. Each group pins one rule a refactor
 * could lose without any screen looking wrong.
 */
declare(strict_types=1);

t_group('storage — SigV4 signs exactly as AWS documents');

$SP_t = gmmktime(0, 0, 0, 5, 24, 2013);
t_ok(str_contains(mam_bucket_presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', [], 86400,
    'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', $SP_t),
    'X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404'), 'the presigned URL matches AWS\'s query-auth example');
$SP_h = mam_bucket_auth_headers('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', '', ['range' => 'bytes=0-9'],
    'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', $SP_t);
t_ok(str_contains((string) end($SP_h), 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41'), 'and header auth matches AWS\'s GET-object example');

t_group('storage — what may be stored, and where');

t_eq(mam_media_classify('community', 'clip.MP4', 'video/mp4')['type'] ?? null, 'video/mp4', 'a video is stored as the type its extension says');
t_eq(mam_media_classify('community', 'page.html', 'text/html'), null, 'HTML is never stored — a bucket must not be able to host a page');
t_eq(mam_media_classify('community', 'x.svg', 'image/svg+xml'), null, 'nor SVG, which carries script');
t_eq(mam_media_classify('community', 'notes.docx', ''), null, 'a purpose takes only its own kinds (no documents in a class feed)');
t_ok(mam_media_key_ok(mam_media_new_key('community', 'mp4')), 'a new key is in the grammar it is checked against');
t_ok(!mam_media_key_ok('mam/community/2026/01/../../etc.mp4') && mam_media_local_path('../x') === null, 'a key that climbs is refused before any path is built');

$SP_now = gmdate('YmdHis') . random_int(100, 999);
$SP_u = static fn(string $tag, string $role) => (int) mam_db_insert('users', ['email' => "sp.$tag.$SP_now@test.local", 'name' => ucfirst($tag) . ' Space',
    'role' => $role, 'password_hash' => 'x', 'status' => 'active']);
$SP_amy = $SP_u('amy', 'student'); $SP_ben = $SP_u('ben', 'student'); $SP_out = $SP_u('outsider', 'student');
$SP_teach = $SP_u('teacher', 'instructor'); $SP_other = $SP_u('otherteacher', 'instructor'); $SP_mum = $SP_u('mum', 'parent');
$SP_admin = $SP_u('admin', 'admin');
$SP_user = static fn(int $id) => mam_db_row('SELECT * FROM users WHERE id = ?', [$id]);

unset($GLOBALS['__mam_bucket_cfg_mock']);
$SP_b = mam_media_begin($SP_amy, 'community', 'performance.mp4', 'video/mp4', 11);
t_eq($SP_b['provider'], 'local', 'with no bucket the upload goes to this host');
t_ok(str_starts_with($SP_b['upload']['url'], '/api/media/put?id=' . $SP_b['id'] . '&t='), '…through the signed local PUT');
$SP_tok = explode('&t=', $SP_b['upload']['url'])[1];
t_ok(mam_media_put_token_ok($SP_tok, $SP_b['id'], $SP_amy), 'the token is good for its owner');
t_ok(!mam_media_put_token_ok($SP_tok, $SP_b['id'], $SP_ben), 'and for nobody else');
t_throws(fn() => mam_media_begin($SP_amy, 'community', 'big.mp4', 'video/mp4', MAM_MEDIA_LOCAL_MAX + 1), null,
    'without a bucket a file larger than this host keeps is refused BEFORE it is sent');

$SP_stream = fopen('php://memory', 'w+'); fwrite($SP_stream, 'hello video'); rewind($SP_stream);
mam_media_local_receive($SP_b['id'], $SP_amy, $SP_stream);
$SP_long = fopen('php://memory', 'w+'); fwrite($SP_long, 'too many bytes here'); rewind($SP_long);
$SP_b2 = mam_media_begin($SP_amy, 'community', 'p2.mp4', 'video/mp4', 4);
t_throws(fn() => mam_media_local_receive($SP_b2['id'], $SP_amy, $SP_long), 'too_large', 'a body longer than was signed is refused while it streams');
t_throws(fn() => mam_media_finish($SP_b2['id'], $SP_amy), 'not_uploaded', 'and a file that never arrived does not become "uploaded"');
$SP_file = mam_media_finish($SP_b['id'], $SP_amy, ['duration' => 12.5, 'width' => 1280, 'height' => 720, 'compressed' => true, 'evil' => '<x>']);
t_eq($SP_file['status'], 'uploaded', 'a whole file is marked uploaded once its size is checked');
t_ok(!str_contains((string) $SP_file['meta'], 'evil'), 'only the meta fields we know are kept');

t_group('storage — a private bucket');

$GLOBALS['__mam_bucket_cfg_mock'] = ['endpoint' => 'https://acct.r2.cloudflarestorage.com', 'bucket' => 'mam-media', 'access_key' => 'AK', 'secret_key' => 'SK'];
$SP_heads = [];
$GLOBALS['__mam_bucket_http_mock'] = function (string $m, string $k) use (&$SP_heads) { $SP_heads[] = [$m, $k]; return ['code' => 200, 'headers' => ['content-length' => '2048']]; };
$SP_bb = mam_media_begin($SP_amy, 'community', 'take.mp4', 'video/mp4', 2048);
t_eq($SP_bb['provider'], 'bucket', 'with a bucket set the browser uploads straight to it');
t_ok(str_contains($SP_bb['upload']['url'], 'X-Amz-SignedHeaders=content-length%3Bcontent-type%3Bhost'), 'the PUT is signed for one content type and one exact length');
mam_media_finish($SP_bb['id'], $SP_amy);
t_eq($SP_heads[0][0] ?? '', 'HEAD', 'finishing checks the stored object with a HEAD before it counts');
$SP_bo = mam_db_row('SELECT * FROM media_objects WHERE id = ?', [$SP_bb['id']]);
$SP_read = (string) mam_media_read_url($SP_bo);
t_ok(str_contains($SP_read, 'X-Amz-Expires=' . MAM_BUCKET_GET_TTL) && str_contains($SP_read, 'response-cache-control'), 'a read is a link that lasts ten minutes, never a public URL');
$GLOBALS['__mam_bucket_http_mock'] = fn() => ['code' => 200, 'headers' => ['content-length' => '1']];
$SP_bad = mam_media_begin($SP_amy, 'community', 'lie.mp4', 'video/mp4', 5000);
t_throws(fn() => mam_media_finish($SP_bad['id'], $SP_amy), 'size_mismatch', 'a stored size that differs from the signed one is refused');
unset($GLOBALS['__mam_bucket_cfg_mock'], $GLOBALS['__mam_bucket_http_mock']);

t_group('class spaces — who is in one');

$SP_course = (int) mam_db_insert('courses', ['code' => 'sp_' . $SP_now, 'name' => 'Spaces ' . $SP_now, 'kind' => 'elective', 'active' => 1, 'sort_order' => 99]);
$SP_co = (int) mam_db_insert('cohorts', ['course_id' => $SP_course, 'instructor_id' => $SP_teach, 'title' => 'Violin A ' . $SP_now, 'status' => 'active']);
foreach ([$SP_amy, $SP_ben] as $sid) mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $SP_course, 'cohort_id' => $SP_co, 'status' => 'active']);
mam_db_insert('parent_links', ['parent_id' => $SP_mum, 'student_id' => $SP_amy]);
$SP_id = mam_space_for_cohort($SP_co, $SP_teach);
t_eq(mam_space_for_cohort($SP_co), $SP_id, 'a class has one space; asking again returns it');
$SP_s = mam_space_get($SP_id);
t_eq(mam_space_role($SP_s, $SP_user($SP_amy)), 'student', 'an enrolled student is in their class\'s space');
t_eq(mam_space_role($SP_s, $SP_user($SP_teach)), 'teacher', 'the class\'s teacher is its teacher');
t_eq(mam_space_role($SP_s, $SP_user($SP_out)), null, 'a student from another class is not');
t_eq(mam_space_role($SP_s, $SP_user($SP_other)), null, 'nor is a teacher of another class');
t_eq(mam_space_role($SP_s, $SP_user($SP_mum)), 'guardian', 'a guardian reads their child\'s space');
mam_db_update('parent_links', ['access_scope' => 'billing'], ['parent_id' => $SP_mum, 'student_id' => $SP_amy]);
t_eq(mam_space_role($SP_s, $SP_user($SP_mum)), null, '…unless their access is billing-only');
mam_db_update('parent_links', ['access_scope' => 'standard'], ['parent_id' => $SP_mum, 'student_id' => $SP_amy]);
t_throws(fn() => mam_space_post($SP_s, $SP_user($SP_mum), ['body' => 'Hello class']), 'forbidden', 'a guardian writes nothing in a class space');

mam_space_update($SP_id, ['visibility' => 'private'], $SP_teach);
$SP_s = mam_space_get($SP_id);
t_eq(mam_space_role($SP_s, $SP_user($SP_amy)), null, 'made private, the class is no longer in it automatically');
t_eq(mam_space_role($SP_s, $SP_user($SP_teach)), 'teacher', 'but its teacher still is');
$SP_add = mam_space_members_add($SP_s, [$SP_amy, $SP_out], 'student', $SP_user($SP_teach));
t_eq([$SP_add['added'], count($SP_add['refused'])], [1, 1], 'a teacher can add a student they teach and is refused one they do not');
t_eq(mam_space_role($SP_s, $SP_user($SP_amy)), 'student', 'the added student is in');
mam_space_update($SP_id, ['visibility' => 'class'], $SP_teach);
$SP_s = mam_space_get($SP_id);
mam_space_member_remove($SP_s, $SP_ben, $SP_user($SP_teach));
t_eq(mam_space_role($SP_s, $SP_user($SP_ben)), null, 'removing somebody takes them out even of a class space');
t_throws(fn() => mam_space_create('Loose', 'class', $SP_admin), null, 'a space with no class must be private');

t_group('class spaces — handing work in');

$SP_task = mam_space_task_save($SP_s, ['title' => 'Play the G major scale', 'wants' => 'video', 'points' => 20], $SP_user($SP_teach));
$SP_vid = static function (int $owner, string $kind = 'video') {
    $ext = $kind === 'video' ? 'mp4' : 'jpg';
    $b = mam_media_begin($owner, 'community', "a.$ext", $kind === 'video' ? 'video/mp4' : 'image/jpeg', 3);
    $st = fopen('php://memory', 'w+'); fwrite($st, 'abc'); rewind($st);
    mam_media_local_receive($b['id'], $owner, $st);
    if ($kind === 'video') mam_media_finish($b['id'], $owner);
    else mam_db_update('media_objects', ['status' => 'uploaded'], ['id' => $b['id']]);
    return (int) $b['id'];
};
t_throws(fn() => mam_space_post($SP_s, $SP_user($SP_amy), ['kind' => 'submission', 'task_id' => $SP_task, 'body' => 'here']), null,
    'a video task needs a video');
$SP_m1 = $SP_vid($SP_amy);
$SP_p1 = mam_space_post($SP_s, $SP_user($SP_amy), ['kind' => 'submission', 'task_id' => $SP_task, 'media_id' => $SP_m1, 'body' => 'My scale']);
t_eq($SP_p1['audience'], 'teacher', 'a minor with no media consent: only the teacher sees the video, whatever the task says');
t_ok(str_contains($SP_p1['note'], 'family has not yet agreed'), '…and the student is told why, in a sentence');
t_eq($SP_p1['status'], 'held', 'a student\'s video waits for a teacher (review_media is on by default)');
t_eq((string) mam_db_row('SELECT scope_type FROM media_objects WHERE id = ?', [$SP_m1])['scope_type'], 'space', 'the file is now attached to the space');
t_throws(fn() => mam_space_post($SP_s, $SP_user($SP_ben), ['body' => 'x', 'media_id' => $SP_m1]), 'forbidden',
    'nobody can post another person\'s file (and a removed member cannot post at all)');

mam_consent_set($SP_amy, ['media' => true], $SP_mum);
$SP_m2 = $SP_vid($SP_amy);
$SP_p2 = mam_space_post($SP_s, $SP_user($SP_amy), ['kind' => 'submission', 'task_id' => $SP_task, 'media_id' => $SP_m2]);
t_eq($SP_p2['id'], $SP_p1['id'], 'a resubmission before marking replaces the first, it does not pile up');
t_eq($SP_p2['audience'], 'class', 'with consent recorded, the task\'s own sharing applies');
t_eq((string) mam_db_row('SELECT status FROM media_objects WHERE id = ?', [$SP_m1])['status'], 'deleted', 'the replaced video is deleted, not left behind');

$SP_feed = static fn(int $uid) => array_column(mam_space_feed(mam_space_get($SP_id), $SP_user($uid))['posts'], null, 'id');
mam_db_update('space_members', ['status' => 'active'], ['space_id' => $SP_id, 'user_id' => $SP_ben]);
t_ok(!isset($SP_feed($SP_ben)[$SP_p1['id']]), 'a classmate does not see a held post');
t_eq($SP_feed($SP_amy)[$SP_p1['id']]['status'] ?? null, 'held', 'its author sees it waiting');
t_ok(isset($SP_feed($SP_mum)[$SP_p1['id']]), 'and so does their guardian');
t_ok(!mam_media_can_read(mam_db_row('SELECT * FROM media_objects WHERE id = ?', [$SP_m2]), $SP_user($SP_ben)), 'the video itself cannot be fetched by a classmate while held');
mam_space_moderate($SP_user($SP_teach), 'post', $SP_p1['id'], 'approve');
t_ok(isset($SP_feed($SP_ben)[$SP_p1['id']]), 'approved, the class sees it');
t_ok(mam_media_can_read(mam_db_row('SELECT * FROM media_objects WHERE id = ?', [$SP_m2]), $SP_user($SP_ben)), '…and can play it');
t_ok(!mam_media_can_read(mam_db_row('SELECT * FROM media_objects WHERE id = ?', [$SP_m2]), $SP_user($SP_out)), 'somebody outside the space never can');

$SP_q = mam_space_queue($SP_user($SP_teach));
t_ok(is_array($SP_q['held']) && is_array($SP_q['to_mark']), 'the teacher\'s queue reads held items and work to mark (a UNION SQLite can order)');

t_group('class spaces — a mark is private');

t_throws(fn() => mam_space_review($SP_p1['id'], $SP_user($SP_ben), 18, 'Nice'), 'forbidden', 'a classmate cannot mark');
t_throws(fn() => mam_space_review($SP_p1['id'], $SP_user($SP_teach), 21, 'Nice'), null, 'a mark above the task\'s points is refused');
t_throws(fn() => mam_space_review($SP_p1['id'], $SP_user($SP_teach), 18, ''), null, 'a mark with no words is refused');
mam_space_review($SP_p1['id'], $SP_user($SP_teach), 18, 'Lovely tone; watch the F sharp at 0:12.');
t_eq([$SP_feed($SP_amy)[$SP_p1['id']]['score'], $SP_feed($SP_amy)[$SP_p1['id']]['max_score']], [18, 20], 'the student sees their mark');
t_eq($SP_feed($SP_mum)[$SP_p1['id']]['score'], 18, 'their guardian sees it');
$SP_benview = $SP_feed($SP_ben)[$SP_p1['id']];
t_ok($SP_benview['reviewed'] && $SP_benview['score'] === null && $SP_benview['feedback'] === null, 'a classmate sees only that it was reviewed — never the mark or the words');
t_throws(fn() => mam_space_post_remove($SP_p1['id'], $SP_user($SP_amy)), null, 'marked work stays on the record');
$SP_m3 = $SP_vid($SP_amy);
$SP_p3 = mam_space_post($SP_s, $SP_user($SP_amy), ['kind' => 'submission', 'task_id' => $SP_task, 'media_id' => $SP_m3]);
t_ok($SP_p3['id'] !== $SP_p1['id'], 'after marking, handing in again is a new attempt');

t_group('class spaces — screening words');

t_eq(mam_space_screen('Call me on 0803 123 4567')['hold'] !== null, true, 'a phone number is held');
t_eq(mam_space_screen('add me on snap')['hold'] !== null, true, 'an invitation to talk somewhere else is held');
t_eq(mam_space_screen('www.example.com is cool')['hold'] !== null, true, 'a link is held');
t_eq(mam_space_screen('you are so stupid')['hold'] !== null, true, 'an unkind word is held');
t_eq(mam_space_screen('I practised for 45 minutes in 2026 and it went well!')['hold'], null, 'ordinary practice talk, with a number and a year, is not');
$SP_hit = mam_space_screen('my uncle hurts me when nobody is home');
t_ok($SP_hit['safeguarding'] && $SP_hit['hold'] !== null, 'words about harm are held AND marked for safeguarding');
$SP_before = (int) mam_db_row("SELECT COUNT(*) AS n FROM safeguarding_concerns WHERE source = 'automatic'")['n'];
$SP_c = mam_space_comment($SP_p1['id'], $SP_user($SP_ben), 'he hits me at home, I am scared to go home');
t_eq($SP_c['status'], 'held', 'such a comment is held');
t_eq((int) mam_db_row("SELECT COUNT(*) AS n FROM safeguarding_concerns WHERE source = 'automatic'")['n'], $SP_before + 1, '…and opens an automatic safeguarding concern');
t_throws(fn() => mam_space_moderate($SP_user($SP_teach), 'comment', $SP_c['id'], 'approve'), null,
    'a teacher cannot wave through what the safeguarding officer is looking at');
$SP_tn = mam_space_post($SP_s, $SP_user($SP_teach), ['kind' => 'notice', 'body' => 'WhatsApp me on 08031234567 for extra lessons']);
t_eq($SP_tn['status'], 'held', 'an adult sharing a private contact is held too');
t_throws(fn() => mam_space_moderate($SP_user($SP_teach), 'post', $SP_tn['id'], 'approve'), null,
    'and a teacher never decides their own held post');
mam_space_moderate($SP_user($SP_admin), 'post', $SP_tn['id'], 'remove', 'Private contact with students is not allowed.');
t_eq((string) mam_db_row('SELECT status FROM space_posts WHERE id = ?', [$SP_tn['id']])['status'], 'removed', 'an admin decides it');

t_group('class spaces — reports');

$SP_open = mam_space_post($SP_s, $SP_user($SP_teach), ['kind' => 'notice', 'body' => 'Bring your music stand on Friday.']);
t_eq($SP_open['status'], 'visible', 'an ordinary teacher notice is up at once');
$SP_r = mam_space_report($SP_user($SP_ben), 'post', $SP_open['id'], 'unsafe', 'This feels wrong');
t_ok($SP_r['held'], 'one "unsafe" report hides it at once');
t_ok((bool) mam_db_row("SELECT 1 FROM safeguarding_concerns WHERE ref_type = 'post' AND ref_id = ? AND raised_by = ?", [$SP_open['id'], $SP_ben]),
    '…and opens a concern in the reporter\'s name');
$SP_kind = mam_space_post($SP_s, $SP_user($SP_amy), ['body' => 'Practice went well today']);
mam_space_report($SP_user($SP_ben), 'post', $SP_kind['id'], 'spam');
t_eq((string) mam_db_row('SELECT status FROM space_posts WHERE id = ?', [$SP_kind['id']])['status'], 'visible', 'one ordinary report does not hide a post');
t_eq(mam_space_report($SP_user($SP_ben), 'post', $SP_kind['id'], 'spam')['held'], false, 'reporting twice counts once');

t_group('class spaces — comments at a moment in the video');

$SP_tc = mam_space_comment($SP_p1['id'], $SP_user($SP_teach), 'Here — lift the bow.', 12.4);
$SP_list = $SP_feed($SP_amy)[$SP_p1['id']]['comments'];
t_eq((float) end($SP_list)['at_sec'], 12.4, 'a teacher comment can point at a moment in the video');
mam_space_update($SP_id, ['students_comment' => 0], $SP_teach);
t_throws(fn() => mam_space_comment($SP_p1['id'], $SP_user($SP_ben), 'great'), null, 'students can be kept to reactions only');

t_group('safeguarding — concerns');

t_throws(fn() => mam_sg_raise($SP_amy, ['category' => 'wellbeing', 'body' => 'sad']), null, 'a concern needs a few words');
$SP_cid = mam_sg_raise($SP_amy, ['category' => 'conduct', 'body' => 'A teacher asked me to message them privately.', 'about_id' => $SP_other]);
t_ok($SP_cid > 0, 'a student can raise one');
t_throws(fn() => mam_sg_update($SP_cid, $SP_admin, ['status' => 'closed', 'outcome' => '']), null, 'it cannot be closed without saying what was decided');
mam_sg_update($SP_cid, $SP_admin, ['status' => 'assessing', 'note' => 'Spoke to the student.', 'take' => true]);
$SP_g = mam_sg_get($SP_cid);
t_ok($SP_g['acknowledged_at'] !== null && (int) $SP_g['assigned_to'] === $SP_admin && count($SP_g['notes']) === 1, 'working it records who, when, and a note');
t_ok(!mam_sg_can_manage($SP_user($SP_teach)), 'an ordinary teacher cannot read the queue');
t_ok(mam_sg_can_manage($SP_user($SP_admin)), 'a super admin can');

t_group('safeguarding — every route reaches the officer');

$SP_tid = mam_thread_open($SP_amy, 'Lessons', 'general', $SP_mum);
$SP_before2 = (int) mam_db_row("SELECT COUNT(*) AS n FROM safeguarding_concerns WHERE ref_type = 'thread' AND ref_id = ?", [$SP_tid])['n'];
mam_thread_post($SP_tid, $SP_mum, 'She told me someone hits me— sorry, hits her, at the bus stop.');
t_eq((int) mam_db_row("SELECT COUNT(*) AS n FROM safeguarding_concerns WHERE ref_type = 'thread' AND ref_id = ?", [$SP_tid])['n'], $SP_before2 + 1,
    'words about harm in a family message open a concern, whatever the thread was about');
$SP_tp = (string) file_get_contents(__DIR__ . '/../routes/parent/thread-post.php');
t_ok(str_contains($SP_tp, 'mam_sg_raise') && !str_contains($SP_tp, "mam_fail('use_safeguarding_route'"), 'a guardian\'s safeguarding message becomes a concern, not a refusal');
$SP_adm = (string) file_get_contents(__DIR__ . '/../../admin/index.php');
t_ok(str_contains($SP_adm, "'dso_set' => 'users'") && str_contains($SP_adm, "if (\$action === 'dso_set')"), 'the console can name the safeguarding officer');
$SP_vt = (string) file_get_contents(__DIR__ . '/../../partials/admin-console/vetting.php');
t_ok(str_contains($SP_vt, "ac_form('vetting_record'"), 'the console has a form to record a vetting check (it had a handler and no button)');
