<?php
/**
 * GET /api/certificate/{reference}
 *
 * Renders a printable, save-as-PDF certificate as a standalone page (same
 * model as the invoice/policy pages). The reference alone identifies it; no
 * extra PII is surfaced. The visual is intentionally self-contained so the
 * design can be restyled in one place.
 */
declare(strict_types=1);

$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($params['ref'] ?? ''));
if ($ref === '') mam_raw_response('<h1>Certificate not found</h1><p>Missing reference.</p>', 400);

$c = mam_lms_certificate($ref);
if (!$c) mam_raw_response('<h1>Certificate not found</h1><p>That reference doesn\'t match any certificate on file.</p>', 404);

$esc      = static fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$name     = $esc($c['student_name'] ?: 'Student');
$title    = $esc($c['title'] ?: mam_lms_cert_kind_title((string)($c['kind'] ?? '')));
$program  = $esc($c['program_name'] ?: 'MAM Academy programme');
$level    = $c['level_name'] ? $esc($c['level_name']) : '';
$grades   = $c['trinity_grades'] ? $esc($c['trinity_grades']) : '';
$diploma  = ($c['kind'] ?? '') === 'internal_diploma';
$issued   = $esc(date('j F Y', strtotime((string)($c['issued_at'] ?? 'now'))));
$year     = date('Y', strtotime((string)($c['issued_at'] ?? 'now')));
$refEsc   = $esc($c['reference']);
$verifyUrl = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/verify?ref=' . rawurlencode($c['reference']);

