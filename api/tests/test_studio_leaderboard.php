<?php
/**
 * Studio leaderboard (S2) — opt-in cohort competition. Best-per-(user,tool),
 * explicit submission, PII-minimised reads (first names only), and a 1-based
 * rank for the caller.
 */
declare(strict_types=1);
t_group('studio leaderboard (opt-in)');

$a = mam_db_insert('users', ['email' => 't_lb_a@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Ada Obi', 'status' => 'active']);
$b = mam_db_insert('users', ['email' => 't_lb_b@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Bode Eze', 'status' => 'active']);
$c = mam_db_insert('users', ['email' => 't_lb_c@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Chidi', 'status' => 'active']);

// ── Validation ──
$threw = false; try { mam_studio_submit_score($a, 'metronome', 50); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'invalid_tool'; }
t_ok($threw, 'an unscored tool is rejected (invalid_tool)');
$threw = false; try { mam_studio_submit_score($a, 'rhythm', 150); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'invalid_score'; }
t_ok($threw, 'an out-of-range score is rejected (invalid_score)');

// ── Submit upserts the BEST and counts plays ──
$r1 = mam_studio_submit_score($a, 'rhythm', 70);
t_ok($r1['best'] === 70 && $r1['improved'] === true && $r1['plays'] === 1, 'first post sets best + plays=1');
$r2 = mam_studio_submit_score($a, 'rhythm', 60); // worse → best unchanged, plays++
t_ok($r2['best'] === 70 && $r2['improved'] === false && $r2['plays'] === 2, 'a worse run keeps the best but counts the play');
$r3 = mam_studio_submit_score($a, 'rhythm', 88); // better → best rises
t_ok($r3['best'] === 88 && $r3['improved'] === true && $r3['plays'] === 3, 'a better run raises the best');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM tool_scores WHERE user_id = ? AND tool = ?', [$a, 'rhythm'])['n'], 1, 'exactly one row per (user, tool)');

// Other students post.
mam_studio_submit_score($b, 'rhythm', 95);
mam_studio_submit_score($c, 'rhythm', 80);
// Different tool is independent.
mam_studio_submit_score($a, 'pitchgame', 50);

// ── Leaderboard: ordered, first-names only ──
$board = mam_studio_leaderboard('rhythm', 10);
t_eq(count($board), 3, 'leaderboard lists all three rhythm posters');
t_eq($board[0]['name'], 'Bode', 'top scorer first — first name ONLY (no surname)');
t_eq($board[0]['best_score'], 95, 'top score is 95');
t_eq($board[0]['rank'], 1, 'ranks are 1-based');
t_eq($board[1]['name'], 'Ada', 'second place by best score');
t_eq($board[2]['name'], 'Chidi', 'third place');
t_ok(mam_studio_leaderboard('metronome') === [], 'an unscored tool has no leaderboard');

// ── My rank ──
t_eq(mam_studio_rank($a, 'rhythm')['rank'], 2, 'Ada ranks 2nd on rhythm');
t_eq(mam_studio_rank($b, 'rhythm')['rank'], 1, 'Bode ranks 1st on rhythm');
t_eq(mam_studio_rank($a, 'pitchgame')['rank'], 1, 'Ada is 1st on pitchgame (independent board)');
t_ok(mam_studio_rank($a, 'metronome') === null, 'rank for an unscored tool is null');
t_ok(mam_studio_rank($b, 'pitchgame') === null, 'a student who never posted has no rank');

// A suspended student drops off the public board.
mam_db_update('users', ['status' => 'suspended'], ['id' => $b]);
$board2 = mam_studio_leaderboard('rhythm', 10);
t_eq($board2[0]['name'], 'Ada', 'a suspended student is excluded from the board');

// ── Trinity-aligned band ──
t_eq(mam_studio_band(90), 'Distinction', '90 → Distinction');
t_eq(mam_studio_band(70), 'Merit', '70 → Merit');
t_eq(mam_studio_band(60), 'Pass', '60 → Pass');
t_eq(mam_studio_band(40), 'Keep practising', '40 → Keep practising');

// ── Shareable SVG card (S2) ──
$svg = mam_studio_share_svg('rhythm', 92, 'Ada Obi');
t_ok(strpos($svg, '<svg') !== false && strpos($svg, '</svg>') !== false, 'card is a complete SVG document');
t_ok(strpos($svg, 'width="1200"') !== false && strpos($svg, 'height="630"') !== false, 'card uses social 1200x630 dimensions');
t_ok(strpos($svg, '>92<') !== false, 'card shows the score');
t_ok(strpos($svg, 'Distinction') !== false, 'card shows the Trinity band');
t_ok(strpos($svg, 'Ada scored') !== false, 'card shows the first name only');
t_ok(strpos($svg, 'Obi') === false, 'card never includes the surname (PII-min)');
t_ok(strpos($svg, 'Rhythm Tap') !== false, 'card shows the tool label');

// Score clamped; an unknown tool falls back; the name is XML-escaped.
t_ok(strpos(mam_studio_share_svg('rhythm', 250, ''), '>100<') !== false, 'an over-range score is clamped to 100');
t_ok(strpos(mam_studio_share_svg('rhythm', -5, ''), '>0<') !== false, 'a negative score is clamped to 0');
t_ok(strpos(mam_studio_share_svg('bogus', 50, ''), 'Practice Studio') !== false, 'an unknown tool falls back to a generic label');
$xss = mam_studio_share_svg('rhythm', 80, '<script>x</script>');
t_ok(strpos($xss, '<script>') === false, 'the name is XML-escaped (no raw markup injected)');
