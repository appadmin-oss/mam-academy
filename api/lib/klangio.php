<?php
/**
 * Klangio audio-to-notation client (server-side proxy).
 * Docs: https://api-docs.klang.io  ·  https://klang.io/api
 *
 * Klangio turns an audio recording into MIDI / MusicXML / note events. We proxy
 * it server-side so the API key never reaches the browser, uploads are size- and
 * type-checked, and the paid endpoint sits behind our auth + rate limiting.
 *
 * Everything that could plausibly drift in Klangio's API — base URL, the key
 * header name, the submit path, the default model — is read from env, so the
 * integration can follow their API without a code change. The feature is OFF
 * until KLANGIO_API_KEY is set; mam_klangio_configured() lets routes degrade
 * gracefully instead of erroring.
 */
declare(strict_types=1);

function mam_klangio_configured(): bool {
    // Needs both a key and an HTTP client — a host without cURL can't reach the
    // API, so report unavailable up front rather than failing at submit time.
    return function_exists('curl_init') && trim((string)mam_env('KLANGIO_API_KEY', '')) !== '';
}

function mam_klangio_key(): string {
    $k = trim((string)mam_env('KLANGIO_API_KEY', ''));
    if ($k === '') throw new RuntimeException('Klangio not configured');
    return $k;
}

function mam_klangio_base(): string {
    return rtrim((string)mam_env('KLANGIO_BASE_URL', 'https://api.klang.io'), '/');
}

/**
 * Low-level request. Returns ['code'=>int, 'body'=>string, 'content_type'=>string].
 * Never throws on a non-2xx HTTP status (the caller decides) — only on a
 * transport-level failure.
 *
 * @param array $opts  method, headers[], multipart[] (CURLFile-bearing), timeout
 */
function mam_klangio_request(string $method, string $path, array $opts = []): array {
    if (!function_exists('curl_init')) throw new RuntimeException('curl unavailable');
    $url = (stripos($path, 'http') === 0) ? $path : (mam_klangio_base() . $path);
    $keyHeader = (string)mam_env('KLANGIO_KEY_HEADER', 'kl-api-key');
    $headers = [$keyHeader . ': ' . mam_klangio_key(), 'Accept: */*'];
    foreach ($opts['headers'] ?? [] as $h) $headers[] = $h;

    $ch = curl_init();
    $c = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => (int)($opts['timeout'] ?? 30),
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (isset($opts['multipart'])) {
        $c[CURLOPT_POST] = true;
        $c[CURLOPT_POSTFIELDS] = $opts['multipart']; // array; CURLFile sets multipart automatically
    } elseif (isset($opts['body'])) {
        $c[CURLOPT_POSTFIELDS] = $opts['body'];
    }
    curl_setopt_array($ch, $c);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('Klangio network error: ' . $err);
    return ['code' => $code, 'body' => (string)$resp, 'content_type' => $ctype];
}

/**
 * Submit an audio file for transcription. Returns the decoded JSON (expected to
 * carry a job id under one of job_id / id / jobId). Throws on failure.
 */
function mam_klangio_submit(string $filePath, string $fileName, string $mime, ?string $model = null): array {
    $model = $model ?: (string)mam_env('KLANGIO_MODEL', 'piano');
    $path = (string)mam_env('KLANGIO_SUBMIT_PATH', '/transcription');
    $multipart = [
        'model' => $model,
        'outputs' => 'midi,musicxml,csv',
        'file' => new CURLFile($filePath, $mime ?: 'application/octet-stream', $fileName ?: 'audio'),
    ];
    $r = mam_klangio_request('POST', $path, ['multipart' => $multipart, 'timeout' => 90]);
    $data = json_decode($r['body'], true);
    if ($r['code'] >= 300 || !is_array($data)) {
        $detail = is_array($data) ? ($data['message'] ?? $data['detail'] ?? '') : substr($r['body'], 0, 200);
        throw new RuntimeException('Klangio submit failed (' . $r['code'] . '): ' . $detail);
    }
    return $data;
}

