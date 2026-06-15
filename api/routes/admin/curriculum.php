<?php
/**
 * Admin curriculum authoring + import/export. One file, dispatches by method
 * and ?action. Gated by mw_admin_cap('curriculum') (+ CSRF on POST) in
 * api/index.php.
 *
 *  GET  ?action=catalog                          → programmes/levels/courses (picker)
 *  GET  ?action=outline&course=&level=&program=  → week-by-week outline (plan + authored)
 *  GET  ?action=export&course=&level=&format=    → authored modules (json | csv download)
 *  POST action=save     {id?,course,level?,week,kind,title,content}
 *  POST action=delete   {id}
 *  POST action=scaffold {course,level?,program?}        → create the missing week rows
 *  POST action=import   {course?,level?,format?,modules?[],data?}  → bulk upsert
 *
 * The server stays authoritative: codes are resolved to ids here, kinds are
 * normalised, and the importer upserts by (course, level, week) so a re-import
 * refines a syllabus instead of duplicating it.
 */
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'];

$resolveCourse = function (string $code): ?int {
    $code = trim($code);
    if ($code === '') return null;
    $r = mam_db_row('SELECT id FROM courses WHERE code = ?', [$code]);
    return $r ? (int)$r['id'] : null;
};
$resolveLevel = function ($code): ?int {
    $code = trim((string)($code ?? ''));
    if ($code === '') return null;
    $r = mam_db_row('SELECT id FROM levels WHERE code = ?', [$code]);
    return $r ? (int)$r['id'] : null;
};

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? 'catalog');

    if ($action === 'catalog') {
        mam_ok(mam_lms_admin_catalog());
    }

    if ($action === 'outline') {
        $course = trim((string)($_GET['course'] ?? ''));
        if ($course === '') mam_fail('missing_field', 400, ['field' => 'course']);
        $outline = mam_lms_course_outline(
            $course,
            ($_GET['level'] ?? '')   !== '' ? (string)$_GET['level']   : null,
            ($_GET['program'] ?? '') !== '' ? (string)$_GET['program'] : null
        );
        if (!$outline) mam_fail('course_not_found', 404);
        mam_ok(['outline' => $outline]);
    }

    if ($action === 'export') {
        $course = trim((string)($_GET['course'] ?? ''));
        $cid = $resolveCourse($course);
        if ($cid === null) mam_fail('course_not_found', 404);
        $lid  = $resolveLevel($_GET['level'] ?? null);
        $rows = mam_lms_export_modules($cid, $lid);

        if ((string)($_GET['format'] ?? 'json') === 'csv') {
            $name = preg_replace('/[^a-z0-9]+/i', '-', $course . ($lid !== null ? '-' . (string)($_GET['level'] ?? '') : ''));
            if (!headers_sent()) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="curriculum-' . trim((string)$name, '-') . '.csv"');
            }
            echo mam_lms_modules_to_csv($rows);
            exit;
        }
        mam_ok([
            'course'  => $course,
            'level'   => ($_GET['level'] ?? '') !== '' ? (string)$_GET['level'] : null,
            'count'   => count($rows),
            'modules' => $rows,
        ]);
    }

    mam_fail('unknown_action', 400, ['action' => $action]);
}

/* ─── POST ─── */
$in     = mam_json_input();
$action = (string)($in['action'] ?? $_GET['action'] ?? '');

if ($action === 'save') {
    $cid = $resolveCourse((string)($in['course'] ?? ''));
    if ($cid === null) mam_fail('course_not_found', 404);
    $lid   = $resolveLevel($in['level'] ?? null);
    $week  = (int)($in['week'] ?? 0);
    $title = trim((string)($in['title'] ?? ''));
    if ($week < 1)     mam_fail('bad_week', 400, ['field' => 'week']);
    if ($title === '') mam_fail('empty_title', 400, ['field' => 'title']);
    $kind    = (string)($in['kind'] ?? 'module');
    $content = (isset($in['content']) && trim((string)$in['content']) !== '') ? (string)$in['content'] : null;
    $id      = (isset($in['id']) && (int)$in['id'] > 0) ? (int)$in['id'] : null;
    $savedId = mam_lms_save_module($id, $cid, $lid, $week, $kind, $title, $content);
    mam_ok(['id' => $savedId]);
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if ($id < 1) mam_fail('missing_field', 400, ['field' => 'id']);
    mam_lms_delete_module($id);
    mam_ok(['id' => $id, 'deleted' => true]);
}

if ($action === 'scaffold') {
    $cid = $resolveCourse((string)($in['course'] ?? ''));
    if ($cid === null) mam_fail('course_not_found', 404);
    $lid = $resolveLevel($in['level'] ?? null);
    $pid = null;
    if (!empty($in['program'])) {
        $p = mam_db_row('SELECT id FROM programs WHERE code = ?', [(string)$in['program']]);
        if ($p) $pid = (int)$p['id'];
    }
    mam_ok(['created' => mam_lms_scaffold_modules($cid, $lid, $pid)]);
}

if ($action === 'import') {
    $defaultCid = $resolveCourse((string)($in['course'] ?? ''));
    $defaultLid = $resolveLevel($in['level'] ?? null);

    // Rows can arrive pre-parsed (modules[]) or as raw text (data) — JSON or CSV.
    $rows = [];
    if (isset($in['modules']) && is_array($in['modules'])) {
        $rows = $in['modules'];
    } elseif (isset($in['data']) && is_string($in['data']) && trim($in['data']) !== '') {
        $data = trim($in['data']);
        $rows = mam_lms_rows_from_text($data, (string)($in['format'] ?? ''));
        // A wrapped {course,level,modules} export carries its own scope — honour
        // it only when the request didn't already pick a course/level.
        if (($defaultCid === null || $defaultLid === null) && str_starts_with($data, '{')) {
            $w = json_decode($data, true);
            if (is_array($w)) {
                if ($defaultCid === null && isset($w['course'])) $defaultCid = $resolveCourse((string)$w['course']);
                if ($defaultLid === null && isset($w['level']))  $defaultLid = $resolveLevel($w['level']);
            }
        }
    }

    if (!$rows) {
        mam_fail('no_rows', 400, ['message' => 'No curriculum rows found. Provide a JSON modules array or CSV text with week/title columns.']);
    }
    mam_ok(mam_lms_import_modules($defaultCid, $defaultLid, $rows));
}

mam_fail('unknown_action', 400, ['action' => $action]);
