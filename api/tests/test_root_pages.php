<?php
/**
 * Root page scripts — static guards against the two fatals that shipped in
 * card.php and join.php and went unnoticed because NOTHING in the suite ever
 * loads a root `*.php` page.
 *
 *   R1 — a page must not declare a top-level function that a lib it requires
 *        also declares. PHP declares top-level functions at COMPILE time, before
 *        any `require_once` in the file runs, so such a stub is an unconditional
 *        "Cannot redeclare" fatal on every request — not a shadow, not a
 *        fallback. card.php and join.php both stubbed mam_log()/mam_audit()
 *        while also requiring modules/kernel/lib/log.php.
 *
 *   R2 — a page must not call a mam_* function that exists nowhere in the repo.
 *        card.php called mam_session_get(), which is not defined anywhere
 *        (the real API is mam_session_user()).
 *
 * Static on purpose: executing these pages would emit headers, exit(), and write
 * to the REAL api/storage dev DB. Parsing with token_get_all() is exact about
 * where a function is declared vs called, so there is no regex guesswork.
 */
declare(strict_types=1);
t_group('root page scripts (static fatal guards)');

$ROOT_DIR = dirname(MAM_API_ROOT);   // repo root; MAM_API_ROOT is .../api

/** Top-level (brace-depth 0) function declarations in a PHP file. */
function trp_declared_functions(string $file): array {
    $src = @file_get_contents($file);
    if ($src === false) return [];
    $t = @token_get_all($src);
    if (!$t) return [];
    $out = []; $depth = 0; $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        $tok = $t[$i];
        if (is_string($tok)) {
            if ($tok === '{') $depth++;
            elseif ($tok === '}') $depth--;
            continue;
        }
        if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
        if ($tok[0] !== T_FUNCTION) continue;
        // The next meaningful token is the name (skip whitespace and `&`).
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) continue;
            if (is_string($t[$j]) && $t[$j] === '&') continue;
            break;
        }
        // A name means a declaration; `(` means a closure/arrow fn — skip those.
        if ($j < $n && is_array($t[$j]) && $t[$j][0] === T_STRING && $depth === 0) {
            $out[strtolower($t[$j][1])] = true;
        }
    }
    return array_keys($out);
}

/** mam_* functions CALLED in a file (not declarations, not methods, not strings). */
function trp_called_mam_functions(string $file): array {
    $src = @file_get_contents($file);
    if ($src === false) return [];
    $t = @token_get_all($src);
    if (!$t) return [];
    $out = []; $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_STRING) continue;
        $name = strtolower($t[$i][1]);
        if (strncmp($name, 'mam_', 4) !== 0) continue;
        // Preceding meaningful token must not make this a declaration or a
        // method/static call (mam_* would then belong to a class, not a function).
        for ($p = $i - 1; $p >= 0; $p--) {
            if (is_array($t[$p]) && $t[$p][0] === T_WHITESPACE) continue;
            break;
        }
        if ($p >= 0 && is_array($t[$p])
            && in_array($t[$p][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) continue;
        // Following meaningful token must be `(` for this to be a call.
        for ($q = $i + 1; $q < $n; $q++) {
            if (is_array($t[$q]) && $t[$q][0] === T_WHITESPACE) continue;
            break;
        }
        if ($q < $n && is_string($t[$q]) && $t[$q] === '(') $out[$name] = true;
    }
    return array_keys($out);
}

/** The lib files a page pulls in, expanded through bootstrap's autoload list. */
function trp_required_libs(string $file, string $apiRoot): array {
    $src = (string)@file_get_contents($file);
    $libs = [];
    $all  = (array) require $apiRoot . '/modules/libs.php';   // name => path
    $libs = [];
    // A page names a library by its module path (…/modules/<m>/lib/<name>.php).
    if (preg_match_all('#/modules/[\w\-]+/lib/([\w\-]+)\.php[\'"]#', $src, $m)) {
        foreach ($m[1] as $name) $libs[$name] = true;
    }
    // A page that loads bootstrap.php gets everything bootstrap loads — the
    // list resolved from the module manifests by api/modules/libs.php.
    if (preg_match('#[\'"]/lib/bootstrap\.php[\'"]#', $src)) {
        foreach (array_keys($all) as $name) $libs[$name] = true;
    }
    $paths = [];
    foreach (array_keys($libs) as $name) {
        if (isset($all[$name]) && is_file($all[$name])) $paths[] = $all[$name];
    }
    return $paths;
}

