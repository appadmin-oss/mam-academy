<?php
/**
 * Print the measured module call graph: which module calls which, and how
 * often. Usage: php api/tools/module-graph.php [--undeclared]
 *
 * --undeclared prints only edges missing from the caller's manifest `depends`
 * (the same check api/tests/test_module_boundaries.php makes).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$api = dirname(__DIR__);
require $api . '/modules/graph.php';
$only = in_array('--undeclared', $argv, true);
foreach (mam_module_graph($api) as $from => $to) {
    $declared = (require $api . "/modules/$from/module.php")['depends'] ?? [];
    $parts = [];
    foreach ($to as $m => $n) {
        $missing = !in_array($m, $declared, true) && !in_array('*', $declared, true);
        if ($only && !$missing) continue;
        $parts[] = $m . "($n)" . ($missing ? ' UNDECLARED' : '');
    }
    if ($parts) echo str_pad($from, 13), ' -> ', implode(', ', $parts), "\n";
}
