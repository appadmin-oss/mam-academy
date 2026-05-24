<?php
/**
 * Structured logger — appends JSON lines to api/logs/app.log.
 * Rotates daily; old logs are kept on disk.
 */
declare(strict_types=1);

function mam_log(string $level, string $event, array $meta = []): void {
    $line = json_encode([
        'ts' => date('c'),
        'level' => $level,
        'event' => $event,
        'meta' => $meta,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
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
