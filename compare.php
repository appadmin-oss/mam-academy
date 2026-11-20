<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Compare Pathways &amp; Programmes — MAM Academy</title>
<meta name="description" content="Side-by-side comparison of every MAM Academy programme — Certified Musician, Grand Musician, Crash and The MetaMusic Order. Pick what fits.">
<link rel="canonical" href="https://academy.mamcentre.org/compare">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Compare Pathways &amp; Programmes — MAM Academy">
<meta name="twitter:description" content="Side-by-side comparison of every MAM Academy programme — Certified Musician, Grand Musician, Crash and The MetaMusic Order. Pick what fits.">
<meta name="twitter:image" content="https://academy.mamcentre.org/mam-academy-logo.svg">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"https://academy.mamcentre.org/"},{"@type":"ListItem","position":2,"name":"Compare Programmes","item":"https://academy.mamcentre.org/compare"}]}</script>
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<meta property="og:title" content="Compare MAM Academy Programmes">
<meta property="og:description" content="Side-by-side comparison of every pathway and intensive.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260920">
<style>
.compare-shell{padding:32px 24px 96px}
/* Horizontal-scroll table with scroll-snap per programme column, a sticky
   labels column, and CSS-only edge shadows that appear only when there is
   more to scroll (Lea Verou technique via background-attachment:local). */
.compare-table-wrap{
  overflow-x:auto;-webkit-overflow-scrolling:touch;
  background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-lg);
  scroll-snap-type:x proximity;scroll-padding-left:200px;
  background-image:
    linear-gradient(to right,var(--bg-surface) 30%,rgba(26,18,11,0)),
    linear-gradient(to right,rgba(26,18,11,0),var(--bg-surface) 70%),
    linear-gradient(to right,rgba(0,0,0,0.45),rgba(0,0,0,0)),
    linear-gradient(to left,rgba(0,0,0,0.45),rgba(0,0,0,0));
  background-position:0 0,100% 0,0 0,100% 0;
  background-repeat:no-repeat;
  background-size:48px 100%,48px 100%,16px 100%,16px 100%;
  background-attachment:local,local,scroll,scroll;
}
.compare-table{width:100%;min-width:900px;border-collapse:separate;border-spacing:0;font-size:0.9rem}
.compare-table th,.compare-table td{padding:14px 16px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}
.compare-table th.row-head{background:var(--bg-raised);font-size:0.75rem;font-weight:800;color:var(--text-muted);letter-spacing:0.1em;text-transform:uppercase;width:200px;position:sticky;left:0;z-index:2;box-shadow:8px 0 18px -10px rgba(0,0,0,0.7)}
.compare-table thead th{background:var(--bg-raised);padding:18px 16px;vertical-align:bottom}
.compare-table thead th.row-head{z-index:4}
.compare-table thead th.col-head{position:relative;border-left:1px solid var(--border);scroll-snap-align:start}
/* Zebra striping + row hover for readability across many rows. The sticky
   row-head column needs the SAME treatment as the body cells, otherwise it
   visually detaches from its row when zebra/hover paints the rest. */
