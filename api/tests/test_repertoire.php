<?php
declare(strict_types=1);
t_group('repertoire → recital (§1.7)');
$RP_stu = mam_db_insert('users', ['name' => 'RP Stu', 'email' => 'rp_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$RP_oth = mam_db_insert('users', ['name' => 'RP Oth', 'email' => 'rp_oth@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);

$p1 = mam_repertoire_add($RP_stu, 'Für Elise', 'Beethoven', 'learning', 'bars 1-8');
$p2 = mam_repertoire_add($RP_stu, 'Minuet in G', 'Bach', 'polished');
t_ok($p1 > 0 && $p2 > 0, 'pieces added');
t_eq(count(mam_repertoire_list($RP_stu)), 2, 'both pieces listed');

// Blank title rejected.
$threw = false; try { mam_repertoire_add($RP_stu, '   '); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage() === 'title_required'; }
t_ok($threw, 'a blank title is rejected');

// Programme = polished + performed only.
t_eq(count(mam_repertoire_programme($RP_stu)), 1, 'programme has the one polished piece');
mam_repertoire_update($p1, $RP_stu, ['status' => 'performed']);
t_eq(count(mam_repertoire_programme($RP_stu)), 2, 'advancing to performed adds it to the programme');

// Ownership guards.
$threw = false; try { mam_repertoire_update($p1, $RP_oth, ['status' => 'learning']); } catch (\RuntimeException $e) { $threw = $e->getMessage() === 'forbidden'; }
t_ok($threw, 'a student cannot edit another student\'s piece');
$threw = false; try { mam_repertoire_delete($p2, $RP_oth); } catch (\RuntimeException $e) { $threw = $e->getMessage() === 'forbidden'; }
t_ok($threw, 'a student cannot delete another student\'s piece');

// Delete.
t_ok(mam_repertoire_delete($p2, $RP_stu), 'owner deletes a piece');
t_eq(count(mam_repertoire_list($RP_stu)), 1, 'delete removes it');
