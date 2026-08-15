<?php
/**
 * /recital/{slug} — the public concert programme.
 *
 * Rendered SERVER-SIDE, deliberately. A programme is a document: it must be
 * readable with JavaScript off, print correctly from a phone in a foyer, and
 * carry real Open Graph tags so that when a parent forwards the link it
 * unfurls with the recital's name rather than "MAM Academy". A client-rendered
 * page fails all three.
 *
 * /recital with no slug lists what is coming up and what has passed.
 */
declare(strict_types=1);

require_once __DIR__ . '/api/lib/bootstrap.php';
// bootstrap forces application/json and its shutdown handler appends a JSON
// envelope; both must be undone for an HTML page (CLAUDE.md gotcha #5).
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);

$slug = trim((string) ($_GET['slug'] ?? ''));
$e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$recital = null; $prog = null; $list = []; $viewer = null; $gallery = [];
if ($slug !== '') {
    $row = mam_recital_by_slug($slug);
    // A draft or an open call is not a programme yet — 404 rather than show a
    // running order that has not been decided.
    if ($row && in_array((string) $row['status'], ['programmed', 'completed'], true)) {
        $recital = $row;
        $prog    = mam_recital_programme_full((int) $row['id']);
        // The page is public, but a signed-in member of the academy sees more:
        // the evening itself, if it was recorded and the families consented.
        // Resolving the viewer here — rather than fetching after load — keeps
        // the gallery in the server-rendered document like everything else.
        $viewer  = function_exists('mam_session_user') ? mam_session_user() : null;
        $gallery = $viewer ? mam_recital_gallery((int) $row['id']) : [];
    } else {
        http_response_code(404);
    }
} else {
    $list = mam_recital_public_list(30);
}

$fmtDate = function ($dt, string $f = 'l j F Y') {
    if (!$dt) return null;
    $t = strtotime((string) $dt);
    return $t === false ? null : date($f, $t);
};

$pageTitle = $recital ? ($recital['title'] . ' — MAM Academy') : 'Recitals — MAM Academy';
$ogDesc    = $recital
    ? trim(($fmtDate($recital['starts_at']) ?: 'Date to be confirmed') . ($recital['venue'] ? ' · ' . $recital['venue'] : '')
        . ' · ' . (int) ($prog['performer_count'] ?? 0) . ' performers')
    : 'Concerts and recitals at MAM Academy.';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($pageTitle) ?></title>
<meta name="description" content="<?= $e($ogDesc) ?>">
<meta property="og:type" content="<?= $recital ? 'event' : 'website' ?>">
<meta property="og:title" content="<?= $e($recital ? $recital['title'] : 'Recitals — MAM Academy') ?>">
<meta property="og:description" content="<?= $e($ogDesc) ?>">
<?php if ($recital): ?>
<meta property="og:url" content="/recital/<?= $e($recital['slug']) ?>">
<?php endif; ?>
<link rel="icon" type="image/svg+xml" href="/mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if ($recital && $recital['starts_at']):
  // Real structured data, so the event can be surfaced by a search engine or
  // added to a calendar from the share sheet.
  $ld = [
    '@context' => 'https://schema.org', '@type' => 'MusicEvent',
    'name' => $recital['title'], 'startDate' => date('c', strtotime((string) $recital['starts_at'])),
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'eventStatus' => $recital['status'] === 'completed' ? 'https://schema.org/EventScheduled' : 'https://schema.org/EventScheduled',
    'organizer' => ['@type' => 'Organization', 'name' => 'MAM Academy'],
  ];
  if ($recital['venue']) $ld['location'] = ['@type' => 'Place', 'name' => $recital['venue'], 'address' => $recital['venue']];
