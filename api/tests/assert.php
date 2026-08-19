<?php
/** Minimal assertion helpers + result tally for run.php. */
declare(strict_types=1);

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0];

function t_group(string $name): void { echo "▸ $name\n"; }

function t_ok($cond, string $msg): void {
    if ($cond) { $GLOBALS['__t']['pass']++; echo "  ✓ $msg\n"; }
    else       { $GLOBALS['__t']['fail']++; echo "  ✗ FAIL: $msg\n"; }
}

function t_eq($got, $want, string $msg): void {
    t_ok($got == $want, $msg . ' [got ' . var_export($got, true) . ', want ' . var_export($want, true) . ']');
}

/** Float compare with a small tolerance. */
function t_near($got, $want, string $msg, float $eps = 0.01): void {
    t_ok(is_numeric($got) && abs((float)$got - (float)$want) <= $eps, $msg . ' [got ' . var_export($got, true) . ', want ≈' . $want . ']');
}

/**
 * A callable must throw, and the message must be the one named.
 *
 * Written because a whole subsystem's value can be a REFUSAL — reports.php
 * refuses to ratify an unmoderated mark, and its failure paths matter more than
 * its success path — and the try/catch that asserts one is four lines of
 * boilerplate that is easy to get subtly wrong. The commonest way: a `try { …;
 * t_ok(false, …) } catch { t_ok(true, …) }` in which the call does not throw at
 * all AND the t_ok(false) line was never reached because an earlier line threw.
 * Here the failure to throw is itself reported.
 *
 * `$want` is matched against the exception MESSAGE, which is how this codebase
 * signals ('cohort_full', 'appeal_open'). Pass null to accept any throw.
 */
function t_throws(callable $fn, ?string $want, string $msg): void {
    try {
        $fn();
        t_ok(false, $msg . ' [did NOT throw' . ($want !== null ? ", wanted '$want'" : '') . ']');
    } catch (\Throwable $e) {
        if ($want === null) { t_ok(true, $msg . ' [threw ' . get_class($e) . ']'); return; }
        t_ok($e->getMessage() === $want, $msg . " [threw '" . $e->getMessage() . "', wanted '$want']");
    }
}

function mam_test_summary(): void {
    $t = $GLOBALS['__t'];
    echo "\n" . ($t['fail'] ? '❌ FAIL' : '✅ PASS') . ": {$t['pass']} passed, {$t['fail']} failed\n";
    exit($t['fail'] ? 1 : 0);
}
