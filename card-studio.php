<?php
/**
 * ID Card Studio — batch proof, print and export.
 * Route: /card-studio  (clean URL via .htaccess; admin only)
 *
 * Renders the REAL card face (partials/id-card-face.php + assets/css/id-card.css)
 * for every selected holder, so what an admin proofs here is byte-for-byte what
 * the student receives at /card/{serial} and in their card email.
 *
 * Export happens entirely client-side (assets/js/card-export.js): no photograph,
 * QR payload or finished sheet is ever uploaded anywhere.
 *
 * Unlike card.php / join.php this page loads bootstrap.php rather than
 * hand-rolling its lib requires — see CLAUDE.md gotcha #4. Declaring no top-level
 * functions here means it cannot hit the "Cannot redeclare" fatal those two did.
 */
declare(strict_types=1);

require_once __DIR__ . '/api/lib/bootstrap.php';
require_once __DIR__ . '/partials/id-card-face.php';

// bootstrap.php serves the JSON API, so it sets Content-Type: application/json
// unconditionally. Without this the browser shows the page as source text. Same
// two lines /admin/index.php uses for the same reason.
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');

// bootstrap's shutdown handler appends a {"error":"no_response"} JSON envelope to
// anything that finishes without calling mam_ok()/mam_fail() — it assumes every
// request is an API route. An HTML page must claim the response itself or that
// JSON lands in the middle of the markup. /admin/index.php does the same.
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);

/* ── Auth: a logged-in admin, or HTTP Basic as a fallback (mirrors /admin/). ── */
$adminUser = mam_current_user();
if (!($adminUser && mam_user_has_role($adminUser, 'admin'))) {
    if (function_exists('mam_require_admin')) {
        mam_require_admin();
    } else {
        header('Location: /portal/login.html?next=' . urlencode('/card-studio'));
        exit;
    }
}

/* ── Filters ─────────────────────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$status  = (string)($_GET['status'] ?? 'active');
$roleF   = (string)($_GET['role'] ?? '');
$limit   = max(1, min(300, (int)($_GET['limit'] ?? 60)));
if (!in_array($status, ['active', 'revoked', 'all'], true)) $status = 'active';

$where = [];
$args  = [];
if ($status !== 'all') { $where[] = 'ic.status = ?'; $args[] = $status; }
if ($roleF !== '' && in_array($roleF, ['student', 'instructor', 'admin'], true)) {
    $where[] = 'ic.role = ?'; $args[] = $roleF;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(ic.serial LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR sa.first_name LIKE ? OR sa.last_name LIKE ?)';
    array_push($args, $like, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = mam_db_all(
    "SELECT ic.*, u.name AS user_name, u.email AS user_email, u.avatar_url,
            sa.first_name AS sa_first, sa.last_name AS sa_last, sa.headshot_url
       FROM id_cards ic
       LEFT JOIN users u ON u.id = ic.user_id
       LEFT JOIN summer_applications sa ON sa.id = ic.summer_app_id
       $whereSql
       ORDER BY ic.id DESC
       LIMIT $limit",
    $args
);

/* Branding logo, same source and same https-only guard as the card email. */
$logoUrl = '';
$rawLogo = (string)mam_setting('email_logo_url', '');
if (str_starts_with($rawLogo, 'https://') || str_starts_with($rawLogo, '/')) $logoUrl = $rawLogo;
// Back-face contact block (admin-editable — settings group "ID Cards").
$backOpts = [
    'tel'   => (string)mam_setting_or_default('card_found_tel'),
    'email' => (string)mam_setting_or_default('card_found_email'),
    'url'   => (string)mam_setting_or_default('card_found_url'),
];

/* Normalise each row into what the face partial wants. The photo falls back to
   the holder's portal avatar / summer headshot when the card itself has none, so
   the studio proofs the best available portrait rather than an initials disc. */
