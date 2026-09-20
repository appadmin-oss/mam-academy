<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<meta http-equiv="Permissions-Policy" content="microphone=(self), camera=(), geolocation=(), interest-cohort=()">
<title>Music Notation Showcase — Classical, Contemporary &amp; African 6-line | MAM Academy</title>
<meta name="description" content="See one melody written three ways — a Classical 5-line staff, a contemporary piano-roll, and the MAM African 6-line tone grid — and play it back in your browser, watching each system light up in time.">
<meta name="robots" content="index, follow, max-image-preview:large">
<meta property="og:type" content="website">
<meta property="og:url" content="https://academy.mamcentre.org/notation">
<meta property="og:site_name" content="MAM Academy">
<meta property="og:title" content="Music Notation Showcase — three systems, one melody">
<meta property="og:description" content="Classical staff, contemporary piano-roll and the African 6-line tone grid — interactive, with realistic playback. From MAM Academy.">
<meta property="og:image" content="https://academy.mamcentre.org/api/og?t=Notation+Showcase&amp;s=Classical%2C+Contemporary+%26+African+6-line+-+one+melody%2C+three+ways.&amp;e=Notation">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260920">
<style>
.nt-shell{max-width:1100px;margin:0 auto;padding:30px 22px 96px}
.nt-hero{text-align:center;margin:8px 0 26px}
.nt-hero h1{font-family:'Cinzel',serif;font-size:clamp(1.9rem,4vw,2.9rem);margin:0 0 10px;color:var(--text)}
.nt-hero p{color:var(--text-muted);max-width:640px;margin:0 auto;line-height:1.6}
.nt-controls{display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;align-items:center;margin-bottom:16px}
.nt-systems{display:inline-flex;gap:6px;padding:6px;background:var(--bg-surface);border:1px solid var(--border);border-radius:14px}
.nt-sys{padding:9px 16px;border:0;border-radius:10px;font-weight:700;font-size:0.86rem;color:var(--text-muted);background:transparent;cursor:pointer;min-height:42px}
.nt-sys:hover{color:var(--text)}
.nt-sys.active{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B}
.nt-transport{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.nt-tempo{display:flex;align-items:center;gap:8px;font-size:0.82rem;color:var(--text-muted)}
.nt-tempo-val{color:var(--gold-bright);font-weight:800;min-width:2.2em;text-align:right}
.nt-tempo-range{accent-color:var(--gold)}
.nt-stage{background:var(--bg-surface);border:1px solid var(--border);border-radius:18px;padding:18px 8px 12px;box-shadow:0 20px 50px rgba(0,0,0,.28)}
.nt-title{text-align:center;color:var(--text-soft);font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:600;margin:0 0 8px}
.nt-render{overflow-x:auto;-webkit-overflow-scrolling:touch}
.nt-svg{display:block;min-width:520px;width:100%;height:auto;max-height:280px}
/* ── shared note colours ── */
.nt-staffline,.nt-sixline{stroke:var(--text-faint);stroke-width:1}
.nt-ledger{stroke:var(--text-faint);stroke-width:1}
.nt-clef{font-size:52px;fill:var(--gold-bright)}
.nt-rest{font-size:24px;fill:var(--text-muted)}
.nt-acc{font-size:16px;fill:var(--gold-bright)}
.nt-head{fill:var(--text);stroke:var(--text);stroke-width:1}
.nt-head.open{fill:transparent}
.nt-stem{stroke:var(--text);stroke-width:1.6}
.nt-flag{fill:none;stroke:var(--text);stroke-width:1.6}
.nt-roll-row{stroke:var(--border);stroke-width:1}
.nt-roll-row.c{stroke:var(--border-strong);stroke-dasharray:2 3}
.nt-roll-label{font-size:11px;fill:var(--text-faint)}
.nt-bar{opacity:.92;stroke:rgba(0,0,0,.25);stroke-width:1}
.nt-6label{font-size:11px;letter-spacing:.1em;text-transform:uppercase;fill:var(--gold-bright);opacity:.8}
.nt-tone{stroke:rgba(0,0,0,.3);stroke-width:1}
.nt-tone-label{font-size:8.5px;font-weight:800;fill:#1A120B}
/* ── playing highlight ── */
.nt-note{transition:transform .08s ease}
.nt-note.playing .nt-head,.nt-note.playing .nt-tone{fill:var(--gold-bright);stroke:var(--gold)}
.nt-note.playing .nt-bar{fill:var(--gold-bright)!important;filter:drop-shadow(0 0 6px rgba(252,198,71,.7))}
.nt-note.playing{transform:translateY(-2px)}
.nt-examples{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:22px 0 0}
.nt-ex-label{color:var(--text-muted);font-size:0.85rem;margin-right:4px}
.nt-ex{display:flex;flex-direction:column;gap:2px;text-align:left;padding:9px 14px;border:1px solid var(--border);border-radius:12px;background:var(--bg-surface);color:var(--text);cursor:pointer;min-height:46px}
.nt-ex:hover{border-color:var(--border-gold)}
.nt-ex.active{border-color:var(--gold);background:var(--bg-raised)}
.nt-ex strong{font-size:0.9rem}
.nt-ex span{font-size:0.74rem;color:var(--text-muted)}
.nt-note{}
.nt-render+.nt-note,p.nt-note{color:var(--text-muted);font-size:0.88rem;line-height:1.6;margin:20px auto 0;max-width:680px;text-align:center}
.nt-empty{display:flex;align-items:center;justify-content:center;gap:10px;min-height:160px;color:var(--text-muted);font-size:0.95rem;text-align:center;padding:0 16px}
.nt-empty i{color:var(--gold-bright);font-size:1.3rem}
.nt-capture{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:16px 0 0;padding:14px 16px;background:rgba(224,138,26,0.06);border:1px solid var(--border-gold);border-radius:14px}
.nt-rec{min-height:44px}
.nt-rec.recording{background:rgba(244,63,94,0.16);border:1px solid rgba(244,63,94,0.5);color:#FCA5A5;animation:nt-pulse 1.1s ease-in-out infinite}
@keyframes nt-pulse{0%,100%{box-shadow:0 0 0 0 rgba(244,63,94,.4)}50%{box-shadow:0 0 0 6px rgba(244,63,94,0)}}
.nt-rec-status{color:var(--text-muted);font-size:0.86rem}
@media (max-width:640px){.nt-controls{justify-content:center}.nt-svg{min-width:460px}}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<?php include __DIR__ . '/partials/header.html'; ?>
<main id="main">
  <div class="nt-shell">
    <div class="nt-hero">
      <h1>One melody, three notations</h1>
      <p>At MAM we read music in more than one language. Here is a single melody written as a
         <strong>Classical</strong> staff, a <strong>Contemporary</strong> piano-roll, and the
         <strong>African&nbsp;6-line</strong> tone grid — press play and watch each system light up in time.</p>
    </div>
    <div id="ntApp" aria-live="polite"></div>
  </div>
</main>
<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/notation.js?v=20260709a" defer></script>
</body>
</html>
