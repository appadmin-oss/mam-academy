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

function mam_test_summary(): void {
    $t = $GLOBALS['__t'];
    echo "\n" . ($t['fail'] ? '❌ FAIL' : '✅ PASS') . ": {$t['pass']} passed, {$t['fail']} failed\n";
    exit($t['fail'] ? 1 : 0);
}