$cards = [];
foreach ($rows as $r) {
    $name  = mam_card_holder_name($r);
    $photo = (string)($r['photo_url'] ?? '');
    if ($photo === '') $photo = (string)($r['avatar_url'] ?? '');
    if ($photo === '') $photo = (string)($r['headshot_url'] ?? '');
    $cards[] = [
        'id'           => (int)$r['id'],
        'holder_name'  => $name,
        'role'         => (string)$r['role'],
        'display_id'   => mam_card_display_id($r),
        'qr_payload'   => mam_card_qr_payload($r),
        'photo_url'    => $photo,
        'status'       => (string)$r['status'],
        'serial'       => (string)$r['serial'],
        'session_code' => (string)($r['session_code'] ?? ''),
        'issued_at'    => (string)($r['issued_at'] ?? ''),
        'email'        => (string)($r['user_email'] ?? ''),
        'has_photo'    => $photo !== '',
    ];
}

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);
$noPhoto = 0;
foreach ($cards as $c) { if (!$c['has_photo']) $noPhoto++; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ID Card Studio — MAM Academy</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@1,700;1,800;1,900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/id-card.css">
<style>
  *,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #1B140D; --panel: #241A11; --line: #3A2A1B;
    --ink: #F5EFE6; --muted: #A9967C; --gold: #E8961F; --ok: #34D399; --bad: #F87171;
  }
  body { background: var(--bg); color: var(--ink); font-family: 'Inter',system-ui,sans-serif; font-size: 14px; }

  header.bar {
    position: sticky; top: 0; z-index: 50;
    background: rgba(27,20,13,.94); backdrop-filter: blur(8px);
    border-bottom: 1px solid var(--line);
    padding: 14px 22px; display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
  }
  .brand { font-family: 'Barlow Condensed',sans-serif; font-weight: 800; font-size: 22px; letter-spacing: .12em; text-transform: uppercase; }
  .brand small { display: block; font-family: 'Inter',sans-serif; font-weight: 500; font-size: 10px; letter-spacing: .16em; color: var(--muted); }
  .spacer { flex: 1 }

  .field { display: flex; flex-direction: column; gap: 4px; }
  .field label { font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
  input[type=search], input[type=text], select {
    background: #150F0A; color: var(--ink); border: 1px solid var(--line);
    border-radius: 8px; padding: 7px 10px; font: inherit; font-size: 13px; min-width: 90px;
  }
  select { cursor: pointer }
  .btn {
    display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
    border: 1px solid var(--line); background: #2C2015; color: var(--ink);
    border-radius: 9px; padding: 9px 15px; font: inherit; font-size: 13px; font-weight: 600;
  }
  .btn:hover:not(:disabled) { border-color: var(--gold) }
  .btn:disabled { opacity: .45; cursor: not-allowed }
  .btn-primary { background: var(--gold); color: #241A11; border-color: var(--gold) }
  .btn-ghost { background: transparent }

  .toolbar {
    padding: 14px 22px; display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;
    border-bottom: 1px solid var(--line); background: var(--panel);
  }
  .hint { color: var(--muted); font-size: 12px; line-height: 1.6; padding: 10px 22px; max-width: 1100px; }
  /* Live imposition readout — the operator sees the sheet plan BEFORE spending
     minutes rasterising, including when the trim simply will not fit. */
  .layout {
    margin: 14px 22px 0; padding: 10px 14px; border-radius: 10px;
    border: 1px solid var(--line); background: #150F0A;
    font-size: 12.5px; line-height: 1.7; color: var(--ink); max-width: 1100px;
  }
  .layout b { color: var(--gold) }
  .layout.bad { border-color: var(--bad); color: #FFD9D9 }
  .layout .mono { font-family: ui-monospace,Menlo,Consolas,monospace }
  .hint b { color: var(--ink) }
  .warn { color: #F0C36B }

  /* ── Card grid ─────────────────────────────────────────────────────────── */
  .grid { display: flex; flex-wrap: wrap; gap: 18px; padding: 20px 22px 60px; align-items: flex-start; }
  .cell {
    position: relative; border-radius: 14px; padding: 10px;
    border: 1px solid var(--line); background: var(--panel);
    display: flex; flex-direction: column; gap: 8px; align-items: center;
  }
  .cell.sel { border-color: var(--gold); box-shadow: 0 0 0 1px var(--gold) inset }
  /* Reserve the card's footprint so pulling it out of flow to rasterise doesn't
     collapse the cell and make the whole grid jump mid-export. */
  .cell .slot { width: var(--cardw, 210px); aspect-ratio: 54/86; display: flex; }
  .cell .meta { font-size: 11px; color: var(--muted); text-align: center; line-height: 1.45; width: var(--cardw,210px) }
  .cell .meta b { color: var(--ink); display: block; font-size: 12px }
  .cell .pick { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--muted); cursor: pointer }
  .flag { font-size: 10px; padding: 1px 6px; border-radius: 5px; background: #3A2A1B; color: #F0C36B }
  .flag.bad { background: #4A1F1F; color: var(--bad) }
  .flag.ok  { background: #17372B; color: var(--ok) }

  .mam-card { --mam-card-w: var(--cardw, 210px); }
  /* Back faces are parked OFF-CANVAS rather than `hidden`: display:none has no
     layout box, so html2canvas would rasterise nothing and every duplex sheet
     would come out blank. Toggling `.show-backs` brings them into flow. */
  .grid:not(.show-backs) .slot[data-face="back"] {
    position: fixed; left: -12000px; top: 0; width: var(--cardw, 210px);
  }
  /* Rasterising happens at a larger CSS width so html2canvas upscales less (a
     small node scaled 4× renders soft text). Moved off-canvas, not hidden:
     display:none has no layout and html2canvas would capture nothing. */
  .mam-card.is-exporting {
    position: fixed !important; left: -12000px !important; top: 0 !important;
    --mam-card-w: 560px !important; box-shadow: none !important;
  }

  /* ── Progress overlay ──────────────────────────────────────────────────── */
  .veil { position: fixed; inset: 0; z-index: 100; background: rgba(15,10,6,.82); display: none; align-items: center; justify-content: center }
  .veil.on { display: flex }
  .veil .box { background: var(--panel); border: 1px solid var(--line); border-radius: 16px; padding: 26px 30px; width: min(460px,92vw); text-align: center }
  .veil h3 { font-family: 'Barlow Condensed',sans-serif; font-size: 22px; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 6px }
  .veil p { color: var(--muted); font-size: 12px; margin-bottom: 14px }
  .track { height: 8px; border-radius: 99px; background: #150F0A; overflow: hidden; margin-bottom: 14px }
  .track i { display: block; height: 100%; width: 0; background: var(--gold); transition: width .2s }
  #vlog { max-height: 130px; overflow: auto; text-align: left; font-size: 11px; color: var(--muted); line-height: 1.6 }

  /* ── Browser print: a real sheet of cards, nothing else ────────────────── */
  @media print {
    @page { size: A4 portrait; margin: 10mm; }
    header.bar, .toolbar, .hint, .veil, .cell .meta, .cell .pick { display: none !important }
    body { background: #fff }
    .grid { display: block; padding: 0; column-count: 2; column-gap: 4mm }
    .cell { border: none; background: none; padding: 0; margin: 0 0 4mm; break-inside: avoid; }
    .cell.unsel { display: none }
    .cell .slot { width: 74mm }
    .mam-card { --mam-card-w: 74mm !important; box-shadow: none !important; border-radius: 2mm }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact }
  }
</style>
</head>
<body>

<header class="bar">
  <div class="brand">ID Card Studio<small>MAM Academy · <?= count($cards) ?> card<?= count($cards) === 1 ? '' : 's' ?> loaded</small></div>
  <div class="spacer"></div>
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div class="field"><label for="q">Search</label>
      <input id="q" type="search" name="q" value="<?= $h($q) ?>" placeholder="name, email or serial"></div>
    <div class="field"><label for="status">Status</label>
      <select id="status" name="status">
        <?php foreach (['active' => 'Active', 'revoked' => 'Revoked', 'all' => 'All'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label for="role">Role</label>
      <select id="role" name="role">
        <option value="">Any</option>
        <?php foreach (['student' => 'Student', 'instructor' => 'Instructor', 'admin' => 'Admin'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $roleF === $k ? ' selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label for="limit">Max</label>
      <select id="limit" name="limit">
        <?php foreach ([30, 60, 120, 300] as $n): ?>
        <option value="<?= $n ?>"<?= $limit === $n ? ' selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select></div>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="/admin/?v=id-cards">Admin ↗</a>
  </form>
</header>

<div class="toolbar">
  <button class="btn btn-ghost" id="selAll">Select all</button>
  <button class="btn btn-ghost" id="selNone">Select none</button>
  <button class="btn btn-ghost" id="selNoPhoto">Only missing photos</button>

  <div class="field"><label for="zoom">Preview size</label>
    <select id="zoom">
      <option value="150">Small</option>
      <option value="210" selected>Medium</option>
      <option value="300">Large</option>
    </select></div>

  <div class="field"><label for="dpi">Raster DPI</label>
    <select id="dpi" title="Resolution of the card artwork. 350 is the offset standard; 300 the digital-press floor; 600 is slow and large.">
      <option value="300">300 — digital press</option>
      <option value="350" selected>350 — offset standard</option>
      <option value="600">600 — line art (slow)</option>
    </select></div>

  <div class="field"><label for="sheet">Sheet</label>
    <select id="sheet">
      <option value="A4" selected>A4</option>
      <option value="A3">A3</option>
      <option value="SRA3">SRA3</option>
      <option value="LETTER">US Letter</option>
    </select></div>

  <div class="field"><label for="intent">Output intent</label>
    <select id="intent">
      <option value="FOGRA39" selected>FOGRA39</option>
      <option value="FOGRA51">FOGRA51 / PSO v3</option>
      <option value="GRACOL2013">GRACoL 2013</option>
      <option value="SWOP2013">SWOP 2013</option>
    </select></div>

  <div class="field"><label for="orient">Sheet orientation</label>
    <select id="orient">
      <option value="portrait" selected>Portrait</option>
      <option value="landscape">Landscape</option>
    </select></div>

  <div class="field"><label for="trimW">Trim width (mm)</label>
    <input id="trimW" type="number" min="40" max="150" step="0.5" value="74"
      title="Card width on paper. The height follows the artwork's proportion — the background plate is traced art, so forcing a different proportion would stretch it."></div>

  <div class="field"><label for="trimH">Trim height</label>
    <input id="trimH" type="number" value="105" readonly
      title="Derived from the width to keep the artwork's proportion. Read-only on purpose."
      style="opacity:.65;cursor:not-allowed"></div>

  <div class="field"><label for="marginMm">Margin (mm)</label>
    <input id="marginMm" type="number" min="0" max="40" step="1" value="10"
      title="Unprinted border. Most office printers cannot print closer than about 5mm to the paper edge."></div>

  <div class="field"><label for="gutterMm">Gutter (mm)</label>
    <input id="gutterMm" type="number" min="0" max="20" step="0.5" value="6"
      title="Gap between neighbouring cards. Should be at least twice the bleed (6mm) or one card's bleed prints over the next card's face."></div>

  <div class="field"><label for="flip">Duplex flip</label>
    <select id="flip" title="How the press turns the sheet between sides. Choosing wrong puts every back on the wrong card.">
      <option value="long" selected>Long edge — work-and-turn</option>
      <option value="short">Short edge</option>
      <option value="none">None (single-sided)</option>
    </select></div>

  <label class="pick" style="align-self:center"><input type="checkbox" id="withBacks" checked> Include backs (duplex)</label>
  <label class="pick" style="align-self:center"><input type="checkbox" id="verifyOnExport" checked> Verify QR during export</label>
  <label class="pick" style="align-self:center"><input type="checkbox" id="withMarks" checked> Crop marks</label>
  <label class="pick" style="align-self:center"><input type="checkbox" id="showBacks"> Proof backs on screen</label>

  <div class="spacer"></div>
  <button class="btn" id="btnVerify">Verify QR codes</button>
  <button class="btn" id="btnPng">↓ PNG (.zip)</button>
  <button class="btn" id="btnPrint">Print sheet</button>
  <button class="btn btn-primary" id="btnPdf">↓ Print-ready PDF</button>
</div>

<div class="layout" id="layout">Working out the sheet layout…</div>

<p class="hint">
  The PDF imposes the selected cards N-up on the chosen sheet at an exact
  <b>74×105&nbsp;mm</b> trim with
  <b>3&nbsp;mm bleed</b>, crop marks and a named CMYK output intent; fronts and backs
  interleave (backs mirrored) so a long-edge duplex run registers. Artwork embeds at
  the chosen DPI and the press RIP separates it against the intent.
  <b>Print sheet</b> uses your browser's own dialog — handy for a quick proof on office
  card stock, and its "Save as PDF" needs no print shop.
  Everything renders in this browser; nothing is uploaded.
  <?php if ($noPhoto): ?>
  <br><span class="warn">⚠ <?= $noPhoto ?> of these <?= count($cards) ?> cards have no photograph and will print with initials —
  use “Only missing photos” to review them before committing to a print run.</span>
  <?php endif; ?>
</p>

<div class="grid" id="grid">
<?php foreach ($cards as $i => $c): ?>
  <div class="cell sel" data-i="<?= $i ?>" data-nophoto="<?= $c['has_photo'] ? '0' : '1' ?>">
    <label class="pick"><input type="checkbox" class="tick" checked> #<?= $h($c['display_id']) ?></label>
    <div class="slot" data-face="front">
      <?= mam_card_face_html($c, ['qr_id' => 'qr-' . $i, 'logo' => $logoUrl]) ?>
    </div>
    <div class="slot" data-face="back">
      <?= mam_card_back_html($c, $backOpts) ?>
    </div>
    <div class="meta">
      <b><?= $h($c['holder_name'] ?: '—') ?></b>
      <?= $h($c['serial']) ?><br>
      <?php if (!$c['has_photo']): ?><span class="flag bad">no photo</span><?php endif; ?>
      <?php if ($c['status'] === 'revoked'): ?><span class="flag bad">revoked</span><?php endif; ?>
      <span class="flag qrflag" hidden></span>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$cards): ?>
  <p class="hint">No cards match those filters. Cards are minted when a student is enrolled — see <a href="/admin/?v=id-cards" style="color:var(--gold)">Admin → ID Cards</a>.</p>
<?php endif; ?>
</div>

<div class="veil" id="veil">
  <div class="box">
    <h3 id="vtitle">Working…</h3>
    <p id="vsub">Rendering card artwork in this browser.</p>
    <div class="track"><i id="vbar"></i></div>
    <div id="vlog"></div>
    <button class="btn btn-ghost" id="vcancel" style="margin-top:14px">Cancel</button>
  </div>
</div>

<script>window.MAM_CARDS = <?= json_encode(array_map(static fn(array $c): array => [
    'display_id' => $c['display_id'],
    'serial'     => $c['serial'],
    'name'       => $c['holder_name'],
    'qr'         => $c['qr_payload'],
], $cards), JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/assets/js/vendor/qrcode.min.js"></script>
<script src="/assets/js/id-card-qr.js"></script>
<!-- The toolkit is loaded eagerly (it is small) so the live sheet-plan readout can
     compute an imposition before any export runs. The heavy part — html2canvas,
     jsPDF, jsQR, ~700KB — stays behind ensureDeps() and only loads when an admin
     actually exports. -->
<script src="/assets/js/card-export.js"></script>
<script src="/assets/js/card-studio.js"></script>
</body>
</html>
