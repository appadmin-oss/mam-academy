<?php
/**
 * Structured logger — appends JSON lines to api/logs/app-YYYY-MM-DD.log.
 *
 * A new file per day. They are NOT kept forever: `mam_dp_prune_app_logs()`
 * in dataprotection.php sweeps them on the same schedule as the database
 * tables, because a line here carries an IP address and a user agent and is
 * therefore personal data like any other (see the retention note there).
 */
declare(strict_types=1);

function mam_log(string $level, string $event, array $meta = []): void {
    $line = json_encode([
        'ts' => date('c'),
        'level' => $level,
        'event' => $event,
        'meta' => $meta,
        /* ⚠️ The REAL caller, via mam_client_ip() — never REMOTE_ADDR.
           MEASURED: behind Cloudflare, REMOTE_ADDR is the edge, so with
           CF-Connecting-IP 102.89.44.17 arriving at edge 172.68.10.5 this
           field recorded 172.68.10.5. Every line logged the CDN, which made
           the one field you reach for during an incident useless — and put
           it in direct disagreement with `audit_log`, written from the same
           request by mam_audit() below, which had always used the resolver.
           function_exists guards the gotcha-#4 case: card.php and join.php
           load this file WITHOUT bootstrap and declare their own copy, so a
           future root page that forgets must degrade, not fatal. */
        'ip' => function_exists('mam_client_ip') ? mam_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 240),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) return;
    $file = MAM_LOGS . '/app-' . date('Y-m-d') . '.log';
    if (!is_dir(MAM_LOGS)) @mkdir(MAM_LOGS, 0750, true);
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

function mam_audit(string $action, string $entity, ?int $entityId = null, array $meta = []): void {
    try {
        $db = mam_db();
        $stmt = $db->prepare('INSERT INTO audit_log (actor, action, entity, entity_id, meta, ip) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $_SERVER['PHP_AUTH_USER'] ?? 'public',
            $action, $entity, $entityId,
            json_encode($meta, JSON_UNESCAPED_SLASHES),
            mam_client_ip(),
        ]);
    } catch (Throwable $e) {
        mam_log('warn', 'audit_failed', ['err' => $e->getMessage(), 'action' => $action]);
    }
}
