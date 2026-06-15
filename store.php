<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Store &amp; Library — MAM Academy</title>
<meta name="description" content="The MAM Academy Store — sheet music, handouts, fakebooks, recordings and learning bundles, plus instructor add-ons and physical merch. Region-aware delivery.">
<link rel="canonical" href="https://academy.mamcentre.org/store">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Store &amp; Library — MAM Academy">
<meta name="twitter:description" content="The MAM Academy Store — sheet music, handouts, fakebooks, recordings and learning bundles, plus instructor add-ons and physical merch. Region-aware delivery.">
<meta name="twitter:image" content="https://academy.mamcentre.org/mam-academy-logo.svg">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"https://academy.mamcentre.org/"},{"@type":"ListItem","position":2,"name":"Store","item":"https://academy.mamcentre.org/store"}]}</script>
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<meta property="og:title" content="MAM Academy Store">
<meta property="og:description" content="Sheet music, handouts, recordings and add-ons from the MAM Centre.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260615a">
<style>
/* ── Shell + layout ───────────────────────────────────────────── */
.store-shell{max-width:1240px;margin:0 auto;padding:36px 22px 96px}

/* ── Toolbar: search + categories + region ──────────────────────
   Reorganised so search is primary on desktop AND mobile. Category
   pills stay scrollable horizontally so the toolbar can never break
   the layout on narrow screens. */
.store-toolbar{display:grid;grid-template-columns:minmax(0,1.4fr) auto;gap:14px;align-items:center;margin:24px 0 22px}
@media (max-width:700px){.store-toolbar{grid-template-columns:1fr}}
.st-search{position:relative}
.st-search input{width:100%;padding:14px 16px 14px 44px;background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;color:var(--text);font-size:0.96rem;font-family:inherit;min-height:48px;outline:none}
.st-search input:focus{border-color:var(--gold-bright);box-shadow:0 0 0 4px rgba(252,198,71,0.22)}
.st-search input::placeholder{color:var(--text-muted)}
.st-search .icn{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:0.95rem}
.st-region{display:inline-flex;align-items:center;gap:8px;font-size:0.82rem;color:var(--text-muted);padding:0 4px;white-space:nowrap}
.st-region b{color:var(--text)}
.st-region i{color:var(--gold-bright)}

/* Category pills — scroll horizontally if they overflow */
.st-categories{display:flex;gap:6px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding:2px 0;margin-bottom:28px}
.st-categories::-webkit-scrollbar{display:none}
.st-categories button{flex-shrink:0;padding:8px 14px;border-radius:100px;font-size:0.84rem;font-weight:700;color:var(--text-muted);background:var(--bg-surface);border:1px solid var(--border);cursor:pointer;letter-spacing:0.01em;transition:all .15s var(--ease);min-height:36px}
.st-categories button:hover{border-color:var(--border-gold);color:var(--text)}
.st-categories button.active{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;border-color:transparent}
.st-categories .count{margin-left:6px;font-size:0.72rem;opacity:0.55}
.st-categories button.active .count{opacity:0.7}

/* ── Grid + cards ──────────────────────────────────────────────
   Cards are flex-column so the foot (price + CTA) always sits at the
   bottom regardless of description length. */
.store-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:22px}
.item-card{position:relative;background:var(--bg-surface);border:1px solid var(--border);border-radius:18px;overflow:hidden;display:flex;flex-direction:column;transition:transform .2s var(--ease),border-color .2s var(--ease),box-shadow .2s var(--ease)}
.item-card:hover{transform:translateY(-3px);border-color:var(--border-gold);box-shadow:0 14px 36px rgba(0,0,0,0.35)}
.item-card.unavailable{opacity:0.55;filter:saturate(0.6)}
.item-card.unavailable:hover{transform:none;border-color:var(--border);box-shadow:none}
.item-thumb{aspect-ratio:4/3;background:linear-gradient(135deg,rgba(252,198,71,0.16),var(--bg-raised));display:flex;align-items:center;justify-content:center;color:var(--gold-bright);font-size:2.2rem;position:relative;overflow:hidden}
.item-thumb img{width:100%;height:100%;object-fit:cover;display:block}

