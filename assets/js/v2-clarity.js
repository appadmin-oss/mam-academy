/* =============================================================================
   MAM v2 — the clarity kit (browser half of Layer H)
   =============================================================================
   `MAM_CLARITY` renders the four things every surface needs and none of them
   should reinvent: a status chip, an explain panel, the to-do list, and a class
   time a reader in another timezone can trust.

   The rule this exists to enforce: **no surface ever prints a raw status
   value.** Everything goes through `chip()` or `explain()`, which take the
   resolved object the server produced from `status_definitions`. v1's problem
   was not a missing label — it was five screens each inventing their own.

   No dependencies, no build step, CSP-safe (nothing inline, nothing remote).
   The pure functions are exercised by v2-clarity.selftest.cjs, which CI RUNS —
   a self-test that is only parsed is not a test (see CLAUDE.md).
   ========================================================================== */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.MAM_CLARITY = factory();
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /* ── escaping ───────────────────────────────────────────────────────────
     Every dynamic value that reaches innerHTML goes through this. Status
     wording is tenant-editable, so it is untrusted input like any other. */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* A URL that will be put in href/location. Anything not clearly a safe
     same-origin or https link becomes '#', because `javascript:` in a
     tenant-editable action_url is a stored XSS. */
  function safeUrl(u) {
    var s = String(u == null ? '' : u).trim();
    if (s === '') return '';
    if (/^(https:)?\/\//i.test(s)) return s;
    if (/^\/[^/\\]/.test(s) || s === '/') return s;   // root-relative, not //evil
    return '#';
  }

  /* ── tone → glyph + spoken prefix ───────────────────────────────────────
     Colour alone never carries meaning: a colour-blind reader, a greyscale
     print and a screen reader must all get the same information. The glyph is
     aria-hidden and the WORD is what assistive tech announces. */
  var TONES = {
    neutral:   { glyph: '•', word: 'Status' },
    positive:  { glyph: '✓', word: 'Good news' },
    progress:  { glyph: '◔', word: 'In progress' },
    attention: { glyph: '!', word: 'Needs attention' },
    warning:   { glyph: '▲', word: 'Warning' },
    critical:  { glyph: '✕', word: 'Problem' }
  };
  function tone(t) { return TONES[t] || TONES.neutral; }

  /**
   * A status chip. `st` is the object from mam_v2_status():
   *   { label, meaning, next_step, tone, actionable, terminal }
   */
  function chip(st, opts) {
    st = st || {}; opts = opts || {};
    var t = tone(st.tone);
    var label = st.label || 'Not set';
    // The tone word is in the accessible name, not merely in the colour, so
    // "Missed" is announced as "Warning: Missed" rather than as a bare word
    // whose severity was only ever conveyed in orange.
    var aria = t.word + ': ' + label;
    return '<span class="mam-chip" data-tone="' + esc(st.tone || 'neutral') + '"'
      + (opts.id ? ' id="' + esc(opts.id) + '"' : '')
      + ' role="status" aria-label="' + esc(aria) + '">'
      + '<span class="mam-chip__glyph" aria-hidden="true">' + esc(t.glyph) + '</span>'
      + '<span class="mam-chip__label">' + esc(label) + '</span>'
      + '</span>';
  }

  /**
   * The explain panel: what it is, what it means, what to do next.
   *
   * `reason` is the free-text explanation attached to a negative outcome (a
   * rejection, a cancellation, a failed payment). The schema now requires one
   * at the source, so this renders it rather than leaving the reader to guess.
   */
  function explain(st, opts) {
    st = st || {}; opts = opts || {};
    var out = '<div class="mam-clarity" data-tone="' + esc(st.tone || 'neutral') + '">';
    out += chip(st);
    if (st.meaning) out += '<p class="mam-clarity__meaning">' + esc(st.meaning) + '</p>';

    if (st.next_step) {
      out += '<div class="mam-clarity__next">'
        + '<strong class="mam-clarity__next-h">What to do next</strong>'
        + esc(st.next_step) + '</div>';
    } else if (opts.showNoAction !== false) {
      // A blank where the next step belongs reads as missing information. Say
      // it plainly instead — "nothing is needed" is a real, reassuring answer.
      out += '<div class="mam-clarity__next mam-clarity__next--none">'
        + '<strong class="mam-clarity__next-h">What to do next</strong>'
        + 'Nothing is needed from you right now.</div>';
    }
    if (opts.reason) {
      out += '<p class="mam-clarity__reason"><strong>Why:</strong> ' + esc(opts.reason) + '</p>';
    }
    return out + '</div>';
  }

  /* ── to-do list ─────────────────────────────────────────────────────────── */

  /**
   * How a due date should be said. "in 3 days" is readable; a raw ISO string
   * is not, and "0 days" is ambiguous about whether it has already passed.
   */
  function dueText(days) {
    if (days === null || days === undefined) return '';
    if (days < -1)  return 'Overdue by ' + Math.abs(days) + ' days';
    if (days === -1) return 'Overdue by 1 day';
    if (days === 0) return 'Due today';
    if (days === 1) return 'Due tomorrow';
    if (days <= 7)  return 'Due in ' + days + ' days';
    return 'Due in ' + Math.round(days / 7) + (Math.round(days / 7) === 1 ? ' week' : ' weeks');
  }

  /** The headline. Written so the common case — nothing to do — is a relief. */
  function summaryText(sum) {
    sum = sum || {};
    var n = sum.total || 0;
    if (n === 0) return 'You are all caught up';
    var s = n === 1 ? 'You have 1 thing to do' : 'You have ' + n + ' things to do';
    if (sum.overdue > 0) s += ', ' + sum.overdue + ' overdue';
    return s;
  }

  /**
   * The list. `items` is what mam_v2_action_items() returned.
   *
   * The empty state is a first-class render, not a fallthrough: it must look
   * different from an error and different from a spinner that never resolved.
   */
  function todoList(items, sum) {
    items = items || [];
    var out = '<section class="mam-todo" aria-labelledby="mam-todo-h">'
      + '<div class="mam-todo__head">'
      + '<h2 class="mam-todo__count" id="mam-todo-h">' + esc(summaryText(sum || { total: items.length })) + '</h2>'
      + '</div>';

    if (!items.length) {
      return out + '<p class="mam-todo__empty">Nothing needs your attention right now. '
        + 'We will put anything new here and email you about it.</p></section>';
    }

    // aria-live so an item resolved in place is announced rather than silently
    // vanishing from under someone using a screen reader.
    out += '<ul class="mam-todo__list" aria-live="polite">';
    items.forEach(function (it) {
      var url = safeUrl(it.action_url);
      out += '<li class="mam-todo__item"'
        + ' data-blocking="' + (it.is_blocking ? 1 : 0) + '"'
        + ' data-overdue="' + (it.is_overdue ? 1 : 0) + '"'
        + ' data-key="' + esc(it.dedupe_key || '') + '">'
        + '<div class="mam-todo__body">'
        + '<p class="mam-todo__title">' + esc(it.title || '') + '</p>';
      if (it.detail) out += '<p class="mam-todo__detail">' + esc(it.detail) + '</p>';
      var d = dueText(it.due_in_days);
      if (d) out += '<p class="mam-todo__due" data-overdue="' + (it.is_overdue ? 1 : 0) + '">' + esc(d) + '</p>';
      out += '</div>';
      if (url) {
        // The accessible name names the task, because "Pay" repeated six times
        // down a list of links tells a screen-reader user nothing about which
        // one they are on.
        out += '<a class="mam-todo__cta" href="' + esc(url) + '" aria-label="'
          + esc((it.action_label || 'Open') + ' — ' + (it.title || '')) + '">'
          + esc(it.action_label || 'Open') + '</a>';
      }
      out += '</li>';
    });
    return out + '</ul></section>';
  }

  /* ── time ───────────────────────────────────────────────────────────────── */

  /** Render mam_v2_when()'s output: the class's own clock, then the viewer's. */
  function when(w) {
    if (!w) return '';
    var s = '<span class="mam-when"><span class="mam-when__local">' + esc(w.text) + '</span>';
    if (w.zone) s += ' <span class="mam-when__zone">(' + esc(w.zone) + ')</span>';
    if (w.also) s += ' <span class="mam-when__also">· ' + esc(w.also) + '</span>';
    return s + '</span>';
  }

  /* ── preferences ────────────────────────────────────────────────────────── */

  /**
   * Apply stored preferences to the document. Called as early as possible so
   * the reader does not see the default paint and then a correction — for
   * someone who set 150% text because they need it, that flash is the whole
   * problem, not a cosmetic one.
   */
  function applyPreferences(prefs, doc) {
    prefs = prefs || {};
    var el = (doc || (typeof document !== 'undefined' ? document : null));
    el = el && el.documentElement;
    if (!el) return {};

    var scale = Number(prefs.text_scale_pct || 100);
    // Clamp to the same range the database CHECK enforces. A value outside it
    // could only arrive from a stale client, and honouring it would render the
    // interface unusable with no way back.
    if (!(scale >= 80 && scale <= 250)) scale = 100;

    el.setAttribute('data-mam-scale', String(scale));
    el.style.setProperty('--mam-scale', String(scale / 100));
    if (prefs.dyslexia_font)  el.setAttribute('data-mam-dyslexia', '1'); else el.removeAttribute('data-mam-dyslexia');
    if (prefs.high_contrast)  el.setAttribute('data-mam-contrast', 'high'); else el.removeAttribute('data-mam-contrast');
    if (prefs.reduce_motion)  el.setAttribute('data-mam-motion', 'reduce'); else el.removeAttribute('data-mam-motion');
    if (prefs.low_bandwidth)  el.setAttribute('data-mam-bandwidth', 'low'); else el.removeAttribute('data-mam-bandwidth');
    if (prefs.locale)         el.setAttribute('lang', String(prefs.locale));
    return { scale: scale };
  }

  /* ── mounting ───────────────────────────────────────────────────────────── */

  function mount(el, html) {
    if (!el) return;
    el.innerHTML = html;
  }

  return {
    esc: esc, safeUrl: safeUrl, tone: tone,
    chip: chip, explain: explain,
    dueText: dueText, summaryText: summaryText, todoList: todoList,
    when: when, applyPreferences: applyPreferences, mount: mount,
    TONES: TONES
  };
}));
