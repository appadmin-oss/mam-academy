<?php
/**
 * GET /api/advisor/recommend?dream=…&excites=…&level=…&discipline=…&expression=…&mode=…
 *
 * The AI-driven "Find Your Path" recommendation: the AI both chooses the best
 * starting programme (constrained to the real set) and writes the rationale.
 * Public, rate-limited, non-PII (whitelisted answer tokens only). Returns
 * enabled:false when no AI provider is configured, and recommendation:null when
 * AI is on but unavailable — in both cases the client falls back to its own
 * deterministic preview so the advisor always works. Price and final placement
 * stay server-authoritative (this endpoint touches neither).
 */
declare(strict_types=1);

if (!mam_ai_enabled('advisor')) { mam_ok(['enabled' => false, 'recommendation' => null]); }

$ctx = [
    'dream'      => (string)($_GET['dream'] ?? ''),
    'excites'    => (string)($_GET['excites'] ?? ''),
    'level'      => (string)($_GET['level'] ?? ''),
    'discipline' => (string)($_GET['discipline'] ?? ''),
    'expression' => (string)($_GET['expression'] ?? ''),
    'mode'       => (string)($_GET['mode'] ?? ''),
];

$rec = mam_ai_advisor_recommend($ctx);
mam_ok([
    'enabled'        => true,
    'recommendation' => $rec ? ['pathway' => $rec['pathway'], 'rationale' => $rec['rationale']] : null,
]);