// ── Every function name defined anywhere in the codebase (for R2) ─────────────
$allDefined = [];
foreach ([
    glob($ROOT_DIR . '/*.php') ?: [],
    glob(MAM_API_ROOT . '/lib/*.php') ?: [],
    glob(MAM_API_ROOT . '/modules/*/lib/*.php') ?: [],
    glob(MAM_API_ROOT . '/routes/*.php') ?: [],
    glob(MAM_API_ROOT . '/routes/*/*.php') ?: [],
    glob(MAM_API_ROOT . '/*.php') ?: [],
    glob($ROOT_DIR . '/partials/*.php') ?: [],
    glob($ROOT_DIR . '/admin/*.php') ?: [],
] as $set) {
    foreach ($set as $f) {
        // Include nested declarations too: a function defined inside an if() still exists.
        $src = (string)@file_get_contents($f);
        if (preg_match_all('/\bfunction\s+&?\s*([a-zA-Z_]\w*)\s*\(/', $src, $m)) {
            foreach ($m[1] as $fn) $allDefined[strtolower($fn)] = true;
        }
    }
}
t_ok(isset($allDefined['mam_session_user']), 'the scanner sees library functions (sanity check)');
t_ok(!isset($allDefined['mam_session_get']), 'sanity: mam_session_get really is not defined anywhere');

$pages = glob($ROOT_DIR . '/*.php') ?: [];
t_ok(count($pages) > 20, 'root pages were found to scan [' . count($pages) . ']');

// ── R1: no page may redeclare a function one of its own requires provides ─────
$clashes = [];
foreach ($pages as $page) {
    $declared = trp_declared_functions($page);
    if (!$declared) continue;
    $libFns = [];
    foreach (trp_required_libs($page, MAM_API_ROOT) as $lib) {
        foreach (trp_declared_functions($lib) as $fn) $libFns[$fn] = basename($lib);
    }
    foreach ($declared as $fn) {
        if (isset($libFns[$fn])) {
            $clashes[] = basename($page) . ' redeclares ' . $fn . '() from lib/' . $libFns[$fn];
        }
    }
}
t_eq(count($clashes), 0, 'R1: no root page redeclares a function its requires already define'
    . ($clashes ? ' — ' . implode('; ', $clashes) : ''));

// ── R2: no page may call a mam_* function that exists nowhere ────────────────
$undefined = [];
foreach ($pages as $page) {
    foreach (trp_called_mam_functions($page) as $fn) {
        if (!isset($allDefined[$fn])) $undefined[] = basename($page) . ' calls ' . $fn . '()';
    }
}
t_eq(count($undefined), 0, 'R2: no root page calls a mam_* function that does not exist'
    . ($undefined ? ' — ' . implode('; ', $undefined) : ''));

// ── The two regressions that motivated this file, named explicitly ───────────
$cardFns = trp_declared_functions($ROOT_DIR . '/card.php');
t_ok(!in_array('mam_log', $cardFns, true), 'card.php no longer stubs mam_log() (was a fatal on every request)');
t_ok(!in_array('mam_audit', $cardFns, true), 'card.php no longer stubs mam_audit()');
t_ok(!in_array('mam_automation_touch', $cardFns, true), 'card.php no longer stubs mam_automation_touch()');
t_ok(!in_array('mam_session_get', trp_called_mam_functions($ROOT_DIR . '/card.php'), true),
    'card.php no longer calls the non-existent mam_session_get()');
// The card page must still define what bootstrap would have given it.
t_ok(in_array('mam_client_ip', $cardFns, true), 'card.php still defines mam_client_ip (bootstrap is not loaded)');
t_ok(in_array('mam_env', $cardFns, true), 'card.php still defines mam_env (bootstrap is not loaded)');