/* Goods-type chip in the upper corner of the thumb */
.item-chip{position:absolute;top:10px;left:10px;font-size:0.66rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;padding:4px 9px;border-radius:100px;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
.item-chip.gd-digital{background:rgba(99,102,241,0.85);color:#fff}
.item-chip.gd-physical{background:rgba(52,211,153,0.85);color:#fff}
.item-chip.gd-service{background:rgba(251,191,36,0.85);color:#1A120B}

/* Availability ribbon top-right */
.item-ribbon{position:absolute;top:10px;right:10px;font-size:0.66rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;padding:4px 9px;border-radius:100px;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
.item-ribbon.avail-sold_out{background:rgba(244,63,94,0.9);color:#fff}
.item-ribbon.avail-preorder{background:rgba(252,198,71,0.9);color:#1A120B}
.item-ribbon.not-here{background:rgba(0,0,0,0.65);color:#fff}

.item-body{padding:18px 20px 14px;display:flex;flex-direction:column;gap:6px;flex:1}
.item-cat{font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--gold-bright);font-weight:800}
.item-name{font-family:var(--font-serif);font-size:1.15rem;font-weight:700;color:var(--text);line-height:1.28;margin:0}
.item-desc{font-size:0.86rem;color:var(--text-soft);line-height:1.55;margin:4px 0 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

.item-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;border-top:1px solid var(--border);background:rgba(0,0,0,0.12)}
.item-price{font-family:var(--font-display);font-size:1.65rem;color:var(--text);line-height:1;letter-spacing:0.02em}
.item-price small{font-size:0.5em;color:var(--text-muted);font-family:var(--font-body);font-weight:500;letter-spacing:0;margin-left:3px}
.item-buy{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;border-radius:10px;background:#1A120B;color:#FBBF24;font-weight:700;font-size:0.82rem;letter-spacing:0.08em;text-transform:uppercase;border:0;cursor:pointer;min-height:42px;transition:all .15s var(--ease)}
.item-buy:hover:not(:disabled){background:#000;transform:translateY(-1px)}
.item-buy:disabled{opacity:0.45;cursor:not-allowed;background:var(--bg-raised);color:var(--text-muted)}
.item-buy i{font-size:0.86em}

/* ── Skeleton loader (replaces the spinner) ────────────────────
   Renders the card outline + animated shimmer so users see structure
   while the catalogue loads. Much better perceived performance. */
.skeleton-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:22px}
.skel-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.skel{background:linear-gradient(90deg,var(--bg-raised) 0,rgba(255,255,255,0.05) 50%,var(--bg-raised) 100%);background-size:200% 100%;animation:shimmer 1.4s linear infinite}
@keyframes shimmer{from{background-position:200% 0}to{background-position:-200% 0}}
.skel-thumb{aspect-ratio:4/3}
.skel-body{padding:18px 20px 14px}
.skel-line{height:12px;border-radius:6px;margin-bottom:10px}
.skel-line.short{width:38%}
.skel-line.tall{height:22px;width:78%}
.skel-foot{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-top:1px solid var(--border)}
.skel-foot .skel-line{margin:0;height:24px;border-radius:6px}

/* ── Empty states ──────────────────────────────────────────────── */
.store-empty{padding:64px 24px;text-align:center;color:var(--text-soft);background:var(--bg-surface);border:1px dashed var(--border);border-radius:18px;max-width:520px;margin:0 auto}
.store-empty .icn{font-size:2.4rem;color:var(--gold-bright);opacity:0.65;margin-bottom:14px}
.store-empty h3{font-family:var(--font-serif);color:var(--text);font-size:1.4rem;margin:0 0 8px}
.store-empty p{margin:0 0 6px;line-height:1.7}

/* ── Checkout modal — clean, focused, no display:flex bug ─────
   Crucial: when the modal is hidden via [hidden] attribute, the
   browser default is display:none, BUT a class-based display:flex
   beats that by specificity. So we explicitly override the [hidden]
   case to win — and ALSO use a .is-open class as a belt-and-braces
   second toggle so even if `hidden` somehow strips, the modal stays
   hidden until JS explicitly opens it. */
.modal-backdrop{position:fixed;inset:0;background:rgba(10,7,4,0.78);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:1000;align-items:center;justify-content:center;padding:18px;opacity:0;visibility:hidden;transition:opacity .2s var(--ease),visibility .2s var(--ease);display:flex}
.modal-backdrop[hidden],
.modal-backdrop:not(.is-open){opacity:0;visibility:hidden;pointer-events:none}
.modal-backdrop.is-open{opacity:1;visibility:visible}
.modal-backdrop[hidden]{display:flex !important;opacity:0 !important;visibility:hidden !important;pointer-events:none !important}

.modal{background:var(--bg-surface);border:1px solid var(--border-gold);border-radius:20px;width:100%;max-width:540px;max-height:calc(100vh - 36px);overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,0.5);transform:translateY(20px) scale(0.98);opacity:0;transition:transform .25s var(--ease),opacity .25s var(--ease)}
.modal-backdrop.is-open .modal{transform:translateY(0) scale(1);opacity:1}

.modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:24px 28px 16px;border-bottom:1px solid var(--border)}
.modal-head .titles{flex:1;min-width:0}
.modal-head h2{font-family:var(--font-serif);font-size:1.35rem;margin:0 0 4px;color:var(--text);line-height:1.25}
.modal-head .sub{color:var(--text-muted);font-size:0.88rem;margin:0;line-height:1.5}
.modal-close{flex-shrink:0;width:36px;height:36px;border-radius:50%;background:var(--bg-raised);border:1px solid var(--border);color:var(--text-muted);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:0.95rem;transition:all .15s var(--ease)}
.modal-close:hover{background:var(--bg-elevated);color:var(--text);border-color:var(--border-strong)}

.modal-body{padding:20px 28px}
.modal-body label{display:block;font-size:0.74rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin:14px 0 6px}
.modal-body label:first-child{margin-top:0}
.modal-body label.req::after{content:" *";color:#FCA5A5}
.modal-body input,
.modal-body textarea{width:100%;padding:13px 14px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:16px;font-family:inherit;min-height:46px;outline:none;transition:border-color .15s var(--ease),box-shadow .15s var(--ease)}
.modal-body input:focus,
.modal-body textarea:focus{border-color:var(--gold-bright);box-shadow:0 0 0 4px rgba(252,198,71,0.22)}
.modal-body textarea{min-height:84px;resize:vertical;line-height:1.5}

/* Two-column row for name+email on desktop */
.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media (max-width:520px){.modal-grid{grid-template-columns:1fr;gap:0}}

/* Order summary inside the modal */
.modal-summary{margin:18px 0 0;padding:14px 16px;background:var(--bg-raised);border:1px solid var(--border);border-radius:12px}
.modal-summary .row{display:flex;justify-content:space-between;align-items:center;gap:14px;font-size:0.88rem;color:var(--text-soft);padding:2px 0}
.modal-summary .row.total{padding-top:10px;margin-top:6px;border-top:1px dashed var(--border);font-size:1rem;color:var(--text);font-weight:700}
.modal-summary .row.total b{font-family:var(--font-display);font-size:1.55rem;color:var(--gold-bright);letter-spacing:0.02em;font-weight:normal}

.modal-foot{padding:18px 28px 24px;border-top:1px solid var(--border);background:rgba(0,0,0,0.12)}
.modal-foot .actions{display:flex;gap:10px}
.modal-foot button{flex:1;padding:14px;border-radius:10px;font-weight:800;font-size:0.92rem;cursor:pointer;min-height:48px;border:0;letter-spacing:0.04em;transition:all .15s var(--ease)}
.modal-foot .cancel{background:transparent;border:1px solid var(--border-strong);color:var(--text-muted);flex:0 0 auto;padding:14px 22px}
.modal-foot .cancel:hover{background:var(--bg-raised);color:var(--text)}
.modal-foot .buy{background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-size:0.96rem;text-transform:uppercase;letter-spacing:0.06em;display:inline-flex;align-items:center;justify-content:center;gap:8px}
.modal-foot .buy:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 22px rgba(224,138,26,0.4)}
.modal-foot .buy:disabled{opacity:0.6;cursor:wait}
.modal-foot .trust{margin:10px 0 0;text-align:center;font-size:0.74rem;color:var(--text-muted);letter-spacing:0.02em}
.modal-foot .trust i{color:var(--gold-bright);margin-right:4px}

.modal-err{display:none;margin:14px 28px 0;padding:11px 14px;border-radius:10px;background:rgba(244,63,94,0.10);border:1px solid rgba(244,63,94,0.35);color:#FECDD3;font-size:0.86rem;line-height:1.5}
.modal-err.show{display:block}

/* Success state — replaces the form with a centered confirmation */
.modal-success{display:none;padding:40px 28px;text-align:center}
.modal-success.show{display:block}
.modal-success .check{width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#34D399,#10B981);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:18px;box-shadow:0 8px 28px rgba(16,185,129,0.4)}
.modal-success h3{font-family:var(--font-serif);font-size:1.35rem;color:var(--text);margin:0 0 8px}
.modal-success p{color:var(--text-soft);margin:0 0 18px;line-height:1.65;font-size:0.94rem}
.modal-success .ref{font-family:'SF Mono',Menlo,Consolas,monospace;font-size:0.86rem;color:var(--gold-bright);background:var(--bg-raised);padding:6px 12px;border-radius:6px;display:inline-block;margin-bottom:18px}

/* Spinner */
.spinner-sm{display:inline-block;width:16px;height:16px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin 0.7s linear infinite;vertical-align:-3px}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Cart: floating button, slide-in drawer, toast ───────────── */
.cart-fab{position:fixed;right:20px;bottom:20px;z-index:980;display:inline-flex;align-items:center;gap:9px;padding:13px 18px;border-radius:100px;border:0;cursor:pointer;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;font-size:0.9rem;letter-spacing:0.03em;box-shadow:0 10px 30px rgba(224,138,26,0.42);transition:transform .18s var(--ease-bounce),box-shadow .2s var(--ease)}
.cart-fab:hover{transform:translateY(-2px)}
.cart-fab .cart-count{background:#1A120B;color:var(--gold-bright);min-width:22px;height:22px;border-radius:100px;display:inline-flex;align-items:center;justify-content:center;font-size:0.78rem;padding:0 6px}
.cart-fab[hidden]{display:none}
.cart-fab.bump{animation:cartBump .42s var(--ease-bounce)}
@keyframes cartBump{0%{transform:scale(1)}40%{transform:scale(1.13)}100%{transform:scale(1)}}

.cart-drawer{position:fixed;inset:0;z-index:1001;display:flex;justify-content:flex-end;opacity:0;visibility:hidden;transition:opacity .22s var(--ease),visibility .22s var(--ease)}
.cart-drawer.is-open{opacity:1;visibility:visible}
.cart-drawer .cd-backdrop{position:absolute;inset:0;background:rgba(10,7,4,0.7);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.cart-panel{position:relative;width:min(420px,100%);background:var(--bg-surface);border-left:1px solid var(--border-gold);display:flex;flex-direction:column;box-shadow:-20px 0 60px rgba(0,0,0,0.5);transform:translateX(28px);transition:transform .26s var(--ease)}
.cart-drawer.is-open .cart-panel{transform:translateX(0)}
.cart-panel-head{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--border)}
.cart-panel-head h2{font-family:var(--font-serif);font-size:1.3rem;margin:0;color:var(--text)}
.cart-panel-body{flex:1;overflow-y:auto;padding:4px 24px}
.cart-line{display:flex;gap:14px;padding:16px 0;border-bottom:1px solid var(--border)}
.cart-line-thumb{width:58px;height:58px;border-radius:12px;flex-shrink:0;background:linear-gradient(135deg,rgba(252,198,71,0.16),var(--bg-raised));display:flex;align-items:center;justify-content:center;color:var(--gold-bright);overflow:hidden}
.cart-line-thumb img{width:100%;height:100%;object-fit:cover}
.cart-line-main{flex:1;min-width:0}
.cart-line-name{font-weight:700;color:var(--text);font-size:0.94rem;margin:0 0 2px;line-height:1.3}
.cart-line-price{color:var(--text-muted);font-size:0.82rem}
.cart-line-foot{display:flex;align-items:center;justify-content:space-between;margin-top:9px}
.qty{display:inline-flex;align-items:center;border:1px solid var(--border-strong);border-radius:8px;overflow:hidden}
.qty button{width:30px;height:30px;background:var(--bg-raised);border:0;color:var(--text);cursor:pointer;font-size:1rem;line-height:1;transition:background .12s var(--ease)}
.qty button:hover{background:var(--bg-card);color:var(--gold-bright)}
.qty span{min-width:34px;text-align:center;font-weight:700;color:var(--text);font-size:0.9rem}
.cart-line-rm{background:none;border:0;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:inline-flex;align-items:center;gap:5px}
.cart-line-rm:hover{color:#FCA5A5}
.cart-panel-foot{padding:18px 24px 24px;border-top:1px solid var(--border);background:rgba(0,0,0,0.14)}
.cart-panel-foot .sub{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;font-size:0.88rem;color:var(--text-soft)}
.cart-panel-foot .sub.grand{margin:8px 0 14px;font-size:1rem;color:var(--text);font-weight:700}
.cart-panel-foot .sub.grand b{font-family:var(--font-display);font-size:1.5rem;color:var(--gold-bright);font-weight:normal;letter-spacing:0.02em}
.cart-checkout{width:100%;padding:15px;border-radius:12px;border:0;cursor:pointer;background:linear-gradient(135deg,var(--gold),var(--gold-light));color:#1A120B;font-weight:800;font-size:0.96rem;letter-spacing:0.04em;text-transform:uppercase;transition:transform .15s var(--ease),box-shadow .2s var(--ease)}
.cart-checkout:hover{transform:translateY(-1px);box-shadow:0 8px 22px rgba(224,138,26,0.4)}
.cart-empty{text-align:center;color:var(--text-muted);padding:54px 16px;line-height:1.6}
.cart-empty i{font-size:2.1rem;color:var(--gold-bright);opacity:0.6;display:block;margin-bottom:12px}

.toast{position:fixed;left:50%;bottom:92px;transform:translate(-50%,16px);z-index:1002;background:var(--bg-card);border:1px solid var(--border-gold);color:var(--text);padding:12px 18px;border-radius:100px;font-size:0.88rem;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,0.45);opacity:0;visibility:hidden;transition:all .25s var(--ease);display:inline-flex;align-items:center;gap:12px}
.toast.show{opacity:1;visibility:visible;transform:translate(-50%,0)}
.toast i{color:#34D399}
.toast button{background:none;border:0;color:var(--gold-bright);font-weight:800;cursor:pointer;font-size:0.86rem;white-space:nowrap}

.item-buy.added{background:#10B981;color:#fff}

@media (max-width:600px){
  .store-shell{padding:24px 16px 80px}
  .modal-head{padding:20px 22px 14px}
  .modal-body{padding:18px 22px}
  .modal-foot{padding:16px 22px 22px}
  .modal-foot .actions{flex-direction:column-reverse}
  .modal-foot .cancel{flex:1;padding:14px}
  .modal{max-height:calc(100vh - 24px);border-radius:18px}
  .item-name{font-size:1.05rem}
  .item-foot{padding:12px 16px}
  .item-body{padding:14px 16px 12px}
  .cart-fab{right:14px;bottom:14px}
}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1507838153414-b4b713384a76?w=1920&q=80');padding:84px 22px 44px">
    <div class="page-hero-inner">
      <nav class="breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span>Store</span></nav>
      <div class="page-hero-eyebrow"><i class="fa-solid fa-bag-shopping"></i>The MAM Library</div>
      <h1 class="page-hero-title">Store &amp; <span class="highlight-gold">Library</span></h1>
      <p class="page-hero-lede">Curated handouts, sheet music, recordings, learning bundles and physical merch from the MAM Centre. Built for our students &mdash; open to everyone.</p>
    </div>
  </header>

  <section class="store-shell" aria-label="Store catalogue">
    <div class="store-toolbar">
      <div class="st-search">
        <i class="fa-solid fa-magnifying-glass icn" aria-hidden="true"></i>
        <input type="search" id="storeSearch" placeholder="Search the library — sheet music, handouts, recordings…" aria-label="Search the store">
      </div>
      <div class="st-region"><i class="fa-solid fa-earth-africa"></i> Shipping to <b id="regionName">your region</b></div>
    </div>

    <div class="st-categories" id="catTabs" role="tablist" aria-label="Filter by category">
      <button class="active" data-cat="" type="button">All</button>
    </div>

    <div id="storeBody" aria-live="polite"></div>
  </section>
</main>

<!-- Floating cart button — hidden while the basket is empty -->
<button type="button" class="cart-fab" id="cartFab" hidden aria-label="Open cart">
  <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
  <span>Cart</span>
  <span class="cart-count" id="cartCount">0</span>
</button>

<!-- Cart drawer -->
<div class="cart-drawer" id="cartDrawer" aria-hidden="true">
  <div class="cd-backdrop" id="cartBackdrop"></div>
  <aside class="cart-panel" role="dialog" aria-modal="true" aria-labelledby="cartTitle">
    <div class="cart-panel-head">
      <h2 id="cartTitle">Your cart</h2>
      <button type="button" class="modal-close" id="cartClose" aria-label="Close cart"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="cart-panel-body" id="cartBody"></div>
    <div class="cart-panel-foot" id="cartFoot" hidden>
      <div class="sub"><span>Subtotal</span><span id="cartSubtotal">$0</span></div>
      <div class="sub" id="cartShipRow" hidden><span>Shipping (physical goods)</span><span id="cartShip">$0</span></div>
      <div class="sub grand"><span>Total</span><b id="cartTotal">$0</b></div>
      <button type="button" class="cart-checkout" id="cartCheckout">Checkout &rarr;</button>
    </div>
  </aside>
</div>

<!-- Add-to-cart toast -->
<div class="toast" id="toast" role="status" aria-live="polite">
  <i class="fa-solid fa-check" aria-hidden="true"></i><span id="toastMsg">Added to cart</span>
  <button type="button" id="toastView">View cart</button>
</div>

<!-- Checkout modal — starts hidden (CSS + attribute both enforce). Don't
     remove the [hidden] attribute below; the !important rule in CSS uses
     it as the single source of truth for the closed state. -->
<div class="modal-backdrop" id="checkoutModal" hidden aria-hidden="true">
  <div class="modal" role="dialog" aria-labelledby="checkoutTitle" aria-modal="true" tabindex="-1">

    <div class="modal-head">
      <div class="titles">
        <h2 id="checkoutTitle">Checkout</h2>
        <p class="sub" id="checkoutSub">Quick — almost done.</p>
      </div>
      <button type="button" class="modal-close" id="coClose" aria-label="Close checkout">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>

    <form id="checkoutForm" autocomplete="on" novalidate>
      <div class="modal-body">
        <div class="modal-grid">
          <div>
            <label class="req" for="coName">Full name</label>
            <input type="text" id="coName" name="name" required autocomplete="name" placeholder="Ada Bello">
          </div>
          <div>
            <label class="req" for="coEmail">Email</label>
            <input type="email" id="coEmail" name="email" required autocomplete="email" placeholder="you@example.com">
          </div>
        </div>
        <label for="coPhone">Phone <span style="color:var(--text-muted);text-transform:none;letter-spacing:0;font-weight:500">(optional)</span></label>
        <input type="tel" id="coPhone" name="phone" autocomplete="tel" placeholder="+234…">

        <div id="shipBlock" hidden>
          <label class="req" for="coShip">Shipping address</label>
          <textarea id="coShip" name="shipping_address" placeholder="Street, City, State, Country, Postcode"></textarea>
        </div>

        <div class="modal-summary" id="coSummary"></div>
      </div>

      <div class="modal-err" id="coErr" role="alert"></div>

      <div class="modal-foot">
        <div class="actions">
          <button type="button" class="cancel" id="coCancel">Back</button>
          <button type="submit" class="buy" id="coBuy"><i class="fa-solid fa-lock"></i> Secure payment</button>
        </div>
        <p class="trust"><i class="fa-solid fa-shield-halved"></i> Encrypted via Paystack &middot; we never see your card</p>
      </div>
    </form>

    <div class="modal-success" id="coSuccess" aria-live="polite">
      <div class="check"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
      <h3 id="coSuccessTitle">Order confirmed</h3>
      <p id="coSuccessMsg">Check your email — your receipt and any download links are on the way.</p>
      <div class="ref" id="coSuccessRef"></div>
      <button type="button" class="cancel" id="coDone" style="display:inline-block;padding:12px 28px;background:var(--bg-raised);border:1px solid var(--border);color:var(--text);border-radius:10px;font-weight:700;cursor:pointer;letter-spacing:0.02em">Browse more →</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260613b"></script>
<script src="assets/js/main.js?v=20260613b"></script>
<script>
(function(){
  'use strict';
  var GOODS = { digital:'Digital', physical:'Physical', service:'Service' };
  var $ = function(s){ return document.querySelector(s); };
  var $$ = function(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); };

  // ── State ──────────────────────────────────────────────────
  var allItems = [];          // unfiltered catalogue
  var activeCat = '';         // current category filter ('' = All)
  var activeSearch = '';      // current search term
  var lastFocus = null;       // for restoring focus when modal closes

  // ── Cart (localStorage-persisted) ──────────────────────────
  // Each line snapshots display fields so the drawer renders without a
  // refetch; at checkout we send only {sku, qty} and the server recomputes
  // the authoritative total. Lines are reconciled against the live catalogue
  // on load (drop vanished/sold-out items, refresh names/prices).
  var CART_KEY = 'mam.store.cart';
  var cart = loadCart();
  function loadCart(){ try { return JSON.parse(localStorage.getItem(CART_KEY)) || []; } catch(e){ return []; } }
  function saveCart(){ try { localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch(e){} }
  function cartCount(){ return cart.reduce(function(n,l){ return n + l.qty; }, 0); }
  function cartHasPhysical(){ return cart.some(function(l){ return l.goods_type === 'physical'; }); }
  function cartSubtotal(){ return cart.reduce(function(s,l){ return s + (Number(l.price_usd)||0) * l.qty; }, 0); }
  function shippingFlat(){ var c = window.MAM_CONFIG || {}; return (typeof c.storeShippingUsd === 'number') ? c.storeShippingUsd : 5; }
  function findInCart(sku){ for (var i=0;i<cart.length;i++) if (cart[i].sku === sku) return cart[i]; return null; }

  // ── Catalogue load ─────────────────────────────────────────
  function load() {
    paintSkeleton();
    fetch('/api/store/items', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function(r){
        return r.text().then(function(t){
          if (!r.ok) throw Object.assign(new Error('http_' + r.status), { status: r.status, body: t });
          try { return JSON.parse(t); } catch (e) { throw Object.assign(new Error('non_json'), { body: t }); }
        });
      })
      .then(function(j){
        if (!j || !j.ok) throw new Error('bad_response');
        $('#regionName').textContent = (j.country && j.country !== 'DEFAULT') ? j.country : 'your region';
        allItems = j.items || [];
        reconcileCart();
        paintCategories();
        render();
        updateCartUI();
      })
      .catch(function(err){
        try { console.error('[MAM_STORE] failed to load', err); } catch (e) {}
        $('#storeBody').innerHTML = ''
          + '<div class="store-empty">'
          +   '<div class="icn"><i class="fa-solid fa-circle-exclamation"></i></div>'
          +   '<h3>The library can\'t open right now</h3>'
          +   '<p>This usually means the server is being updated. Refresh in a moment.</p>'
          +   '<p style="font-size:0.84rem;color:var(--text-muted);margin-top:14px">Need it urgently? Email <a href="mailto:academy@mamcentre.org" style="color:var(--gold-bright)">academy@mamcentre.org</a>.</p>'
          + '</div>';
      });
  }

  // ── Skeleton loader (12 cards, shimmering) ─────────────────
  function paintSkeleton() {
    var cards = '';
    for (var i = 0; i < 8; i++) {
      cards += ''
        + '<div class="skel-card">'
        +   '<div class="skel skel-thumb"></div>'
        +   '<div class="skel-body">'
        +     '<div class="skel skel-line short"></div>'
        +     '<div class="skel skel-line tall"></div>'
        +     '<div class="skel skel-line"></div>'
        +   '</div>'
        +   '<div class="skel-foot"><div class="skel skel-line" style="width:60px"></div><div class="skel skel-line" style="width:80px"></div></div>'
        + '</div>';
    }
    $('#storeBody').innerHTML = '<div class="skeleton-grid">' + cards + '</div>';
  }

  // ── Categories — show ALL and a count next to each ─────────
  function paintCategories() {
    var tabs = $('#catTabs');
    var counts = { '': allItems.length };
    allItems.forEach(function(i){ if (i.category) counts[i.category] = (counts[i.category] || 0) + 1; });

    var html = '<button data-cat="" type="button" class="' + (activeCat === '' ? 'active' : '') + '">All <span class="count">' + counts[''] + '</span></button>';
    Object.keys(counts).filter(function(k){ return k !== ''; }).sort().forEach(function(c){
      html += '<button data-cat="' + escAttr(c) + '" type="button" class="' + (activeCat === c ? 'active' : '') + '">' + escapeHtml(c) + ' <span class="count">' + counts[c] + '</span></button>';
    });
    tabs.innerHTML = html;
  }
  // delegated click handler — survives re-renders
  $('#catTabs').addEventListener('click', function(e){
    var b = e.target.closest('button[data-cat]'); if (!b) return;
    activeCat = b.getAttribute('data-cat');
    paintCategories();
    render();
  });

  // ── Search (debounced) ─────────────────────────────────────
  var searchTimer = null;
  $('#storeSearch').addEventListener('input', function(e){
    var v = e.target.value.trim().toLowerCase();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function(){
      activeSearch = v;
      render();
    }, 140);
  });

  // ── Filter + render ────────────────────────────────────────
  function render() {
    var items = allItems;
    if (activeCat) items = items.filter(function(i){ return i.category === activeCat; });
    if (activeSearch) items = items.filter(function(i){
      var hay = (i.name + ' ' + (i.description || '') + ' ' + (i.category || '')).toLowerCase();
      return hay.indexOf(activeSearch) !== -1;
    });

    var body = $('#storeBody');
    if (!items.length) {
      var msg = activeSearch
        ? 'No items match &ldquo;' + escapeHtml(activeSearch) + '&rdquo;'
        : (activeCat ? 'No items in ' + escapeHtml(activeCat) + ' yet' : 'Nothing here yet');
      body.innerHTML = ''
        + '<div class="store-empty">'
        +   '<div class="icn"><i class="fa-solid fa-magnifying-glass"></i></div>'
        +   '<h3>' + msg + '</h3>'
        +   '<p>' + (activeSearch ? 'Try a different search, or clear it to see everything.' : 'We\'re still curating the library — check back soon.') + '</p>'
        + '</div>';
      return;
    }

    body.innerHTML = '<div class="store-grid"></div>';
    var grid = body.querySelector('.store-grid');
    var frag = document.createDocumentFragment();
    items.forEach(function(item){ frag.appendChild(buildCard(item)); });
    grid.appendChild(frag);
  }

  function buildCard(item) {
    var card = document.createElement('article');
    card.className = 'item-card' + (item.available_here ? '' : ' unavailable');

    var firstImg = (item.images && item.images.length) ? item.images[0] : '';
    var thumb = firstImg
      ? '<img src="' + escAttr(firstImg) + '" alt="' + escAttr(item.name || '') + '" loading="lazy" onerror="this.parentNode.innerHTML=\'<i class=&quot;fa-solid fa-music&quot;></i>\'">'
      : '<i class="fa-solid fa-music"></i>';
    var chip = item.goods_type ? '<span class="item-chip gd-' + escAttr(item.goods_type) + '">' + (GOODS[item.goods_type] || item.goods_type) + '</span>' : '';
    var ribbon = '';
    if (!item.available_here) ribbon = '<span class="item-ribbon not-here">Not in ' + ($('#regionName').textContent || 'your region') + '</span>';
    else if (item.availability === 'sold_out') ribbon = '<span class="item-ribbon avail-sold_out">Sold out</span>';
    else if (item.availability === 'preorder') ribbon = '<span class="item-ribbon avail-preorder">Pre-order</span>';

    var canBuy = item.available_here && item.availability !== 'sold_out';

    card.innerHTML = ''
      + '<div class="item-thumb">' + chip + ribbon + thumb + '</div>'
      + '<div class="item-body">'
      +   (item.category ? '<span class="item-cat">' + escapeHtml(item.category) + '</span>' : '')
      +   '<h3 class="item-name">' + escapeHtml(item.name) + '</h3>'
      +   (item.description ? '<p class="item-desc">' + escapeHtml(item.description) + '</p>' : '')
      + '</div>'
      + '<div class="item-foot">'
      +   '<span class="item-price">' + fmtPrice(item.price_usd) + '<small>USD</small></span>'
      +   '<button type="button" class="item-buy"' + (canBuy ? '' : ' disabled') + '>'
      +     (canBuy ? '<i class="fa-solid fa-cart-plus"></i> Add' : 'Unavailable')
      +   '</button>'
      + '</div>';

    if (canBuy) {
      var btn = card.querySelector('.item-buy');
      btn.addEventListener('click', function(){
        addToCart(item);
        // Tasteful confirmation: button flips to "Added" briefly, badge bumps.
        btn.classList.add('added');
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Added';
        setTimeout(function(){ btn.classList.remove('added'); btn.innerHTML = '<i class="fa-solid fa-cart-plus"></i> Add'; }, 1100);
      });
    }
    return card;
  }

  function fmtPrice(n){
    var v = Number(n) || 0;
    return '$' + (v % 1 === 0 ? v.toFixed(0) : v.toFixed(2));
  }
  function fmt(n){ return '$' + Number(n).toFixed(2); }
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function escAttr(s){ return escapeHtml(s).replace(/`/g, '&#96;'); }

  // ── Cart operations + UI ───────────────────────────────────
  function addToCart(item){
    var line = findInCart(item.sku);
    if (line) { line.qty += 1; }
    else cart.push({ sku:item.sku, name:item.name, price_usd:Number(item.price_usd)||0, goods_type:item.goods_type, image:(item.images && item.images[0]) || '' });
    saveCart(); updateCartUI(); bumpFab(); showToast('Added to cart');
  }
  function setQty(sku, qty){
    var line = findInCart(sku); if (!line) return;
    line.qty = Math.max(0, qty);
    if (line.qty === 0) cart = cart.filter(function(l){ return l.sku !== sku; });
    saveCart(); updateCartUI();
  }
  function removeFromCart(sku){ cart = cart.filter(function(l){ return l.sku !== sku; }); saveCart(); updateCartUI(); }
  function clearCart(){ cart = []; saveCart(); updateCartUI(); }

  // Drop lines that no longer exist / aren't sellable here; refresh names+prices.
  function reconcileCart(){
    if (!cart.length || !allItems.length) return;
    var bySku = {}; allItems.forEach(function(i){ bySku[i.sku] = i; });
    cart = cart.filter(function(l){
      var it = bySku[l.sku];
      if (!it || !it.available_here || it.availability === 'sold_out') return false;
      l.name = it.name; l.price_usd = Number(it.price_usd) || 0; l.goods_type = it.goods_type;
      l.image = (it.images && it.images[0]) || '';
      return true;
    });
    saveCart();
  }

  function bumpFab(){ var f = $('#cartFab'); if (!f) return; f.classList.remove('bump'); void f.offsetWidth; f.classList.add('bump'); }

  var toastTimer = null;
  function showToast(msg){
    var t = $('#toast'); if (!t) return;
    $('#toastMsg').textContent = msg; t.classList.add('show');
    clearTimeout(toastTimer); toastTimer = setTimeout(function(){ t.classList.remove('show'); }, 3200);
  }
  $('#toastView').addEventListener('click', function(){ $('#toast').classList.remove('show'); openCart(); });

  function updateCartUI(){
    var count = cartCount();
    $('#cartCount').textContent = count;
    $('#cartFab').hidden = (count === 0);
    var body = $('#cartBody'), foot = $('#cartFoot');
    if (!cart.length) {
      body.innerHTML = '<div class="cart-empty"><i class="fa-solid fa-bag-shopping"></i>Your cart is empty.<br>Browse the library and add something you love.</div>';
      foot.hidden = true; return;
    }
    foot.hidden = false;
    body.innerHTML = cart.map(function(l){
      var thumb = l.image ? '<img src="' + escAttr(l.image) + '" alt="">' : '<i class="fa-solid fa-music"></i>';
      return '<div class="cart-line" data-sku="' + escAttr(l.sku) + '">'
        + '<div class="cart-line-thumb">' + thumb + '</div>'
        + '<div class="cart-line-main">'
        +   '<p class="cart-line-name">' + escapeHtml(l.name) + '</p>'
        +   '<div class="cart-line-price">' + fmt(l.price_usd) + ' each · ' + fmt(l.price_usd * l.qty) + '</div>'
        +   '<div class="cart-line-foot">'
        +     '<div class="qty"><button type="button" data-act="dec" aria-label="Decrease quantity">&minus;</button><span>' + l.qty + '</span><button type="button" data-act="inc" aria-label="Increase quantity">+</button></div>'
        +     '<button type="button" class="cart-line-rm" data-act="rm"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Remove</button>'
        +   '</div>'
        + '</div></div>';
    }).join('');
    var sub = cartSubtotal();
    var ship = cartHasPhysical() ? shippingFlat() : 0;
    $('#cartSubtotal').textContent = fmt(sub);
    $('#cartShipRow').hidden = (ship === 0);
    $('#cartShip').textContent = fmt(ship);
    $('#cartTotal').textContent = fmt(sub + ship);
  }

  // Delegated qty / remove controls (survives re-render)
  $('#cartBody').addEventListener('click', function(e){
    var btn = e.target.closest('[data-act]'); if (!btn) return;
    var lineEl = btn.closest('.cart-line'); if (!lineEl) return;
    var sku = lineEl.getAttribute('data-sku');
    var line = findInCart(sku); if (!line) return;
    var act = btn.getAttribute('data-act');
    if (act === 'inc') setQty(sku, line.qty + 1);
    else if (act === 'dec') setQty(sku, line.qty - 1);
    else if (act === 'rm') removeFromCart(sku);
  });

  // Drawer open/close
  var drawer = $('#cartDrawer');
  function openCart(){ updateCartUI(); drawer.classList.add('is-open'); drawer.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
  function closeCart(){ drawer.classList.remove('is-open'); drawer.setAttribute('aria-hidden','true'); document.body.style.overflow=''; }
  $('#cartFab').addEventListener('click', openCart);
  $('#cartClose').addEventListener('click', closeCart);
  $('#cartBackdrop').addEventListener('click', closeCart);
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && drawer.classList.contains('is-open')) closeCart(); });

  // Checkout from the drawer → open the checkout modal (summarises the cart)
  $('#cartCheckout').addEventListener('click', function(){ if (!cart.length) return; closeCart(); openCheckout(); });

  // Live pricing/shipping can arrive after first paint — re-render everything.
  // Guard on a loaded catalogue so a config event can't flash an empty grid
  // before /api/store/items has resolved.
  document.addEventListener('mam:config', function(){ if (!allItems.length) { updateCartUI(); return; } reconcileCart(); render(); updateCartUI(); });

  // ── Checkout modal ─────────────────────────────────────────
  var modalEl = $('#checkoutModal');

  function openCheckout() {
    if (!cart.length) return;
    lastFocus = document.activeElement;

    // Reset state every open
    $('#checkoutForm').style.display = '';
    $('#coSuccess').classList.remove('show');
    $('#coErr').classList.remove('show');
    $('#coName').value = '';
    $('#coEmail').value = '';
    $('#coPhone').value = '';
    $('#coShip').value = '';

    var hasPhysical = cartHasPhysical();
    $('#checkoutTitle').textContent = 'Checkout';
    $('#checkoutSub').textContent = hasPhysical
      ? 'We\'ll ship physical items from Lagos within 2 business days.'
      : 'Digital items land in your inbox the moment payment clears.';

    // Summary: one row per cart line, then shipping (if any), then total.
    var sub = cartSubtotal();
    var shipping = hasPhysical ? shippingFlat() : 0;
    var rows = cart.map(function(l){
      return '<div class="row"><span>' + escapeHtml(l.name) + ' × ' + l.qty + '</span><span>' + fmt(l.price_usd * l.qty) + '</span></div>';
    }).join('');
    if (shipping > 0) rows += '<div class="row"><span>Shipping</span><span>' + fmt(shipping) + '</span></div>';
    rows += '<div class="row total"><span>Total</span><b>' + fmt(sub + shipping) + '</b></div>';
    $('#coSummary').innerHTML = rows;

    $('#shipBlock').hidden = !hasPhysical;
    $('#coShip').required = hasPhysical;
    var buy = $('#coBuy'); buy.disabled = false; buy.innerHTML = '<i class="fa-solid fa-lock"></i> Pay ' + fmt(sub + shipping);

    // Open (use BOTH the attribute toggle AND the class toggle so we're
    // bulletproof against the [hidden]/display:flex specificity gotcha)
    modalEl.removeAttribute('hidden');
    modalEl.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(function(){ modalEl.classList.add('is-open'); });
    document.body.style.overflow = 'hidden';
    setTimeout(function(){ $('#coName').focus(); }, 160);
  }

  function closeCheckout() {
    modalEl.classList.remove('is-open');
    modalEl.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    // Wait for the transition before re-applying the hidden attribute,
    // otherwise the fade-out cuts hard.
    setTimeout(function(){ modalEl.setAttribute('hidden', ''); }, 220);
    if (lastFocus && lastFocus.focus) try { lastFocus.focus(); } catch(e){}
  }

  $('#coClose').addEventListener('click', closeCheckout);
  $('#coCancel').addEventListener('click', closeCheckout);
  $('#coDone').addEventListener('click', closeCheckout);
  // click outside the .modal closes
  modalEl.addEventListener('click', function(e){ if (e.target === modalEl) closeCheckout(); });
  // Escape closes (only when open)
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && modalEl.classList.contains('is-open')) closeCheckout();
  });

  // ── Submit (CSRF-protected via window.MAM_API) ─────────────
  $('#checkoutForm').addEventListener('submit', function(e){
    e.preventDefault();
    var btn = $('#coBuy'); var err = $('#coErr'); err.classList.remove('show');
    if (!cart.length) { err.textContent = 'Your cart is empty.'; err.classList.add('show'); return; }
    var payload = {
      items: cart.map(function(l){ return { sku: l.sku, qty: l.qty }; }),
      name:  $('#coName').value.trim(),
      email: $('#coEmail').value.trim(),
      phone: $('#coPhone').value.trim(),
      shipping_address: $('#coShip').value.trim()
    };
    if (!payload.name || !payload.email) {
      err.textContent = 'Please add your name and email.'; err.classList.add('show'); return;
    }
    if (cartHasPhysical() && !payload.shipping_address) {
      err.textContent = 'A shipping address is required for physical items.'; err.classList.add('show'); return;
    }

    btn.disabled = true; btn.innerHTML = '<span class="spinner-sm"></span> Creating order…';

    if (!window.MAM_API) {
      err.textContent = 'API unavailable — please refresh and try again.'; err.classList.add('show');
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-lock"></i> Secure payment'; return;
    }

    window.MAM_API.storeCheckout(payload)
      .then(function(order){
        return window.MAM_API.initializePayment({ reference: order.reference, provider: 'paystack', purpose: 'store' })
          .then(function(init){ openPaystack(init, order.reference); });
      })
      .catch(function(e){
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-lock"></i> Secure payment';
        err.textContent = (e && e.body && (e.body.message || e.body.error)) || 'Could not start payment. Please try again.';
        err.classList.add('show');
      });
  });

  // ── Paystack inline checkout ───────────────────────────────
  function loadScript(src){
    return new Promise(function(res, rej){
      if (document.querySelector('script[src="'+src+'"]')) return res();
      var s = document.createElement('script'); s.src = src; s.async = true;
      s.onload = res; s.onerror = function(){ rej(new Error('script')); };
      document.head.appendChild(s);
    });
  }
  function openPaystack(init, orderRef){
    return loadScript('https://js.paystack.co/v1/inline.js').then(function(){
      var btn = $('#coBuy'); btn.innerHTML = '<i class="fa-solid fa-lock"></i> Secure payment'; btn.disabled = false;
      var handler = window.PaystackPop.setup({
        key: init.public_key,
        email: $('#coEmail').value.trim(),
        amount: init.amount_ngn * 100,
        currency: 'NGN',
        ref: init.reference,
        metadata: { reference: init.reference, purpose: 'store' },
        callback: function(response){
          $('#coBuy').innerHTML = '<span class="spinner-sm"></span> Verifying…';
          $('#coBuy').disabled = true;
          window.MAM_API.verifyPayment({ provider: 'paystack', reference: response.reference || init.reference })
            .then(function(){ showSuccess(orderRef); })
            .catch(function(){
              $('#coErr').textContent = 'Payment received but verification is still pending — please check your email in a minute.';
              $('#coErr').classList.add('show');
              $('#coBuy').innerHTML = '<i class="fa-solid fa-lock"></i> Secure payment';
              $('#coBuy').disabled = false;
            });
        },
        onClose: function(){}
      });
      handler.openIframe();
    });
  }

  function showSuccess(ref) {
    var wasDigitalOnly = !cartHasPhysical();
    clearCart();
    $('#checkoutForm').style.display = 'none';
    $('#coSuccess').classList.add('show');
    $('#coSuccessRef').textContent = 'Order ' + ref;
    $('#coSuccessMsg').textContent = wasDigitalOnly
      ? 'Your download links are on the way — check your inbox in the next minute.'
      : 'Check your email for your receipt. We\'ll ship your items from Lagos within 2 business days.';
  }

  load();
})();
</script>
</body>
</html>
