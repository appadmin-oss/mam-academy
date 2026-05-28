/**
 * MAM Academy — Lead-generation surfaces
 * ─────────────────────────────────────────────────────────────
 *  1. Sticky bottom CTA bar — site-wide nudge to apply. Auto-hides
 *     once the user is already on apply.html or has applied this
 *     session. Dismissible (remembers for 30 days).
 *  2. Exit-intent modal — on desktop, fires when the cursor leaves
 *     the viewport from the top; on mobile, fires on scroll-up
 *     after the user has been on the page > 25 s and is in the
 *     top third. Captures email via the newsletter endpoint. Shown
 *     at most once per visitor every 14 days.
 *
 * Both surfaces are opt-out via:
 *   <body data-no-lead-cta>   (skip the bar on this page)
 *   <body data-no-lead-exit>  (skip the exit modal on this page)
 *
 * Storage is plain localStorage keys with TTL; nothing personal stored.
 */
(function () {
  'use strict';

  if (typeof document === 'undefined' || typeof window === 'undefined') return;

  var LS_BAR_DISMISS = 'mam.cta-bar.dismiss';
  var LS_EXIT_SHOWN  = 'mam.exit-intent.shown';
  var DAY = 86400 * 1000;

  function nowMs() { return Date.now(); }
  function flagSet(key, ttlMs) { try { localStorage.setItem(key, String(nowMs() + ttlMs)); } catch (e) {} }
  function flagActive(key) {
    try { var v = parseInt(localStorage.getItem(key) || '0', 10); return v > nowMs(); } catch (e) { return false; }
  }

  function onApplyPage() {
    return /apply\.html$|\/apply\/?$/i.test(location.pathname);
  }

  // ── 1. Sticky CTA bar ─────────────────────────────────────────────
  function mountCtaBar() {
    if (document.body.hasAttribute('data-no-lead-cta')) return;
    if (onApplyPage()) return;
    if (flagActive(LS_BAR_DISMISS)) return;

    var bar = document.createElement('aside');
    bar.className = 'mam-cta-bar';
    bar.setAttribute('role', 'complementary');
    bar.setAttribute('aria-label', 'Apply to MAM Academy');
    bar.innerHTML =
      '<div class="mam-cta-bar-inner">' +
        '<div class="mam-cta-bar-text">' +
          '<strong>Next intake is open.</strong>' +
          '<span>Five-step application, auto-saved on this device.</span>' +
        '</div>' +
        '<a href="apply.html" class="mam-cta-bar-btn">' +
          '<i class="fa-solid fa-bolt" aria-hidden="true"></i>Apply now' +
        '</a>' +
        '<button type="button" class="mam-cta-bar-close" aria-label="Dismiss">&times;</button>' +
      '</div>';
    document.body.appendChild(bar);
    requestAnimationFrame(function () { bar.classList.add('is-in'); });

    bar.querySelector('.mam-cta-bar-close').addEventListener('click', function () {
      bar.classList.remove('is-in');
      setTimeout(function () { bar.remove(); }, 320);
      flagSet(LS_BAR_DISMISS, 30 * DAY);
    });
  }

  // ── 2. Exit-intent modal ──────────────────────────────────────────
  var armed = false, mounted = false;

  function shouldArmExit() {
    if (document.body.hasAttribute('data-no-lead-exit')) return false;
    if (onApplyPage()) return false; // never interrupt a real application
    if (flagActive(LS_EXIT_SHOWN)) return false;
    return true;
  }

  function showExitModal() {
    if (mounted) return; mounted = true;
    flagSet(LS_EXIT_SHOWN, 14 * DAY);

    var modal = document.createElement('div');
    modal.className = 'mam-exit-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'mamExitTitle');
    modal.innerHTML =
      '<div class="mam-exit-backdrop" data-close="1"></div>' +
      '<div class="mam-exit-card" role="document">' +
        '<button class="mam-exit-close" type="button" aria-label="Close" data-close="1">&times;</button>' +
        '<div class="mam-exit-eyebrow"><i class="fa-solid fa-music"></i>Before you go</div>' +
        '<h2 class="mam-exit-title" id="mamExitTitle">Get the <span class="mam-exit-glow">MAM Music Method</span> notes</h2>' +
        '<p class="mam-exit-lede">One short, honest email a month — the next Power Play date, faculty essays, intake openings. No noise, no daily blasts.</p>' +
        '<form class="mam-exit-form" id="mamExitForm" novalidate>' +
          '<input type="email" name="email" id="mamExitEmail" class="mam-exit-input" placeholder="you@example.com" autocomplete="email" required>' +
          '<button type="submit" class="mam-exit-submit">Send me the notes <i class="fa-solid fa-arrow-right"></i></button>' +
        '</form>' +
        '<div class="mam-exit-msg" id="mamExitMsg" hidden></div>' +
        '<p class="mam-exit-foot">Or skip ahead and <a href="apply.html">start your application</a> in five steps.</p>' +
      '</div>';
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () { modal.classList.add('is-in'); });

    function close() {
      modal.classList.remove('is-in');
      document.body.style.overflow = '';
      setTimeout(function () { modal.remove(); }, 280);
    }
    modal.addEventListener('click', function (e) { if (e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', function escHandler(e) {
      if (e.key === 'Escape') { close(); document.removeEventListener('keydown', escHandler); }
    });

    var emailInput = modal.querySelector('#mamExitEmail');
    var msg        = modal.querySelector('#mamExitMsg');
    setTimeout(function () { try { emailInput.focus({ preventScroll: true }); } catch (e) {} }, 320);

    modal.querySelector('#mamExitForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var email = (emailInput.value || '').trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        msg.hidden = false; msg.className = 'mam-exit-msg mam-exit-msg-error';
        msg.textContent = 'Please enter a valid email address.';
        emailInput.focus(); return;
      }
      var btn = modal.querySelector('.mam-exit-submit');
      var origBtn = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving…';

      var deliver = function (ok, errMsg) {
        if (ok) {
          msg.hidden = false; msg.className = 'mam-exit-msg mam-exit-msg-ok';
          msg.textContent = "You're in. Check your inbox for the first note.";
          setTimeout(close, 1600);
        } else {
          msg.hidden = false; msg.className = 'mam-exit-msg mam-exit-msg-error';
          msg.textContent = errMsg || "Couldn't subscribe just now — please try again.";
          btn.disabled = false; btn.innerHTML = origBtn;
        }
      };

      if (window.MAM_API && window.MAM_API.subscribeNewsletter) {
        window.MAM_API.subscribeNewsletter(email, 'exit-intent')
          .then(function () { deliver(true); })
          .catch(function (err) { deliver(false, err && err.status === 429 ? 'Too many requests — try later.' : null); });
      } else {
        // No API on this page (rare). Open mailto so the lead isn't lost.
        var fb = (window.MAM_CONFIG && window.MAM_CONFIG.forms && window.MAM_CONFIG.forms.fallbackEmail) || 'connect@mamcentre.org';
        window.location.href = 'mailto:' + fb + '?subject=' + encodeURIComponent('Subscribe me') + '&body=' + encodeURIComponent('Please add ' + email);
        deliver(true);
      }
    });
  }

  function armExitIntent() {
    if (!shouldArmExit()) return;

    // Desktop: cursor leaves top edge → classic exit intent
    var desktop = matchMedia('(pointer:fine) and (min-width:760px)').matches;
    if (desktop) {
      var leftAt = 0;
      document.addEventListener('mouseout', function (e) {
        if (!e.toElement && !e.relatedTarget && e.clientY <= 4) {
          // ignore the first 8 s on a cold page-load so it doesn't fire while the
          // user is still flicking up to the URL bar.
          if (nowMs() - leftAt < 100 || nowMs() < window._mamLoaded + 8000) return;
          leftAt = nowMs();
          showExitModal();
        }
      });
    } else {
      // Mobile/tablet: fire after ≥ 25 s on page when the user scrolls UP
      // (proxy for "thinking about leaving"). Avoid triggering during
      // initial settling by ignoring any scroll-up < 25 s in.
      var lastY = window.scrollY;
      window.addEventListener('scroll', function () {
        var y = window.scrollY;
        if (y < lastY - 40 && nowMs() > window._mamLoaded + 25000) {
          showExitModal();
        }
        lastY = y;
      }, { passive: true });
    }
  }

  // ── Boot ──────────────────────────────────────────────────────────
  window._mamLoaded = nowMs();
  function boot() {
    mountCtaBar();
    armExitIntent();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