.compare-table tbody tr:nth-child(even) td,
.compare-table tbody tr:nth-child(even) th.row-head{background:rgba(255,255,255,0.025)}
.compare-table tbody tr:hover td,
.compare-table tbody tr:hover th.row-head{background:rgba(224,138,26,0.08)}
.compare-table tbody tr:hover td.featured-col{background:rgba(224,138,26,0.12)}
.compare-table thead th.col-head.featured{background:linear-gradient(180deg,rgba(224,138,26,0.12),var(--bg-raised));border-left:1px solid var(--border-gold);border-right:1px solid var(--border-gold)}
.compare-table thead th.col-head.premium{background:linear-gradient(180deg,rgba(225,29,72,0.12),var(--bg-raised))}
.col-label{font-size:0.7rem;font-weight:800;color:var(--gold-bright);letter-spacing:0.12em;text-transform:uppercase;margin-bottom:8px}
.col-name{font-family:var(--font-serif);font-size:1.15rem;font-weight:700;color:var(--text);line-height:1.2;margin-bottom:4px}
.col-trinity{font-size:0.74rem;color:var(--text-muted);font-style:italic;margin-bottom:14px}
.col-price{font-family:var(--font-display);font-size:1.9rem;line-height:1;color:var(--text);margin-bottom:8px}
.col-price small{font-size:0.6em;color:var(--text-muted);font-family:var(--font-body);font-weight:500;letter-spacing:0;margin-left:2px}
.compare-table thead th.col-head.featured .col-price{background:linear-gradient(135deg,var(--gold-bright),var(--gold));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.col-tag{display:inline-block;margin-bottom:8px;padding:3px 10px;border-radius:100px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-size:0.62rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase}
.col-tag.tag-premium{background:linear-gradient(135deg,var(--red),var(--orange));color:#fff}
.compare-table td{color:var(--text-soft)}
.compare-table td.featured-col{background:rgba(224,138,26,0.04);border-left:1px solid var(--border-gold);border-right:1px solid var(--border-gold)}
.compare-table tr:last-child td,.compare-table tr:last-child th{border-bottom:none}
.compare-check{color:#34D399;font-weight:700}
.compare-dash{color:var(--text-faint)}
.compare-cta-row td{padding-top:22px;padding-bottom:24px}
.compare-cta{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border-radius:var(--radius-sm);font-size:0.82rem;font-weight:800;letter-spacing:0.05em;text-transform:uppercase;white-space:nowrap}
.compare-cta-primary{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;box-shadow:0 4px 14px rgba(224,138,26,0.35)}
.compare-cta-primary:hover{transform:translateY(-1px)}
.compare-cta-ghost{border:1px solid var(--border-gold);color:var(--gold-bright)}
.compare-cta-ghost:hover{background:rgba(224,138,26,0.08)}
.compare-hint{margin:18px 0 0;font-size:0.85rem;color:var(--text-muted);text-align:center}
.compare-hint i{color:var(--gold-bright);margin-right:6px}

/* Desktop table vs mobile widget visibility. The full table is the
   canonical content (best for SEO + no-JS); the mobile head-to-head
   widget takes over on small screens where a 6-col table can't work. */
.cmp-mobile{display:none}
@media (max-width:860px){
  .cmp-desktop{display:none}
  .cmp-mobile{display:block}
}

/* ── MAM Match recommender ─────────────────────────────────────────── */
.match-panel{max-width:680px;margin:0 auto 40px;background:linear-gradient(160deg,rgba(224,138,26,0.1),var(--bg-surface));border:1px solid var(--border-gold);border-radius:var(--radius-lg);padding:clamp(20px,4vw,30px);box-shadow:0 18px 48px rgba(0,0,0,0.35)}
.match-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px}
.match-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:0.74rem;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:var(--gold-bright)}
.match-progress{display:flex;gap:6px}
.match-progress span{width:26px;height:4px;border-radius:4px;background:var(--border-strong);transition:background 0.3s}
.match-progress span.on{background:linear-gradient(90deg,var(--gold),var(--gold-light))}
.match-q{font-family:var(--font-serif);font-size:clamp(1.25rem,3.5vw,1.6rem);font-weight:700;color:var(--text);margin:0 0 16px;line-height:1.2}
.match-options{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (min-width:520px){.match-options{grid-template-columns:repeat(3,1fr)}}
.match-opt{display:flex;flex-direction:column;align-items:center;gap:8px;padding:16px 12px;background:var(--bg-elevated);border:1.5px solid var(--border-strong);border-radius:var(--radius-md);color:var(--text-soft);cursor:pointer;transition:all 0.18s var(--ease);font-size:0.86rem;font-weight:600;text-align:center;min-height:88px;justify-content:center}
.match-opt i{font-size:1.3rem;color:var(--gold-bright)}
.match-opt:hover,.match-opt:focus-visible{border-color:var(--gold-bright);background:rgba(224,138,26,0.08);transform:translateY(-2px);outline:none}
.match-sub{margin:14px 0 0;font-size:0.8rem;color:var(--text-muted);text-align:center}
.match-back{margin-top:14px;background:none;border:0;color:var(--text-muted);font-weight:700;font-size:0.84rem;cursor:pointer;padding:4px}
.match-back:hover{color:var(--gold-bright)}
.match-result{text-align:center;animation:matchIn 0.4s var(--ease)}
@keyframes matchIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.match-result-badge{display:inline-flex;align-items:center;gap:7px;padding:5px 14px;border-radius:100px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-size:0.7rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:12px}
.match-result-name{font-family:var(--font-serif);font-size:1.7rem;font-weight:700;color:var(--text);line-height:1.15}
.match-result-name span{color:var(--text-muted);font-weight:400;font-size:1.1rem}
.match-result-price{font-family:var(--font-display);font-size:2.4rem;color:var(--gold-bright);margin:6px 0 10px}
.match-result-price small{font-size:0.42em;color:var(--text-muted);font-family:var(--font-body)}
.match-result-why{font-size:0.95rem;color:var(--text-soft);line-height:1.6;max-width:440px;margin:0 auto 20px}
.match-result-actions{display:flex;flex-direction:column;gap:10px;max-width:380px;margin:0 auto}
@media (min-width:480px){.match-result-actions{flex-direction:row;justify-content:center}}
.match-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 22px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;letter-spacing:0.03em;text-transform:uppercase;font-size:0.86rem;border-radius:var(--radius-sm);box-shadow:0 6px 18px rgba(224,138,26,0.4)}
.match-cta:hover{transform:translateY(-1px)}
.match-secondary{padding:13px 22px;background:transparent;border:1px solid var(--border-gold);color:var(--gold-bright);font-weight:700;font-size:0.84rem;border-radius:var(--radius-sm);cursor:pointer}
.match-secondary:hover{background:rgba(224,138,26,0.08)}
.match-restart{margin-top:16px;background:none;border:0;color:var(--text-muted);font-size:0.82rem;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.match-restart:hover{color:var(--gold-bright)}

/* ── Mobile programme cards (clean, spacious, stacked) ──────────────────
   Replaces the old cramped 2-up head-to-head. One generous card per
   programme — scannable, with a disclosure for the full breakdown. */
.pcards{display:flex;flex-direction:column;gap:16px}
.pcard{position:relative;background:var(--bg-surface);border:1px solid var(--border);border-radius:20px;padding:24px 20px 20px;transition:border-color .2s var(--ease),transform .2s var(--ease)}
.pcard.is-featured{border-color:var(--border-gold);background:linear-gradient(180deg,rgba(224,138,26,0.08),var(--bg-surface) 42%)}
.pcard.is-premium{border-color:rgba(225,29,72,0.4);background:linear-gradient(180deg,rgba(225,29,72,0.09),var(--bg-surface) 42%)}
.pcard.pc-flash{border-color:var(--gold-bright);transform:translateY(-2px);box-shadow:0 0 0 3px rgba(252,198,71,0.25),0 18px 44px rgba(0,0,0,0.4)}
.pcard-tag{position:absolute;top:-11px;left:20px;display:inline-flex;align-items:center;gap:4px;font-size:0.62rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;padding:5px 12px;border-radius:100px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;box-shadow:0 4px 12px rgba(224,138,26,0.35)}
.pcard.is-premium .pcard-tag{background:linear-gradient(135deg,var(--red,#E11D48),var(--orange,#F05A28));color:#fff;box-shadow:0 4px 12px rgba(225,29,72,0.35)}
.pcard-label{font-size:0.72rem;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;color:var(--gold-bright)}
.pcard.is-premium .pcard-label{color:#FB7185}
.pcard-name{font-family:var(--font-serif);font-size:1.5rem;font-weight:700;color:var(--text);line-height:1.15;margin:3px 0 2px}
.pcard-trinity{font-size:0.82rem;color:var(--text-muted);font-style:italic}
.pcard-price{font-family:var(--font-display);font-size:2.6rem;line-height:1;color:var(--text);margin:14px 0 4px}
.pcard-price small{font-size:0.34em;color:var(--text-muted);font-family:var(--font-body);font-weight:500;margin-left:3px;letter-spacing:0}
.pc-rows{margin:16px 0 0;border-top:1px solid var(--border)}
.pc-row{display:flex;align-items:baseline;justify-content:space-between;gap:16px;padding:13px 0;border-bottom:1px solid var(--border)}
.pc-row dt{flex:0 0 auto;font-size:0.78rem;font-weight:600;color:var(--text-muted)}
.pc-row dd{margin:0;text-align:right;font-size:0.92rem;color:var(--text);line-height:1.35}
.pc-more .pc-rows{margin-top:0;border-top:none}
.pc-yes{color:#34D399;font-weight:700;display:inline-flex;align-items:center;gap:4px}
.pc-dash{color:var(--text-faint)}
.pc-toggle{display:inline-flex;align-items:center;gap:7px;margin:14px 0 0;padding:8px 2px;background:none;border:0;color:var(--gold-bright);font-weight:700;font-size:0.84rem;cursor:pointer}
.pcard.is-premium .pc-toggle{color:#FB7185}
.pc-toggle i{font-size:0.72rem;transition:transform .2s var(--ease)}
.pcard-cta{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;padding:15px;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;font-size:0.92rem;letter-spacing:0.03em;border-radius:12px;box-shadow:0 6px 18px rgba(224,138,26,0.32);min-height:52px}
.pcard.is-premium .pcard-cta{background:linear-gradient(135deg,#E11D48,#F05A28);color:#fff;box-shadow:0 6px 18px rgba(225,29,72,0.32)}
.pcard-cta:active{transform:translateY(1px)}
.cmp-foot{margin:22px 4px 0;font-size:0.84rem;color:var(--text-muted);line-height:1.6;text-align:center}
.cmp-foot a{color:var(--gold-bright);font-weight:600}
.cmp-foot i{color:var(--gold-bright);margin-right:5px}

@media (max-width:768px){
  .col-name{font-size:1.02rem}
  .col-price{font-size:1.6rem}
  .compare-shell{padding:24px 16px 80px}
}
@media (max-width:380px){
  .pcard{padding:22px 16px 18px}
  .pcard-price{font-size:2.3rem}
}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<div id="preloader" role="status"><div class="preloader-logo"><img src="mam-academy-logo.svg" alt="" onerror="this.style.display='none'"><div class="preloader-name">MAM ACADEMY</div><div class="preloader-tag">Compare programmes</div></div><div class="waves"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></div>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1465225314224-587cd83d322b?w=1920&q=80');padding:90px 24px 50px">
    <div class="page-hero-inner">
      <nav class="breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><a href="pathways">Pathways</a><i class="fa-solid fa-chevron-right"></i><span>Compare</span></nav>
      <div class="page-hero-eyebrow"><i class="fa-solid fa-table"></i>Side-by-side</div>
      <h1 class="page-hero-title">Find Your <span class="highlight-gold">Programme</span></h1>
      <p class="page-hero-lede">Answer four quick questions and let <strong>MAM Match</strong> recommend your fit — or compare any two programmes head-to-head below.</p>
    </div>
  </header>

  <section class="compare-shell">
    <div class="container">
      <!-- MAM Match: guided, on-device recommender (no API). Progressive
           enhancement — populated + revealed by compare.js; stays hidden
           (so no empty box) when JS is unavailable. -->
      <div class="match-panel" id="mamMatch" hidden></div>

      <!-- Mobile head-to-head compare (replaces the table on small screens) -->
      <div class="cmp-mobile" id="mobileCompare" aria-label="Compare two programmes"></div>

      <!-- Full table — desktop / no-JS / SEO canonical content -->
      <div class="cmp-desktop compare-table-wrap">
        <table class="compare-table">
          <caption class="sr-only">Comparison of the four MAM Academy programmes across price, levels, modes, intelligences and certification.</caption>
          <thead>
            <tr>
              <th scope="row" class="row-head"></th>
              <th scope="col" class="col-head featured">
                <span class="col-tag">Most chosen</span>
                <div class="col-label">Certified Musician</div>
                <div class="col-name">One tradition</div>
                <div class="col-trinity">All four levels · 12 weeks</div>
                <div class="col-price">from <span data-cur-sym>$</span><span data-price="240">240</span><small></small></div>
              </th>
              <th scope="col" class="col-head premium">
                <span class="col-tag tag-premium" style="display:inline-flex;align-items:center;gap:4px"><iconify-icon icon="fluent-emoji:trophy" width="13" aria-hidden="true"></iconify-icon>Flagship</span>
                <div class="col-label">Grand Musician</div>
                <div class="col-name">All three traditions</div>
                <div class="col-trinity">All four levels · 12 weeks · 13% off</div>
                <div class="col-price">from <span data-cur-sym>$</span><span data-price="313">313</span><small></small></div>
              </th>
              <th scope="col" class="col-head">
                <div class="col-label">Crash</div>
                <div class="col-name">Intensive</div>
                <div class="col-trinity">6 weeks · any school</div>
                <div class="col-price"><span data-cur-sym>$</span><span data-price="70">70</span><small>/flat</small></div>
              </th>
              <th scope="col" class="col-head premium">
                <span class="col-tag tag-premium" style="display:inline-flex;align-items:center;gap:4px"><iconify-icon icon="fluent-emoji:fire" width="13" aria-hidden="true"></iconify-icon>Premium</span>
                <div class="col-label">MetaMusic Order</div>
                <div class="col-name">Civilization Architect</div>
                <div class="col-trinity">12 weeks · post-graduate</div>
                <div class="col-price"><span data-cur-sym>$</span><span data-price="60">60</span><small>/12wk</small></div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <th scope="row" class="row-head">Best for</th>
              <td class="featured-col">Focused mastery in one tradition</td>
              <td>Complete musicians across all traditions</td>
              <td>Quick wins · summer · busy pros</td>
              <td>Producers, performers, cultural leaders</td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Length</th>
              <td class="featured-col">12 weeks</td>
              <td>12 weeks</td>
              <td>6 weeks fixed</td>
              <td>12 weeks fixed</td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Online &amp; Onsite</th>
              <td class="featured-col"><span class="compare-check">Both</span></td>
              <td><span class="compare-check">Both</span></td>
              <td><span class="compare-check">Both</span></td>
              <td><span class="compare-check">Online focus</span></td>
            </tr>
            <tr>
              <th scope="row" class="row-head">MAM Auditer required</th>
              <td class="featured-col"><span class="compare-check">✓</span></td>
              <td><span class="compare-check">✓</span></td>
              <td>Recommended</td>
              <td><span class="compare-check">✓ + interview</span></td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Certification levels</th>
              <td class="featured-col">All four (Pre-Cert → Diploma)</td>
              <td>All four (Pre-Cert → Diploma)</td>
              <td><span class="compare-dash">—</span></td>
              <td>Post-Diploma</td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Traditions</th>
              <td class="featured-col">One (Contemporary, Classical or African)</td>
              <td>All three (African + Classical + Contemporary)</td>
              <td>Per chosen course</td>
              <td><span class="compare-dash">—</span></td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Supporting courses</th>
              <td class="featured-col">Rhythm · APE · Meta Music · Theory</td>
              <td>Rhythm · APE · Meta Music · Theory</td>
              <td>Per chosen course</td>
              <td><span class="compare-check">Core focus</span></td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Performance Intelligence</th>
              <td class="featured-col"><span class="compare-check">✓✓</span></td>
              <td><span class="compare-check">✓✓✓</span></td>
              <td>Per chosen course</td>
              <td><span class="compare-check">✓✓✓</span></td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Certificate at end</th>
              <td class="featured-col"><span class="compare-check">✓</span></td>
              <td><span class="compare-check">✓ Diploma</span></td>
              <td>Certificate of completion</td>
              <td><span class="compare-check">Order commission</span></td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Registration fee (+<span data-amt-usd="5">$5</span>)</th>
              <td class="featured-col">Once</td>
              <td>Once</td>
              <td>Once</td>
              <td>Included</td>
            </tr>
            <tr>
              <th scope="row" class="row-head">Graduation / Included</th>
              <td class="featured-col"><span class="compare-check">Included</span></td>
              <td><span class="compare-check">Included</span></td>
              <td>Certificate of completion</td>
              <td>Included</td>
            </tr>
            <tr style="background:var(--bg)">
              <th scope="row" class="row-head" style="font-size:0.68rem">Learn more</th>
              <td class="featured-col"><a href="pathways#certified-musician" style="color:var(--gold-bright);font-size:0.82rem">Pathway page →</a></td>
              <td><a href="pathways#grand-musician" style="color:var(--gold-bright);font-size:0.82rem">Pathway page →</a></td>
              <td><a href="crash" style="color:var(--gold-bright);font-size:0.82rem">Crash page →</a></td>
              <td><a href="metamusic-order" style="color:#FB7185;font-size:0.82rem">The Order →</a></td>
            </tr>
            <tr class="compare-cta-row">
              <th scope="row" class="row-head"></th>
              <td class="featured-col"><a href="apply?pathway=certified-musician" class="compare-cta compare-cta-primary">Apply</a></td>
              <td><a href="apply?pathway=grand-musician" class="compare-cta compare-cta-primary">Apply</a></td>
              <td><a href="apply?pathway=crash" class="compare-cta compare-cta-ghost">Apply</a></td>
              <td><a href="apply?pathway=meta-order" class="compare-cta compare-cta-primary" style="background:linear-gradient(135deg,#E11D48,#F05A28);color:#fff">Apply</a></td>
            </tr>
          </tbody>
        </table>
        <p class="compare-hint"><i class="fa-solid fa-circle-info"></i>Not sure? <a href="auditer" style="color:var(--gold-bright)">Book MAM Auditer first</a> — we'll place you accurately.</p>
      </div>
    </div>
  </section>

  <section id="next-step" aria-label="Continue your journey">
    <div class="next-step-wrap">
      <a class="next-step-back" href="pathways">
        <i class="fa-solid fa-arrow-left"></i>
        <div><div class="next-step-back-label">Previous · Step 4</div><strong>Pathways overview</strong></div>
      </a>
      <a class="next-step-card" href="auditer">
        <div class="next-step-eyebrow"><span class="num">06</span>Up next · Continue your journey</div>
        <div class="next-step-title">Book MAM Auditer <i class="fa-solid fa-arrow-right"></i></div>
        <p class="next-step-desc">Before you apply, get assessed. MAM Auditer measures 10 dimensions of musical readiness so you're placed where you actually belong.</p>
        <div class="next-step-meta">
          <span><i class="fa-solid fa-clipboard-check"></i>10 dimensions</span>
          <span><i class="fa-solid fa-bullseye"></i>Accurate placement</span>
        </div>
      </a>
    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20261120a"></script>
<script src="assets/js/main.js?v=20260920"></script>
<script src="assets/js/compare.js?v=20260530a"></script>
</body>
</html>
