<?php
/**
 * MAM Academy — assignment-group backfill (LMS 2.0 Part B) · CLI / one-shot.
 * ─────────────────────────────────────────────────────────────
 * Before v63, assignments existed only as per-student rows with no parent. This
 * groups the pre-existing rows: every distinct (class_id, author_id, title,
 * due_on) among assignments with a NULL group_id becomes one assignment_group,
 * and its members get group_id set. Purely additive — no assignment row is
 * deleted or otherwise changed beyond gaining a group_id. Idempotent: rows that
 * already carry a group_id are ignored, so it is safe to re-run.
 *
 *   php api/tools/backfill-assignment-groups.php --dry-run   # preview
 *   php api/tools/backfill-assignment-groups.php             # apply
 *
 * Exit: 0 success, 1 invalid usage / error.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "This tool is CLI-only.\n"; exit(1); }
require_once __DIR__ . '/../lib/bootstrap.php';

$dryRun = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run' || $a === '-n') { $dryRun = true; continue; }
    fwrite(STDERR, "Unknown argument: $a\nUsage: php backfill-assignment-groups.php [--dry-run]\n");
    exit(1);
}

$db = mam_db(); // lazily migrates, so group_id/assignment_groups exist

// Distinct orphan tuples → one group each. NULLs are normalised so rows with a
// missing class_id/due_on still group together deterministically.
$tuples = mam_db_all(
    "SELECT class_id, author_id, title, due_on, COUNT(*) AS n
       FROM assignments
      WHERE group_id IS NULL
      GROUP BY class_id, author_id, title, due_on
      ORDER BY title"
);

echo "── assignment-group backfill ", ($dryRun ? "(DRY RUN — no writes)" : "(APPLYING)"), " ──\n";
echo "orphan assignment groups to create: ", count($tuples), "\n";

$groups = 0; $linked = 0;
if (!$dryRun && $tuples) $db->beginTransaction();
try {
    foreach ($tuples as $t) {
        $classId  = $t['class_id']  !== null ? (int) $t['class_id']  : null;
        $authorId = $t['author_id'] !== null ? (int) $t['author_id'] : null;
        $title    = (string) $t['title'];
        $dueOn    = $t['due_on'] !== null ? (string) $t['due_on'] : null;
        $n        = (int) $t['n'];

        if ($groups < 10) {
            echo sprintf("  \"%s\" · class=%s author=%s due=%s → %d assignment(s)\n",
                mb_strimwidth($title, 0, 40, '…'), $classId ?? '—', $authorId ?? '—', $dueOn ?? '—', $n);
        }
        if ($dryRun) { $groups++; $linked += $n; continue; }

        $gid = mam_db_insert('assignment_groups', [
            'class_id' => $classId, 'author_id' => $authorId, 'title' => $title, 'due_on' => $dueOn,
        ]);
        // Link members. Match NULLs with IS NULL, not = NULL.
        $where = 'group_id IS NULL AND title = ?';
        $args  = [$title];
        $where .= $classId  === null ? ' AND class_id IS NULL'  : ' AND class_id = ?';
        if ($classId !== null) $args[] = $classId;
        $where .= $authorId === null ? ' AND author_id IS NULL' : ' AND author_id = ?';
        if ($authorId !== null) $args[] = $authorId;
        $where .= $dueOn    === null ? ' AND due_on IS NULL'    : ' AND due_on = ?';
        if ($dueOn !== null) $args[] = $dueOn;
        $stmt = $db->prepare("UPDATE assignments SET group_id = " . (int) $gid . " WHERE $where");
        $stmt->execute($args);
        $linked += $stmt->rowCount();
        $groups++;
    }
    if (!$dryRun && $tuples) $db->commit();
} catch (\Throwable $e) {
    if (!$dryRun && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo ($dryRun ? "would create " : "created "), $groups, " group(s); ",
     ($dryRun ? "would link " : "linked "), $linked, " assignment(s)\n";
if (!$dryRun) {
    $remaining = (int) (mam_db_row('SELECT COUNT(*) AS c FROM assignments WHERE group_id IS NULL')['c'] ?? 0);
    echo "check    : assignments still ungrouped = ", $remaining, "\n";
}
echo "done", ($dryRun ? " (dry run — nothing written)" : ""), ".\n";
exit(0);
