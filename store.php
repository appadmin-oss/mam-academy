<?php
/**
 * Store & Library — on the 2026-11 redesign shell (partials/site/). The handoff did
 * not draw it; it is built from the handoff's own parts (the About page's breadcrumb
 * and serif H1, the Programmes cards, the Apply flow's fields).
 *
 * Behaviour is the previous page's, unchanged: the catalogue from GET /api/store/items,
 * a localStorage cart ("mam.store.cart") that sends only {sku, qty} — the server
 * recomputes every price at checkout — and the drawer hands the buyer to /checkout.
 * config.js is loaded for MAM_CCY (prices in the visitor's currency, at the server's
 * rates) and MAM_CONFIG.storeShippingUsd; because MAM_CCY then exists, site.js leaves
 * the footer's currency switch alone and this page wires it to the same key.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$stCrumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://academy.mamcentre.org/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Store', 'item' => 'https://academy.mamcentre.org/store'],
]];
site_head([
    'title' => 'Store & Library — MAM Academy',
    'description' => 'The MAM Academy Store — sheet music, handouts, fakebooks, recordings and learning bundles, plus instructor add-ons and physical merch. Region-aware delivery.',
    'path' => '/store',
    'jsonld' => '<script type="application/ld+json">' . json_encode($stCrumbs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>',
]);
site_header('inner');
ob_start();
?>
<style>
.store-shell{max-width:1200px;margin:0 auto;padding:0 clamp(20px,4vw,40px) clamp(64px,8vw,120px);box-sizing:border-box}
.store-toolbar{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center;justify-content:space-between;margin:0 0 20px}
.st-search{position:relative;flex:1 1 360px;max-width:640px}
.st-search svg{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#574B40;pointer-events:none}
.st-search input{box-sizing:border-box;width:100%;min-height:52px;padding:12px 16px 12px 48px;border-radius:12px;border:1px solid #CFC3B3;background:#FFFFFF;font:inherit;font-size:16px;color:#211A13}
.st-search input:focus{outline:2px solid #8A5208;outline-offset:2px;border-color:#8A5208}
.st-region{font-size:15px;color:#574B40}
.st-region b{color:#211A13;font-weight:600}
.st-categories{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 32px}
.st-categories button{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:0 16px;border-radius:999px;border:1px solid #CFC3B3;background:#FFFFFF;color:#211A13;font:inherit;font-size:15px;cursor:pointer}
.st-categories button.active{background:#211A13;border-color:#211A13;color:#FAF8F4}
.st-categories .st-count{font-size:13px;color:#574B40}
.st-categories button.active .st-count{color:#D8CEC1}
.store-grid,.skeleton-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(260px,100%),1fr));gap:16px}
.item-card{display:flex;flex-direction:column;border-radius:16px;background:#FFFFFF;border:1px solid #E6DED2;overflow:hidden;transition:transform .75s var(--out),box-shadow .75s var(--out)}
.item-card:hover{transform:translateY(-4px);box-shadow:0 30px 60px -36px rgba(33,26,19,.4)}
.item-card.unavailable{opacity:.7}
.item-thumb{position:relative;aspect-ratio:4/3;background:linear-gradient(135deg,#E8DECF,#CDB89C);display:flex;align-items:center;justify-content:center;color:#574B40}
.item-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.item-chip{position:absolute;left:12px;top:12px;padding:4px 10px;border-radius:999px;background:rgba(250,248,244,.92);font-size:13px;font-weight:600;color:#211A13}
.item-ribbon{position:absolute;right:12px;top:12px;padding:4px 10px;border-radius:999px;background:#211A13;color:#FCF8F3;font-size:13px;font-weight:600}
.item-ribbon.avail-preorder{background:#F2A93B;color:#211A13}
.item-body{flex:1;display:flex;flex-direction:column;gap:6px;padding:20px 20px 12px}
.item-cat{font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#8A5208}
.item-name{margin:0;font-family:'Cormorant Garamond',Georgia,serif;font-weight:600;font-size:24px;line-height:1.15;color:#211A13}
.item-desc{margin:0;font-size:15px;line-height:1.6;color:#3E342B;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.item-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px 20px}
.item-price{font-size:19px;font-weight:600;color:#211A13;font-variant-numeric:tabular-nums}
.item-price small{margin-left:6px;font-size:13px;font-weight:400;color:#574B40}
.item-buy{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 18px;border-radius:999px;border:0;background:#F2A93B;color:#211A13;font:inherit;font-size:15px;font-weight:600;cursor:pointer}
.item-buy:disabled{background:#ECE5DA;color:#574B40;cursor:default}
.item-buy.added{background:#211A13;color:#FAF8F4}
.store-empty{text-align:center;padding:64px 20px;color:#3E342B}
.store-empty .icn{color:#C27A12;margin-bottom:12px}
.store-empty h3{margin:0 0 8px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:32px;color:#211A13}
.store-empty p{margin:0;font-size:16px}
.store-empty .store-empty-note{margin-top:14px;font-size:14px;color:#574B40}
.store-empty a{color:#8A5208}
.ic-lg{width:40px;height:40px}
.skel-card{border-radius:16px;background:#FFFFFF;border:1px solid #E6DED2;overflow:hidden}
.skel{background:linear-gradient(90deg,#F3EEE6,#ECE5DA,#F3EEE6);background-size:200% 100%;animation:skel 1.4s linear infinite;border-radius:8px}
.skel-thumb{aspect-ratio:4/3;border-radius:0}
.skel-body{padding:20px;display:flex;flex-direction:column;gap:10px}
.skel-line{height:12px}.skel-line.short{width:40%}.skel-line.tall{height:22px;width:80%}
.skel-foot{display:flex;justify-content:space-between;padding:0 20px 20px}
@keyframes skel{to{background-position:-200% 0}}
@media (prefers-reduced-motion:reduce){.skel{animation:none}.item-card{transition:none}}
.cart-fab{position:fixed;right:16px;bottom:16px;z-index:1050;display:inline-flex;align-items:center;gap:10px;min-height:52px;padding:0 20px;border-radius:999px;border:0;background:#211A13;color:#FCF8F3;font:inherit;font-size:16px;font-weight:600;cursor:pointer;box-shadow:0 18px 40px -20px rgba(33,26,19,.6)}
.cart-fab[hidden]{display:none}
.cart-fab.bump{animation:bump .45s var(--out)}
@keyframes bump{40%{transform:scale(1.08)}}
.cart-count{min-width:24px;height:24px;padding:0 6px;box-sizing:border-box;border-radius:999px;background:#F2A93B;color:#211A13;font-size:14px;display:inline-flex;align-items:center;justify-content:center}
html.ck-on .cart-fab{bottom:calc(var(--ck-h,0px) + 12px)}
.cart-drawer{position:fixed;inset:0;z-index:1150;visibility:hidden;pointer-events:none}
.cart-drawer.is-open{visibility:visible;pointer-events:auto}
.cd-backdrop{position:absolute;inset:0;background:rgba(18,12,7,.45);opacity:0;transition:opacity .4s ease}
.cart-drawer.is-open .cd-backdrop{opacity:1}
.cart-panel{position:absolute;top:0;right:0;bottom:0;width:min(440px,100%);display:flex;flex-direction:column;background:#FAF8F4;color:#211A13;box-shadow:-30px 0 60px -36px rgba(33,26,19,.4);transform:translateX(100%);transition:transform .6s var(--out)}
.cart-drawer.is-open .cart-panel{transform:none}
.cart-panel-head{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid #E6DED2}
.cart-panel-head h2{margin:0;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:32px}
.modal-close{width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;background:none;color:#211A13;cursor:pointer}
.modal-close:hover{background:#F3EEE6}
.cart-panel-body{flex:1;overflow-y:auto;padding:8px 24px}
.cart-empty{text-align:center;padding:48px 8px;color:#574B40;font-size:16px;line-height:1.6}
.cart-empty svg{display:block;margin:0 auto 12px;color:#C27A12}
.cart-line{display:flex;gap:14px;padding:16px 0;border-bottom:1px solid #E6DED2}
.cart-line-thumb{flex:none;width:64px;height:64px;border-radius:12px;overflow:hidden;background:linear-gradient(135deg,#E8DECF,#CDB89C);display:flex;align-items:center;justify-content:center;color:#574B40}
.cart-line-thumb img{width:100%;height:100%;object-fit:cover}
.cart-line-thumb .ic-lg{width:24px;height:24px}
.cart-line-main{flex:1;min-width:0}
.cart-line-name{margin:0 0 4px;font-weight:600;font-size:16px;line-height:1.3}
.cart-line-price{font-size:14px;color:#574B40;font-variant-numeric:tabular-nums}
.cart-line-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:10px}
.qty{display:inline-flex;align-items:center;border:1px solid #CFC3B3;border-radius:999px;background:#FFFFFF}
.qty button{width:40px;height:40px;border:0;background:none;font:inherit;font-size:18px;color:#211A13;cursor:pointer;border-radius:999px}
.qty span{min-width:24px;text-align:center;font-size:15px;font-weight:600}
.cart-line-rm{min-height:40px;border:0;background:none;font:inherit;font-size:14px;color:#8A5208;cursor:pointer;text-decoration:underline;text-underline-offset:3px}
.cart-panel-foot{padding:20px 24px calc(20px + env(safe-area-inset-bottom,0px));border-top:1px solid #E6DED2;display:flex;flex-direction:column;gap:8px}
.cart-panel-foot .sub{display:flex;justify-content:space-between;font-size:15px;color:#3E342B;font-variant-numeric:tabular-nums}
.cart-panel-foot .sub.grand{padding-top:8px;font-size:18px;color:#211A13}
.cart-checkout{margin-top:8px;min-height:52px;border:0;border-radius:999px;background:#F2A93B;color:#211A13;font:inherit;font-size:16px;font-weight:600;cursor:pointer}
.toast{position:fixed;left:50%;bottom:24px;z-index:1100;display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:16px;background:#211A13;color:#FCF8F3;font-size:15px;box-shadow:0 18px 40px -20px rgba(33,26,19,.6);transform:translate(-50%,24px);opacity:0;pointer-events:none;transition:opacity .3s ease,transform .45s var(--out)}
.toast.show{opacity:1;transform:translate(-50%,0);pointer-events:auto}
.toast button{min-height:40px;padding:0 14px;border:0;border-radius:999px;background:#F2A93B;color:#211A13;font:inherit;font-size:14px;font-weight:600;cursor:pointer}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
</style>
<main id="main">
<?php site_doc_open('Store', 'Store & Library', 'Curated handouts, sheet music, recordings, learning bundles and physical merch from MAM Centre. Built for our students — open to everyone.'); ?>
  <section class="store-shell" aria-label="Store catalogue">
    <div class="store-toolbar">
      <div class="st-search">
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" id="storeSearch" placeholder="Search sheet music, handouts, recordings…" aria-label="Search the store">
      </div>
      <div class="st-region">Shipping to <b id="regionName">your region</b></div>
    </div>

    <h2 class="sr-only">Browse the store</h2>
    <div class="st-categories" id="catTabs" role="group" aria-label="Filter by category">
      <button class="active" data-cat="" type="button">All</button>
    </div>

    <div id="storeBody" aria-live="polite"></div>
  </section>
</main>

<!-- Floating cart button — hidden while the basket is empty -->
<button type="button" class="cart-fab" id="cartFab" hidden aria-label="Open cart">
  <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
  <span>Cart</span>
  <span class="cart-count" id="cartCount">0</span>
</button>

<!-- Cart drawer -->
<div class="cart-drawer" id="cartDrawer" aria-hidden="true">
  <div class="cd-backdrop" id="cartBackdrop"></div>
  <aside class="cart-panel" role="dialog" aria-modal="true" aria-labelledby="cartTitle">
    <div class="cart-panel-head">
      <h2 id="cartTitle">Your cart</h2>
      <button type="button" class="modal-close" id="cartClose" aria-label="Close cart"><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="cart-panel-body" id="cartBody"></div>
    <div class="cart-panel-foot" id="cartFoot" hidden>
      <div class="sub"><span>Subtotal</span><span id="cartSubtotal">—</span></div>
      <div class="sub" id="cartShipRow" hidden><span>Shipping (physical goods)</span><span id="cartShip">—</span></div>
      <div class="sub grand"><span>Total</span><b id="cartTotal">—</b></div>
      <button type="button" class="cart-checkout" id="cartCheckout">Checkout &rarr;</button>
    </div>
  </aside>
</div>

<!-- Add-to-cart toast -->
<div class="toast" id="toast" role="status" aria-live="polite">
  <span id="toastMsg">Added to cart</span>
  <button type="button" id="toastView">View cart</button>
</div>


<?php
echo (string) ob_get_clean();
// config.js before site.js (deferred): MAM_CCY and MAM_CONFIG are the store's formatter and settings.
echo '<script src="/assets/js/config.js?v=20261121"></script>' . "\n";
site_footer_chrome();
?>
<script>
(function(){
  'use strict';
  var GOODS = { digital:'Digital', physical:'Physical', service:'Service' };
  // Icons as inline SVG (the redesign carries no icon font).
  var SV = function (d, extra) { return '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"' + (extra || '') + '>' + d + '</svg>'; };
  var ICON = {
    music:  SV('<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>', ' class="ic-lg"'),
    plus:   SV('<path d="M12 5v14M5 12h14"/>'),
    check:  SV('<path d="M20 6 9 17l-5-5"/>'),
    bag:    SV('<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>', ' class="ic-lg"'),
    alert:  SV('<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>', ' class="ic-lg"'),
    search: SV('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>', ' class="ic-lg"')
  };

  var $ = function(s){ return document.querySelector(s); };
  var $$ = function(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); };

  // ── State ──────────────────────────────────────────────────
  var allItems = [];          // unfiltered catalogue
  var activeCat = '';         // current category filter ('' = All)
  var activeSearch = '';      // current search term

  // ── Cart (localStorage-persisted) ──────────────────────────
  // Each line snapshots display fields so the drawer renders without a
  // refetch; at checkout we send only {sku, qty} and the server recomputes
  // the authoritative total. Lines are reconciled against the live catalogue
  // on load (drop vanished/sold-out items, refresh names/prices).
  var CART_KEY = 'mam.store.cart';
  var cart = loadCart();
  // A line always carries a whole quantity of at least 1. New lines were pushed with
  // none (so "+1" made NaN and the cart read "NaN"); a saved cart is repaired on load.
  function loadCart(){
    var c; try { c = JSON.parse(localStorage.getItem(CART_KEY)) || []; } catch(e){ c = []; }
    return (Array.isArray(c) ? c : []).filter(function(l){ return l && l.sku; }).map(function(l){ var q = parseInt(l.qty, 10); l.qty = q > 0 ? q : 1; return l; });
  }
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
          +   '<div class="icn">' + ICON.alert + '</div>'
          +   '<h3>The library can\'t open right now</h3>'
          +   '<p>This usually means the server is being updated. Refresh in a moment.</p>'
          +   '<p class="store-empty-note">Need it urgently? Email <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a>.</p>'
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

    var html = '<button data-cat="" type="button" aria-pressed="' + (activeCat === '' ? 'true' : 'false') + '" class="' + (activeCat === '' ? 'active' : '') + '">All <span class="st-count">' + counts[''] + '</span></button>';
    Object.keys(counts).filter(function(k){ return k !== ''; }).sort().forEach(function(c){
      html += '<button data-cat="' + escAttr(c) + '" type="button" aria-pressed="' + (activeCat === c ? 'true' : 'false') + '" class="' + (activeCat === c ? 'active' : '') + '">' + escapeHtml(c) + ' <span class="st-count">' + counts[c] + '</span></button>';
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
        +   '<div class="icn">' + ICON.search + '</div>'
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
      ? '<img src="' + escAttr(firstImg) + '" alt="' + escAttr(item.name || '') + '" loading="lazy" onerror="this.parentNode.classList.add(\'no-img\');this.remove()">'
      : ICON.music;
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
      +   '<span class="item-price">' + fmtPrice(item.price_usd) + '<small>' + curCode() + '</small></span>'
      +   '<button type="button" class="item-buy"' + (canBuy ? '' : ' disabled') + '>'
      +     (canBuy ? '' + ICON.plus + ' Add' : 'Unavailable')
      +   '</button>'
      + '</div>';

    if (canBuy) {
      var btn = card.querySelector('.item-buy');
      btn.addEventListener('click', function(){
        addToCart(item);
        // Tasteful confirmation: button flips to "Added" briefly, badge bumps.
        btn.classList.add('added');
        btn.innerHTML = '' + ICON.check + ' Added';
        setTimeout(function(){ btn.classList.remove('added'); btn.innerHTML = '' + ICON.plus + ' Add'; }, 1100);
      });
    }
    return card;
  }

  function curCode(){ return (window.MAM_CCY && MAM_CCY.detect()) || 'USD'; }
  function fmtPrice(n){
    var v = Number(n) || 0;
    if (window.MAM_CCY) return MAM_CCY.format(v, curCode());
    return '$' + (v % 1 === 0 ? v.toFixed(0) : v.toFixed(2));
  }
  function fmt(n){
    if (window.MAM_CCY) return MAM_CCY.format(Number(n) || 0, curCode());
    return '$' + Number(n).toFixed(2);
  }
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function escAttr(s){ return escapeHtml(s).replace(/`/g, '&#96;'); }

  // ── Cart operations + UI ───────────────────────────────────
  function addToCart(item){
    var line = findInCart(item.sku);
    if (line) { line.qty += 1; }
    else cart.push({ sku:item.sku, name:item.name, price_usd:Number(item.price_usd)||0, goods_type:item.goods_type, image:(item.images && item.images[0]) || '', qty:1 });
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
      body.innerHTML = '<div class="cart-empty">' + ICON.bag + 'Your cart is empty.<br>Browse the library and add something you love.</div>';
      foot.hidden = true; return;
    }
    foot.hidden = false;
    body.innerHTML = cart.map(function(l){
      var thumb = l.image ? '<img src="' + escAttr(l.image) + '" alt="">' : ICON.music;
      return '<div class="cart-line" data-sku="' + escAttr(l.sku) + '">'
        + '<div class="cart-line-thumb">' + thumb + '</div>'
        + '<div class="cart-line-main">'
        +   '<p class="cart-line-name">' + escapeHtml(l.name) + '</p>'
        +   '<div class="cart-line-price">' + fmt(l.price_usd) + ' each · ' + fmt(l.price_usd * l.qty) + '</div>'
        +   '<div class="cart-line-foot">'
        +     '<div class="qty"><button type="button" data-act="dec" aria-label="Decrease quantity">&minus;</button><span>' + l.qty + '</span><button type="button" data-act="inc" aria-label="Increase quantity">+</button></div>'
        +     '<button type="button" class="cart-line-rm" data-act="rm">Remove</button>'
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

  // Checkout from the drawer → the dedicated checkout page (/checkout), which
  // reads the same localStorage cart. The in-page checkout dialog that used to
  // sit below was unreachable — nothing opened it — and has been removed.
  $('#cartCheckout').addEventListener('click', function(){ if (!cart.length) return; window.location.href = '/checkout'; });

  // Live pricing/shipping can arrive after first paint — re-render everything.
  // Guard on a loaded catalogue so a config event can't flash an empty grid
  // before /api/store/items has resolved.
  document.addEventListener('mam:config', function(){ if (!allItems.length) { updateCartUI(); return; } reconcileCart(); render(); updateCartUI(); });

  // The footer's currency switch. config.js (loaded for MAM_CCY, the formatter
  // and rates the old store used) means site.js leaves the switch alone, so the
  // page wires it to the same key ("mam.currency") and re-prices on change.
  var ccyBtns = $$('.cur-sw [data-currency]');
  function paintCcy(){ var c = curCode(); ccyBtns.forEach(function(b){ b.setAttribute('aria-pressed', b.getAttribute('data-currency') === c ? 'true' : 'false'); }); }
  ccyBtns.forEach(function(b){
    if (window.MAM_CCY && !MAM_CCY.known(b.getAttribute('data-currency'))) { b.hidden = true; return; }
    b.addEventListener('click', function(){
      try { localStorage.setItem('mam.currency', b.getAttribute('data-currency')); } catch (e) {}
      paintCcy();
      try { document.dispatchEvent(new CustomEvent('mam:currency', { detail: { currency: b.getAttribute('data-currency') } })); } catch (e) {}
    });
  });
  paintCcy();
  document.addEventListener('mam:currency', function(){ paintCcy(); if (allItems.length) render(); updateCartUI(); });

  load();
})();
</script>
</body>
</html>
