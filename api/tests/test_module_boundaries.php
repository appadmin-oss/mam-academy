<?php
/**
 * Module boundaries — the libraries live in domain modules (api/modules/), and
 * which module may call which is written down in each module's manifest.
 *
 * The graph is MEASURED from the source (api/modules/graph.php), never
 * hand-kept: a call into another module that the caller's `depends` does not
 * name fails here. New coupling is therefore a deliberate, reviewable one-line
 * edit to a manifest instead of something that accretes unnoticed. The
 * existing tangles (the kernel calling into domain modules, cycles between
 * academics and live) are listed in api/modules/README.md as debt to pay down;
 * removing one means deleting it from `depends` too, which this test then holds.
 */
declare(strict_types=1);

require_once MAM_API_ROOT . '/modules/graph.php';

t_group('modules — registry and manifests agree');

$MB_root     = MAM_API_ROOT . '/modules';
$MB_registry = require $MB_root . '/registry.php';
$MB_dirs     = array_map('basename', glob($MB_root . '/*', GLOB_ONLYDIR) ?: []);
sort($MB_dirs);
$MB_sorted = $MB_registry;
sort($MB_sorted);
t_eq($MB_sorted, $MB_dirs, 'every module folder is in the registry, and every registered module has a folder');
t_eq(count($MB_registry), count(array_unique($MB_registry)), 'the registry names each module once');
foreach ($MB_registry as $MB_m) {
    $MB_man = require "$MB_root/$MB_m/module.php";
    t_eq($MB_man['name'] ?? null, $MB_m, "module $MB_m: manifest name matches its folder");
    t_ok(!in_array($MB_m, (array) ($MB_man['depends'] ?? []), true), "module $MB_m does not list itself as a dependency");
}
t_ok(is_file($MB_root . '/README.md'), 'the module map and its rules are written down (api/modules/README.md)');

t_group('modules — every cross-module call is declared');

$MB_graph = mam_module_graph(MAM_API_ROOT);
t_ok(isset($MB_graph['learning']['kernel']), 'the analyser sees calls (sanity: learning calls the kernel)');
$MB_undeclared = [];
$MB_stale      = [];
foreach ($MB_registry as $MB_m) {
    $MB_dep = (array) ((require "$MB_root/$MB_m/module.php")['depends'] ?? []);
    foreach (array_keys($MB_graph[$MB_m] ?? []) as $MB_to) {
        if (!in_array($MB_to, $MB_dep, true)) $MB_undeclared[] = "$MB_m -> $MB_to";
    }
    foreach ($MB_dep as $MB_to) {
        if (!isset($MB_graph[$MB_m][$MB_to])) $MB_stale[] = "$MB_m -> $MB_to";
    }
}
t_eq($MB_undeclared, [], 'no module calls into a module its manifest does not name (php api/tools/module-graph.php --undeclared)');
t_eq($MB_stale, [], 'no manifest declares a dependency it no longer uses — remove it so the debt list stays honest');

t_group('modules — the kernel does not grow new dependencies');

/* The kernel is meant to depend on nothing. Today it reaches into five modules
   (see the README's debt list). This pins that set so it can only shrink. */
$MB_kernelDebt = ['academics', 'finance', 'identity', 'integrations', 'learning'];  // admissions left 2026-11-28 (the dead draft-nudge job went)
$MB_kernelNow  = array_keys($MB_graph['kernel'] ?? []);
t_eq(array_values(array_diff($MB_kernelNow, $MB_kernelDebt)), [],
    'the kernel calls no module beyond its recorded debt — move the logic to its domain instead');

t_group('modules — not reachable from the web');

/* api/lib was denied twice (its own .htaccess and a RedirectMatch in
   api/.htaccess). Moving the libraries must not leave them executable by URL. */
t_eq(trim((string) @file_get_contents($MB_root . '/.htaccess')), 'Require all denied', 'api/modules/.htaccess denies every request');
t_ok((bool) preg_match('#RedirectMatch 403 \^/api/\([^)]*\bmodules\b[^)]*\)#', (string) file_get_contents(MAM_API_ROOT . '/.htaccess')),
    'api/.htaccess answers 403 for /api/modules/* as it does for /api/lib/*');