$line = 'has successfully completed the <strong>' . $program . '</strong>';
if ($level !== '') $line .= ' at the <strong>' . $level . '</strong> level' . ($grades !== '' ? ' <span class="muted">(Trinity-equivalent ' . $grades . ')</span>' : '');
$line .= ' at MAM Academy, a MAM Centre company.';

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
  . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
  . '<meta name="robots" content="noindex,nofollow">'
  . '<title>' . $title . ' — ' . $name . ' · MAM Academy</title>'
  . '<link rel="icon" type="image/svg+xml" href="/mam-academy-logo.svg">'
  . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
  . '<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">'
  . '<style>'
  . ':root{--ink:#2C2218;--gold:#A9791C;--gold-bright:#C9A227;--cream:#FBF6EC;--line:#D8C49A}'
  . '*{box-sizing:border-box;margin:0;padding:0}'
  . 'body{background:#3A2E20;font-family:"Inter",system-ui,sans-serif;color:var(--ink);padding:24px;display:flex;flex-direction:column;align-items:center;gap:18px;min-height:100vh}'
  . '.toolbar{display:flex;gap:10px}'
  . '.btn{font:600 0.9rem Inter,sans-serif;border:0;border-radius:10px;padding:11px 20px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px}'
  . '.btn-print{background:linear-gradient(135deg,#C9A227,#A9791C);color:#1a1206}'
  . '.btn-ghost{background:rgba(255,255,255,0.1);color:#F5EEE3;border:1px solid rgba(255,255,255,0.25)}'
  . '.cert{position:relative;width:100%;max-width:1040px;aspect-ratio:1.414/1;background:'
  .   'radial-gradient(120% 120% at 50% 0%,#FFFDF7,var(--cream));'
  .   'box-shadow:0 30px 80px rgba(0,0,0,0.5);padding:clamp(20px,4vw,46px)}'
  . '.frame{position:absolute;inset:clamp(12px,2.2vw,22px);border:2px solid var(--gold);outline:1px solid var(--line);outline-offset:5px;pointer-events:none}'
  . '.frame::before,.frame::after{content:"";position:absolute;width:34px;height:34px;border:2px solid var(--gold-bright)}'
  . '.frame::before{top:-2px;left:-2px;border-right:0;border-bottom:0}'
  . '.frame::after{bottom:-2px;right:-2px;border-left:0;border-top:0}'
  . '.inner{position:relative;height:100%;display:flex;flex-direction:column;align-items:center;text-align:center;justify-content:space-between;padding:clamp(14px,3vw,30px) clamp(20px,5vw,56px)}'
  . '.head{display:flex;flex-direction:column;align-items:center;gap:6px}'
  . '.seal{width:64px;height:64px;border-radius:50%;display:grid;place-items:center;color:#fff;'
  .   'background:radial-gradient(circle at 35% 30%,var(--gold-bright),var(--gold));box-shadow:0 6px 16px rgba(169,121,28,0.4);font-family:"Cinzel",serif;font-weight:700;font-size:1.2rem;letter-spacing:0.02em}'
  . '.brand{font-family:"Cinzel",serif;font-weight:700;letter-spacing:0.22em;font-size:1.05rem;color:var(--ink);margin-top:8px}'
  . '.tag{font-family:"Cormorant Garamond",serif;font-style:italic;color:#7A6A52;font-size:0.98rem}'
  . '.title{font-family:"Cinzel",serif;font-weight:600;letter-spacing:0.18em;text-transform:uppercase;color:var(--gold);font-size:clamp(1rem,2.4vw,1.5rem);margin-top:4px}'
  . '.rule{width:120px;height:2px;background:linear-gradient(90deg,transparent,var(--gold),transparent);margin:10px auto}'
  . '.present{font-family:"Cormorant Garamond",serif;font-size:1.15rem;color:#5C4D38}'
  . '.name{font-family:"Cinzel",serif;font-weight:700;font-size:clamp(2rem,6vw,3.4rem);color:var(--gold);line-height:1.1;margin:6px 0}'
  . '.name-rule{width:min(70%,520px);height:1px;background:var(--line);margin:0 auto 14px}'
  . '.body{font-family:"Cormorant Garamond",serif;font-size:clamp(1.05rem,2.2vw,1.35rem);line-height:1.6;color:#43372a;max-width:640px}'
  . '.body strong{color:var(--ink);font-weight:600}.muted{color:#8a7a5f}'
  . '.diploma{margin-top:10px;display:inline-block;font:600 0.72rem Inter;letter-spacing:0.14em;text-transform:uppercase;color:var(--gold);border:1px solid var(--line);border-radius:100px;padding:5px 14px}'
  . '.foot{width:100%;display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-top:8px}'
  . '.sig{text-align:center;min-width:200px}.sig .ln{border-top:1.5px solid var(--ink);padding-top:7px;font-family:"Cinzel",serif;font-size:0.78rem;letter-spacing:0.06em}.sig .sub{font-size:0.72rem;color:#7A6A52;margin-top:2px}'
  . '.meta{text-align:right;font-size:0.72rem;color:#7A6A52;line-height:1.5}'
  . '.meta .ref{font-family:monospace;color:var(--gold)}'
  . '@media (max-width:640px){.cert{aspect-ratio:auto}.foot{flex-direction:column;align-items:center;gap:18px}.meta{text-align:center}}'
  . '@media print{body{background:#fff;padding:0}.toolbar{display:none}.cert{box-shadow:none;max-width:none;width:100%;height:100vh;aspect-ratio:auto}@page{size:A4 landscape;margin:0}}'
  . '</style></head><body>'
  . '<div class="toolbar">'
  .   '<button class="btn btn-print" onclick="window.print()">⤓ Print / Save as PDF</button>'
  .   '<a class="btn btn-ghost" href="/">← MAM Academy</a>'
  . '</div>'
  . '<main class="cert"><div class="frame"></div><div class="inner">'
  .   '<div class="head">'
  .     '<div class="seal">M</div>'
  .     '<div class="brand">MAM ACADEMY</div>'
  .     '<div class="tag">A Creative Intelligence &amp; Performance Academy</div>'
  .     '<div class="title">' . $title . '</div><div class="rule"></div>'
  .   '</div>'
  .   '<div>'
  .     '<div class="present">This is proudly presented to</div>'
  .     '<div class="name">' . $name . '</div><div class="name-rule"></div>'
  .     '<p class="body">' . $line . '</p>'
  .     ($diploma ? '<span class="diploma">Diploma Transcript Enclosed</span>' : '')
  .   '</div>'
  .   '<div class="foot">'
  .     '<div class="sig"><div class="ln">Just-Joe Adeola</div><div class="sub">Director, MAM Academy</div></div>'
  .     '<div class="meta">Issued ' . $issued . '<br>Certificate ID <span class="ref">' . $refEsc . '</span><br>Scan the QR or verify at academy.mamcentre.org/verify'
  .       '<div id="cert-qr" style="margin-top:8px;display:inline-block;background:#fff;padding:4px;border-radius:6px" title="Scan to verify this certificate"></div>'
  .     '</div>'
  .   '</div>'
  . '</div></main>'
  . '<script src="/assets/js/vendor/qrcode.min.js"></script>'
  . '<script>try{new QRCode(document.getElementById("cert-qr"),{text:' . json_encode($verifyUrl, JSON_UNESCAPED_SLASHES) . ',width:96,height:96,correctLevel:QRCode.CorrectLevel.M});}catch(e){}</script>'
  . '</body></html>';

mam_raw_response($html);
