<?php
/**
 * Admin curriculum authoring + import/export + enterprise management (v41).
 * Gated by mw_admin_cap('curriculum') (+ CSRF on POST) in api/index.php.
 *
 * GET actions:
 *   catalog                          → programmes/levels/courses (picker)
 *   outline  &course=&level=&prog=   → week-by-week outline (plan + authored)
 *   export   &course=&level=&format= → authored modules (json | csv download)
 *   programs                         → all programmes with linked courses + module counts
 *   program_curriculum &program=     → full programme curriculum (all courses + outlines)
 *
 * POST actions (module authoring):
 *   save     {id?,course,level?,week,kind,title,content}
 *   delete   {id}
 *   scaffold {course,level?,program?}
 *   import   {course?,level?,format?,modules?[],data?}
 * POST actions (enterprise management — v41):
 *   course_create   {code,name,kind?,description?,sort_order?}
 *   course_update   {course_id,name?,kind?,description?,sort_order?,active?}
 *   course_toggle   {course_id}          → flip active flag
 *   program_create  {code,name,weeks,revision_week?,exam_week?,requires_core?,certification?,sort_order?}
 *   program_update  {program_id, …}
 *   link_course     {course_id,program_id,level_code?,sort_order?}
 *   unlink_course   {course_id,program_id}
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

    if ($action === 'programs') {
        mam_ok(['programs' => mam_lms_all_programs_with_courses()]);
    }

    if ($action === 'program_curriculum') {
        $code = trim((string)($_GET['program'] ?? ''));
        if ($code === '') mam_fail('missing_field', 400, ['field' => 'program']);
        $result = mam_lms_full_program_curriculum($code);
        if (!$result) mam_fail('program_not_found', 404);
        mam_ok($result);
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

    // Rows can arrive pre-parsed (modules[]), as raw text (data), or be fetched
    // from a remote data-source URL (a published CSV/JSON — e.g. a Google Sheet).
    $rows = [];
    if (isset($in['url']) && is_string($in['url']) && trim($in['url']) !== '') {
        $fetched = mam_lms_fetch_url(trim($in['url']));
        if ($fetched === null) mam_fail('fetch_failed', 400, ['message' => 'Could not fetch that data source (must be a public http(s) CSV/JSON URL).']);
        $in['data'] = $fetched; // fall through to the text parser below
    }
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

/* ── Enterprise management (v41) ── */

if ($action === 'course_create') {
    $code = trim((string)($in['code'] ?? ''));
    $name = trim((string)($in['name'] ?? ''));
    if ($code === '') mam_fail('missing_field', 400, ['field' => 'code']);
    if ($name === '') mam_fail('missing_field', 400, ['field' => 'name']);
    try {
        $id = mam_lms_create_course($code, $name, (string)($in['kind'] ?? 'elective'), $in['description'] ?? null, (int)($in['sort_order'] ?? 99));
        mam_ok(['id' => $id]);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'duplicate_code') mam_fail('duplicate_code', 409, ['code' => $code]);
        throw $e;
    }
}

if ($action === 'course_update') {
    $cid = (int)($in['course_id'] ?? 0);
    if ($cid < 1) mam_fail('missing_field', 400, ['field' => 'course_id']);
    if (!mam_db_row('SELECT id FROM courses WHERE id = ?', [$cid])) mam_fail('course_not_found', 404);
    mam_lms_update_course($cid, $in);
    mam_ok(['id' => $cid, 'updated' => true]);
}

if ($action === 'course_toggle') {
    $cid = (int)($in['course_id'] ?? 0);
    if ($cid < 1) mam_fail('missing_field', 400, ['field' => 'course_id']);
    $row = mam_db_row('SELECT id, active FROM courses WHERE id = ?', [$cid]);
    if (!$row) mam_fail('course_not_found', 404);
    $active = (int)$row['active'] ? 0 : 1;
    mam_lms_update_course($cid, ['active' => $active]);
    mam_ok(['id' => $cid, 'active' => $active]);
}

if ($action === 'program_create') {
    $code = trim((string)($in['code'] ?? ''));
    $name = trim((string)($in['name'] ?? ''));
    if ($code === '') mam_fail('missing_field', 400, ['field' => 'code']);
    if ($name === '') mam_fail('missing_field', 400, ['field' => 'name']);
    try {
        $id = mam_lms_create_program(
            $code, $name, (int)($in['weeks'] ?? 12),
            isset($in['revision_week']) && (int)$in['revision_week'] > 0 ? (int)$in['revision_week'] : null,
            isset($in['exam_week'])     && (int)$in['exam_week']     > 0 ? (int)$in['exam_week']     : null,
            !empty($in['requires_core']),
            (string)($in['certification'] ?? 'completion'),
            (int)($in['sort_order'] ?? 99)
        );
        mam_ok(['id' => $id]);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'duplicate_code') mam_fail('duplicate_code', 409, ['code' => $code]);
        throw $e;
    }
}

if ($action === 'program_update') {
    $pid = (int)($in['program_id'] ?? 0);
    if ($pid < 1) mam_fail('missing_field', 400, ['field' => 'program_id']);
    if (!mam_db_row('SELECT id FROM programs WHERE id = ?', [$pid])) mam_fail('program_not_found', 404);
    mam_lms_update_program($pid, $in);
    mam_ok(['id' => $pid, 'updated' => true]);
}

if ($action === 'link_course') {
    $cid = (int)($in['course_id'] ?? 0);
    $pid = (int)($in['program_id'] ?? 0);
    if ($cid < 1) mam_fail('missing_field', 400, ['field' => 'course_id']);
    if ($pid < 1) mam_fail('missing_field', 400, ['field' => 'program_id']);
    try {
        mam_lms_link_course_to_program($cid, $pid, $in['level_code'] ?? null, (int)($in['sort_order'] ?? 99));
        mam_ok(['linked' => true, 'course_id' => $cid, 'program_id' => $pid]);
    } catch (InvalidArgumentException $e) {
        mam_fail($e->getMessage(), 404);
    }
}

if ($action === 'unlink_course') {
    $cid = (int)($in['course_id'] ?? 0);
    $pid = (int)($in['program_id'] ?? 0);
    if ($cid < 1) mam_fail('missing_field', 400, ['field' => 'course_id']);
    if ($pid < 1) mam_fail('missing_field', 400, ['field' => 'program_id']);
    mam_lms_unlink_course_from_program($cid, $pid);
    mam_ok(['unlinked' => true]);
}

mam_fail('unknown_action', 400, ['action' => $action]);
