<?php
/**
 * The module call graph, measured from the source.
 *
 * mam_module_graph() tokenises every library the manifests list, finds each
 * call to a function another module defines, and returns
 * [caller module => [callee module => number of call sites]]. Comments and
 * strings are tokens of their own, so a function named in prose is not a call.
 *
 * Used by api/tests/test_module_boundaries.php (every measured edge must be
 * declared in the caller's `depends`) and by `php api/tools/module-graph.php`.
 * Not loaded by bootstrap.
 */
declare(strict_types=1);

function mam_module_graph(string $apiRoot): array
{
    $libs   = require $apiRoot . '/modules/libs.php';
    $owner  = [];   // function (lowercase) => module
    $moduleOf = static fn(string $path): string => basename(dirname($path, 2));
    foreach ($libs as $path) {
        foreach (mam_module_graph_tokens($path) as $i => $t) {
            if (is_array($t) && $t[0] === T_FUNCTION) {
                $name = mam_module_graph_next_name($path, $i);
                if ($name !== null) $owner[strtolower($name)] = $moduleOf($path);
            }
        }
    }
    $edges = [];
    foreach ($libs as $path) {
        $m = $moduleOf($path);
        $toks = mam_module_graph_tokens($path);
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($toks[$i]) || $toks[$i][0] !== T_STRING) continue;
            $fn = strtolower($toks[$i][1]);
            if (!isset($owner[$fn]) || $owner[$fn] === $m) continue;
            $j = $i + 1;
            while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
            if ($j >= $n || $toks[$j] !== '(') continue;
            $p = $i - 1;
            while ($p >= 0 && is_array($toks[$p]) && $toks[$p][0] === T_WHITESPACE) $p--;
            if ($p >= 0 && is_array($toks[$p]) && in_array($toks[$p][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) continue;
            $edges[$m][$owner[$fn]] = ($edges[$m][$owner[$fn]] ?? 0) + 1;
        }
    }
    ksort($edges);
    foreach ($edges as &$to) ksort($to);
    return $edges;
}

/** @internal */
function mam_module_graph_tokens(string $path): array
{
    static $cache = [];
    return $cache[$path] ??= token_get_all((string) file_get_contents($path));
}

/** @internal The name after a `function` token, or null for a closure. */
function mam_module_graph_next_name(string $path, int $i): ?string
{
    $toks = mam_module_graph_tokens($path);
    for ($j = $i + 1, $n = count($toks); $j < $n; $j++) {
        $t = $toks[$j];
        if (is_array($t) && $t[0] === T_WHITESPACE) continue;
        if ($t === '&') continue;
        return (is_array($t) && $t[0] === T_STRING) ? $t[1] : null;
    }
    return null;
}