/** Poll a job. Returns decoded JSON (status under 'status'), or a minimal shape. */
function mam_klangio_job(string $jobId): array {
    $r = mam_klangio_request('GET', '/job/' . rawurlencode($jobId), ['timeout' => 20]);
    $data = json_decode($r['body'], true);
    if (!is_array($data)) return ['status' => 'UNKNOWN', 'http' => $r['code']];
    return $data;
}

/** Fetch a result artifact (midi|musicxml|csv|pdf|gp5). Returns the raw request. */
function mam_klangio_result(string $jobId, string $format): array {
    return mam_klangio_request('GET', '/job/' . rawurlencode($jobId) . '/' . rawurlencode($format), ['timeout' => 45]);
}

/* ─── Helpers ─── */

/** Normalise Klangio's many "done"/"failed" spellings into done|failed|working. */
function mam_klangio_phase(string $status): string {
    $s = strtoupper(trim($status));
    if (in_array($s, ['SUCCESS', 'SUCCEEDED', 'SUCCEED', 'DONE', 'COMPLETED', 'COMPLETE', 'FINISHED'], true)) return 'done';
    if (in_array($s, ['FAILED', 'FAILURE', 'ERROR', 'CANCELLED', 'CANCELED'], true)) return 'failed';
    return 'working';
}

/**
 * Best-effort parse of a Klangio note-event CSV into a compact list the UI can
 * render. Tolerant of column order/naming: finds onset, duration/offset, and a
 * pitch column (MIDI number or note name). Returns [] if it can't make sense of
 * it — the UI then just offers the file downloads.
 */
function mam_klangio_parse_csv(string $csv, int $limit = 400): array {
    $csv = trim($csv);
    if ($csv === '') return [];
    $lines = preg_split('/\r\n|\r|\n/', $csv);
    if (!$lines) return [];
    $delim = (substr_count($lines[0], ';') > substr_count($lines[0], ',')) ? ';' : ',';
    $head = array_map(fn($h) => strtolower(trim($h)), str_getcsv($lines[0], $delim));
    $looksHeader = !is_numeric(trim($head[0] ?? '0'));
    $idx = function (array $names) use ($head) {
        foreach ($head as $i => $h) foreach ($names as $n) if (strpos($h, $n) !== false) return $i;
        return -1;
    };
    $iOn = $looksHeader ? $idx(['onset', 'start', 'time', 'begin']) : 0;
    $iDur = $looksHeader ? $idx(['duration', 'dur', 'length']) : -1;
    $iOff = $looksHeader ? $idx(['offset', 'end', 'stop']) : -1;
    $iPit = $looksHeader ? $idx(['pitch', 'midi', 'note', 'key']) : 2;

    $names = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
    $out = [];
    for ($r = $looksHeader ? 1 : 0; $r < count($lines); $r++) {
        $line = trim($lines[$r]);
        if ($line === '') continue;
        $cols = str_getcsv($line, $delim);
        if (!$cols) continue;
        $onset = ($iOn >= 0 && isset($cols[$iOn]) && is_numeric($cols[$iOn])) ? (float)$cols[$iOn] : null;
        $dur = null;
        if ($iDur >= 0 && isset($cols[$iDur]) && is_numeric($cols[$iDur])) $dur = (float)$cols[$iDur];
        elseif ($iOff >= 0 && isset($cols[$iOff]) && is_numeric($cols[$iOff]) && $onset !== null) $dur = max(0, (float)$cols[$iOff] - $onset);
        $pitchRaw = $cols[$iPit] ?? ($cols[2] ?? null);
        if ($pitchRaw === null) continue;
        $pitchRaw = trim((string)$pitchRaw);
        $name = '';
        if (is_numeric($pitchRaw)) {
            $midi = (int)round((float)$pitchRaw);
            if ($midi < 12 || $midi > 127) continue;
            $name = $names[$midi % 12] . ((int)floor($midi / 12) - 1);
        } else {
            if (!preg_match('/^[A-Ga-g][#b♯♭]?-?\d?$/', $pitchRaw)) continue;
            $name = strtoupper(substr($pitchRaw, 0, 1)) . substr($pitchRaw, 1);
        }
        $out[] = ['t' => $onset, 'd' => $dur, 'note' => $name];
        if (count($out) >= $limit) break;
    }
    return $out;
}
