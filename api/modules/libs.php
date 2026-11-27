<?php
/**
 * Every library the application loads, in load order — resolved from the
 * module manifests (api/modules/registry.php + api/modules/<name>/module.php).
 *
 * Returns [lib name => absolute path]. bootstrap.php and the test harness
 * (api/tests/run.php) both read this, so there is still exactly one list.
 *
 * It refuses to resolve (throws) when the manifests are inconsistent: a lib
 * named twice, a file that is not there, or a dependency on an unknown module.
 * (`depends` is not a load order: libraries only declare functions, which PHP
 * resolves when called, so modules may call each other in both directions.) A broken manifest therefore fails every request
 * and the suite, rather than silently leaving functions undefined.
 */
declare(strict_types=1);

return (static function (): array {
    $root     = __DIR__;
    $order    = require $root . '/registry.php';
    $position = array_flip($order);
    $libs     = [];
    foreach ($order as $module) {
        $manifest = require $root . '/' . $module . '/module.php';
        if (($manifest['name'] ?? null) !== $module) {
            throw new RuntimeException("modules: manifest name mismatch for '$module'");
        }
        foreach ((array) ($manifest['depends'] ?? []) as $dep) {
            if ($dep === '*') continue;
            if (!isset($position[$dep])) throw new RuntimeException("modules: '$module' depends on unknown module '$dep'");
        }
        foreach ((array) ($manifest['libs'] ?? []) as $lib) {
            if (isset($libs[$lib])) throw new RuntimeException("modules: lib '$lib' is listed twice");
            $path = $root . '/' . $module . '/lib/' . $lib . '.php';
            if (!is_file($path)) throw new RuntimeException("modules: '$module' lists '$lib' but $path is missing");
            $libs[$lib] = $path;
        }
    }
    return $libs;
})();