?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<style>
  :root{
    --ink:#1B2430; --muted:#69727f; --faint:#959cA7; --rule:#E2E0DA;
    --paper:#FBFAF7; --card:#fff; --gold:#B8892B; --gold-deep:#8C6417;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Inter,system-ui,sans-serif;background:var(--paper);color:var(--ink);line-height:1.6;-webkit-font-smoothing:antialiased}
  .wrap{max-width:760px;margin:0 auto;padding:40px 24px 80px}

  /* The masthead is set like a title page: small caps institution, large
     display title, then the practical facts a person needs to attend. */
  .crest{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:34px}
  .crest img{width:40px;height:40px}
  .crest span{font-size:.7rem;letter-spacing:.24em;text-transform:uppercase;color:var(--muted);font-weight:600}

  .masthead{text-align:center;padding-bottom:26px;border-bottom:2px solid var(--ink);margin-bottom:6px}
  .kicker{font-size:.68rem;letter-spacing:.26em;text-transform:uppercase;color:var(--gold-deep);font-weight:700;margin-bottom:14px}
  h1{font-family:'Cormorant Garamond',Georgia,serif;font-weight:700;font-size:clamp(2.1rem,6vw,3.4rem);line-height:1.08;letter-spacing:-.01em}
  .subtitle{font-family:'Cormorant Garamond',Georgia,serif;font-style:italic;font-size:clamp(1.05rem,2.6vw,1.4rem);color:var(--muted);margin-top:8px}
  .facts{display:flex;flex-wrap:wrap;justify-content:center;gap:6px 22px;margin-top:20px;font-size:.9rem;color:var(--muted)}
  .facts b{color:var(--ink);font-weight:600}
  .rule-thin{border-bottom:1px solid var(--ink);margin-bottom:30px;height:3px}

  .blurb{font-family:'Cormorant Garamond',Georgia,serif;font-size:1.18rem;text-align:center;color:var(--muted);max-width:56ch;margin:0 auto 34px}

  .section-head{font-size:.7rem;letter-spacing:.24em;text-transform:uppercase;color:var(--gold-deep);font-weight:700;text-align:center;margin:38px 0 18px;position:relative}
  .section-head::before,.section-head::after{content:'';position:absolute;top:50%;width:calc(50% - 5.5em);border-top:1px solid var(--rule)}
  .section-head::before{left:0} .section-head::after{right:0}

  /* Each entry is a programme line: number, work + composer, performer, time.
     The dotted leader is what makes it read as a programme rather than a table. */
  .item{display:grid;grid-template-columns:2.2rem 1fr auto;gap:4px 14px;padding:15px 0;border-bottom:1px solid var(--rule);align-items:baseline}
  .item:last-child{border-bottom:none}
  .num{font-family:'Cormorant Garamond',Georgia,serif;font-size:1.3rem;color:var(--gold);text-align:right;font-weight:600}
  .work{font-family:'Cormorant Garamond',Georgia,serif;font-size:1.3rem;font-weight:600;line-height:1.25}
  .movement{display:block;font-style:italic;font-size:.98rem;color:var(--muted);font-weight:400}
  .composer{font-size:.88rem;color:var(--muted);margin-top:2px}
  .performer{grid-column:2;font-size:.94rem;font-weight:600;color:var(--gold-deep);margin-top:5px}
  .performer .inst{font-weight:400;color:var(--muted);font-style:italic}
  .at{font-variant-numeric:tabular-nums;font-size:.84rem;color:var(--faint);white-space:nowrap}
  .dur{display:block;font-size:.72rem;color:var(--faint);text-align:right}

  .interval{text-align:center;padding:22px 0;margin:8px 0;border-top:1px solid var(--rule);border-bottom:1px solid var(--rule)}
  .interval span{font-size:.72rem;letter-spacing:.24em;text-transform:uppercase;color:var(--muted);font-weight:700}
  .interval em{display:block;font-style:normal;font-size:.84rem;color:var(--faint);margin-top:4px;font-variant-numeric:tabular-nums}

  .gallery-note{text-align:center;color:var(--muted);font-size:.88rem;max-width:52ch;margin:0 auto 20px}
  .gallery-note a{color:var(--gold-deep)}
  .rec{display:flex;align-items:center;gap:18px;padding:14px 0;border-bottom:1px solid var(--rule);flex-wrap:wrap}
  .rec:last-of-type{border-bottom:none}
  .rec-meta{flex:1;min-width:200px}
  .rec-meta strong{font-family:'Cormorant Garamond',Georgia,serif;font-size:1.15rem;font-weight:600;display:block}
  .rec-composer{display:block;font-size:.84rem;color:var(--muted)}
  .rec-performer{display:block;font-size:.86rem;color:var(--gold-deep);font-weight:600;margin-top:2px}
  .rec audio{height:36px;max-width:100%}
  /* A recording cannot be printed. Drop the whole gallery from the paper
     programme rather than printing a row of dead player widgets. */
  @media print{.rec,.gallery-note{display:none}}

  .note{margin-top:40px;padding-top:26px;border-top:1px solid var(--rule);font-family:'Cormorant Garamond',Georgia,serif;font-size:1.06rem;color:var(--muted);white-space:pre-wrap}
  .colophon{margin-top:44px;text-align:center;font-size:.8rem;color:var(--faint)}
  .colophon a{color:var(--gold-deep)}

  .actions{display:flex;gap:10px;justify-content:center;margin-top:34px;flex-wrap:wrap}
  .btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:1px solid var(--ink);border-radius:999px;background:transparent;color:var(--ink);font:inherit;font-size:.86rem;font-weight:600;cursor:pointer;text-decoration:none}
  .btn:hover{background:var(--ink);color:#fff}

  /* Listing */
  .card{display:block;background:var(--card);border:1px solid var(--rule);border-radius:14px;padding:22px 24px;margin-bottom:14px;text-decoration:none;color:inherit}
  .card:hover{border-color:var(--gold)}
  .card h2{font-family:'Cormorant Garamond',Georgia,serif;font-size:1.6rem;font-weight:700}
  .card p{color:var(--muted);font-size:.9rem;margin-top:5px}
  .pill{display:inline-block;font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;font-weight:700;padding:3px 10px;border-radius:999px;border:1px solid var(--gold);color:var(--gold-deep);margin-bottom:9px}
  .empty{text-align:center;color:var(--muted);padding:60px 0;font-family:'Cormorant Garamond',Georgia,serif;font-size:1.2rem}

  /* A programme is printed and folded. Give it real paper margins, drop
     anything interactive, and never break an entry across two pages —
     a performer's name orphaned from their piece is the one thing a
     printed programme must not do. */
  @media print{
    @page{margin:18mm 16mm}
    body{background:#fff}
    .wrap{max-width:none;padding:0}
    .actions,.colophon{display:none}
    .item,.interval{break-inside:avoid;page-break-inside:avoid}
    .section-head{break-after:avoid;page-break-after:avoid}
    a{text-decoration:none;color:inherit}
  }
</style>
</head>
<body>
<div class="wrap">

  <div class="crest">
    <img src="/mam-academy-logo.svg" alt="" onerror="this.style.display='none'">
    <span>MAM Academy</span>
  </div>

<?php if ($slug !== '' && !$recital): ?>

  <div class="empty">
    That programme is not available.<br>
    <a href="/recital" style="color:var(--gold-deep);font-size:1rem">See all recitals</a>
  </div>

<?php elseif ($recital): ?>

  <header class="masthead">
    <div class="kicker"><?= $recital['status'] === 'completed' ? 'Performed' : 'Programme' ?></div>
    <h1><?= $e($recital['title']) ?></h1>
    <?php if ($recital['subtitle']): ?><div class="subtitle"><?= $e($recital['subtitle']) ?></div><?php endif; ?>
    <div class="facts">
      <?php if ($d = $fmtDate($recital['starts_at'])): ?><span><b><?= $e($d) ?></b></span><?php endif; ?>
      <?php if ($t = $fmtDate($recital['starts_at'], 'H:i')): ?><span>Begins <b><?= $e($t) ?></b></span><?php endif; ?>
      <?php if ($dr = $fmtDate($recital['doors_open_at'], 'H:i')): ?><span>Doors <b><?= $e($dr) ?></b></span><?php endif; ?>
      <?php if ($recital['venue']): ?><span><b><?= $e($recital['venue']) ?></b></span><?php endif; ?>
    </div>
  </header>
  <div class="rule-thin"></div>

  <?php if ($recital['description']): ?><p class="blurb"><?= $e($recital['description']) ?></p><?php endif; ?>

  <?php
  $items = $prog['items'] ?? [];
  if (!$items):
  ?>
    <div class="empty">The running order for this recital has not been published yet.</div>
  <?php else:
    $labels  = ['opening' => 'Opening', 'main' => 'Programme', 'finale' => 'Finale'];
    $lastSec = null;
    foreach ($items as $it):
      if (!empty($it['interval'])): ?>
        <div class="interval">
          <span>Interval</span>
          <em><?= (int) $it['duration_min'] ?> minutes<?= $it['at'] ? ' · ' . $e($it['at']) : '' ?></em>
        </div>
      <?php
        $lastSec = 'interval';
        continue;
      endif;

      if ($it['section'] !== $lastSec):
        // A single-section programme needs no heading — "Programme" above the
        // only list of pieces is noise on a page that is already a programme.
        $multi = count(array_unique(array_column(array_filter($items, fn($x) => empty($x['interval'])), 'section'))) > 1;
        if ($multi): ?>
          <div class="section-head"><?= $e($labels[$it['section']] ?? $it['section']) ?></div>
        <?php endif;
        $lastSec = $it['section'];
      endif;

      $name = mam_recital_public_name((int) $it['student_id'], (string) $it['student_name']);
    ?>
      <article class="item">
        <div class="num"><?= (int) $it['position'] ?></div>
        <div>
          <div class="work">
            <?= $e($it['title']) ?>
            <?php if ($it['movement']): ?><span class="movement"><?= $e($it['movement']) ?></span><?php endif; ?>
          </div>
          <?php if ($it['composer'] || $it['arranger']): ?>
            <div class="composer">
              <?= $e($it['composer'] ?: '') ?><?php if ($it['arranger']): ?><?= $it['composer'] ? ' · ' : '' ?>arr. <?= $e($it['arranger']) ?><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="at">
          <?= $it['at'] ? $e($it['at']) : '' ?>
          <span class="dur"><?= (int) $it['duration_min'] ?> min</span>
        </div>
        <div class="performer">
          <?= $e($name) ?><?php if ($it['instrument']): ?> <span class="inst">— <?= $e($it['instrument']) ?></span><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>

    <div class="facts" style="margin-top:26px;justify-content:center">
      <span><b><?= (int) $prog['performer_count'] ?></b> performer<?= (int) $prog['performer_count'] === 1 ? '' : 's' ?></span>
      <span>Running time approx. <b><?= (int) $prog['total_min'] ?> min</b></span>
      <?php if ($prog['ends_at']): ?><span>Ends about <b><?= $e($prog['ends_at']) ?></b></span><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($recital['status'] === 'completed'): ?>
    <?php if ($gallery): ?>
      <div class="section-head">Listen back</div>
      <p class="gallery-note">Recordings from the night, shared with the academy. Please keep them within the MAM community.</p>
      <?php foreach ($gallery as $g): ?>
        <div class="rec">
          <div class="rec-meta">
            <strong><?= $e($g['title']) ?></strong>
            <?php if ($g['composer']): ?><span class="rec-composer"><?= $e($g['composer']) ?></span><?php endif; ?>
            <span class="rec-performer"><?= $e($g['performer']) ?><?= $g['instrument'] ? ' — ' . $e($g['instrument']) : '' ?></span>
          </div>
          <audio controls preload="none" src="<?= $e($g['audio_url']) ?>"></audio>
        </div>
      <?php endforeach; ?>
    <?php elseif (!$viewer): ?>
      <div class="section-head">Listen back</div>
      <p class="gallery-note">
        Recordings from this recital are shared with the MAM community.
        <a href="/portal/login?next=<?= $e('/recital/' . $recital['slug']) ?>">Sign in</a> to listen.
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($recital['programme_note']): ?>
    <div class="note"><?= $e($recital['programme_note']) ?></div>
  <?php endif; ?>

  <div class="actions">
    <button type="button" class="btn" onclick="window.print()">Print this programme</button>
    <a class="btn" href="/recital">All recitals</a>
  </div>

  <div class="colophon">
    MAM Academy · Music, Arts, Mystery<br>
    <a href="/">academy.mamcentre.org</a>
  </div>

<?php else: ?>

  <header class="masthead" style="border-bottom-width:1px">
    <div class="kicker">The Recital Hall</div>
    <h1>Recitals</h1>
    <div class="subtitle">Where the practice goes.</div>
  </header>
  <div style="height:26px"></div>

  <?php if (!$list): ?>
    <div class="empty">No recitals have been published yet.<br>The next one will appear here.</div>
  <?php else:
    $now = date('Y-m-d H:i:s');
    foreach ($list as $r):
      $upcoming = $r['status'] === 'programmed' && (!$r['starts_at'] || $r['starts_at'] >= $now);
  ?>
    <a class="card" href="/recital/<?= $e($r['slug']) ?>">
      <span class="pill"><?= $upcoming ? 'Upcoming' : 'Performed' ?></span>
      <h2><?= $e($r['title']) ?></h2>
      <p>
        <?= $e($fmtDate($r['starts_at']) ?: 'Date to be confirmed') ?><?php if ($r['venue']): ?> · <?= $e($r['venue']) ?><?php endif; ?>
      </p>
    </a>
  <?php endforeach; endif; ?>

  <div class="colophon">
    MAM Academy · Music, Arts, Mystery<br>
    <a href="/">academy.mamcentre.org</a>
  </div>

<?php endif; ?>

</div>
</body>
</html>
