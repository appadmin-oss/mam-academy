/**
 * MAM Academy — inline field editing with undo (Advanced Modernization §4.4).
 * ─────────────────────────────────────────────────────────────
 * Declarative: any element carrying
 *
 *   data-ie-table="cohorts" data-ie-column="title" data-ie-id="12"
 *   [data-ie-type="int"] [data-ie-label="Cohort title"]
 *
 * becomes editable in place. Click (or focus + Enter) turns it into an input;
 * Enter or blur commits; Escape cancels. A commit shows a toast with an UNDO
 * button for a few seconds.
 *
 * Undo is not a second mechanism. The server returns the value it replaced,
 * and undoing sends that value back through the same endpoint — so an undo is
 * itself an audited edit, and there is no "pending" state on the server that
 * could be lost, expire, or be replayed by someone else.
 *
 * The server is authoritative on what may be edited at all: this file can
 * decorate any cell, but POST /api/admin/inline-edit refuses anything not on
 * its allowlist. Nothing here is a permission check.
 */
(function () {
  'use strict';

  var UNDO_MS = 8000;
  var toastHost = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function post(body) {
    var f = window.mamFetch || function (u, o) { return fetch(u, o); };
    return f('/api/admin/inline-edit', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'bad_response' }; }); });
  }

  function ensureToastHost() {
    if (toastHost && document.body.contains(toastHost)) { return toastHost; }
    toastHost = document.createElement('div');
    toastHost.className = 'ie-toasts';
    // Polite: an edit the admin just made themselves is confirmation, not an
    // interruption worth cutting across a screen reader mid-sentence.
    toastHost.setAttribute('role', 'status');
    toastHost.setAttribute('aria-live', 'polite');
    document.body.appendChild(toastHost);
    return toastHost;
  }

  function toast(message, undoFn) {
    var host = ensureToastHost();
    var el = document.createElement('div');
    el.className = 'ie-toast';
    el.innerHTML = '<span>' + esc(message) + '</span>';
    if (undoFn) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'ie-undo';
      b.textContent = 'Undo';
      b.addEventListener('click', function () { cleanup(); undoFn(); });
      el.appendChild(b);
    }
    host.appendChild(el);
    var t = window.setTimeout(cleanup, UNDO_MS);
    function cleanup() {
      window.clearTimeout(t);
      if (el.parentNode) { el.parentNode.removeChild(el); }
    }
    return cleanup;
  }

  /** Write the committed value back into the cell and refresh its dataset. */
  function settle(cell, value) {
    cell.textContent = value === '' || value == null ? '—' : String(value);
    cell.setAttribute('data-ie-value', value == null ? '' : String(value));
    cell.classList.remove('is-editing');
    cell.classList.add('is-saved');
    window.setTimeout(function () { cell.classList.remove('is-saved'); }, 1200);
  }

  function commit(cell, raw, previousShown) {
    var payload = {
      table:  cell.getAttribute('data-ie-table'),
      column: cell.getAttribute('data-ie-column'),
      id:     parseInt(cell.getAttribute('data-ie-id'), 10),
      value:  raw
    };
    cell.classList.add('is-saving');
    post(payload).then(function (j) {
      cell.classList.remove('is-saving');
      if (!j || j.ok === false || j.error) {
        // Put the old text back: a cell showing a value the server rejected is
        // worse than no edit at all, because the admin walks away believing it.
        settle(cell, previousShown);
        cell.classList.add('is-error');
        window.setTimeout(function () { cell.classList.remove('is-error'); }, 2000);
        toast(errorText(j), null);
        return;
      }
      settle(cell, j.value);
      if (!j.changed) { return; }           // nothing happened — nothing to undo
      var label = cell.getAttribute('data-ie-label') || j.label || 'Field';
      toast(label + ' updated.', function () {
        // Undo = the same edit in reverse, audited like any other.
        post({ table: payload.table, column: payload.column, id: payload.id, value: j.previous })
          .then(function (u) {
            if (u && !u.error && u.ok !== false) { settle(cell, u.value); toast(label + ' change undone.', null); }
            else { toast('Could not undo that change.', null); }
          })
          .catch(function () { toast('Could not undo that change.', null); });
      });
    }).catch(function () {
      cell.classList.remove('is-saving');
      settle(cell, previousShown);
      toast('Could not save — check your connection.', null);
    });
  }

  function errorText(j) {
    var e = j && j.error;
    if (e === 'invalid_value') {
      var why = { too_long: 'that is too long', empty: 'it cannot be empty', not_a_number: 'that is not a number',
                  too_small: 'that is below the minimum', too_large: 'that is above the maximum',
                  bad_characters: 'it contains characters that are not allowed' }[j.reason];
      return 'Not saved — ' + (why || 'that value is not valid') + '.';
    }
    if (e === 'forbidden' || e === 'not_editable') { return 'Not saved — you do not have permission to edit that.'; }
    if (e === 'not_found') { return 'Not saved — that record no longer exists.'; }
    return 'Not saved — the server rejected that change.';
  }

  function beginEdit(cell) {
    if (cell.classList.contains('is-editing')) { return; }
    var shown = cell.getAttribute('data-ie-value');
    if (shown === null) { shown = cell.textContent.trim(); if (shown === '—') { shown = ''; } }
    var type = cell.getAttribute('data-ie-type') || 'text';

    cell.classList.add('is-editing');
    var input = document.createElement('input');
    input.type = type === 'int' ? 'number' : 'text';
    input.className = 'ie-input';
    input.value = shown;
    input.setAttribute('aria-label', cell.getAttribute('data-ie-label') || 'Edit value');
    cell.textContent = '';
    cell.appendChild(input);
    input.focus();
    input.select();

    var done = false;
    function finish(save) {
      if (done) { return; }
      done = true;
      var val = input.value;
      if (!save || String(val) === String(shown)) { settle(cell, shown); return; }
      commit(cell, type === 'int' ? String(val).trim() : val, shown);
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    input.addEventListener('blur', function () { finish(true); });
  }

  function decorate(cell) {
    if (cell.getAttribute('data-ie-ready')) { return; }
    cell.setAttribute('data-ie-ready', '1');
    cell.classList.add('ie-cell');
    // Focusable and Enter-activatable: an editor reachable only by mouse is
    // not reachable by everyone who has to use this dashboard all day.
    if (!cell.hasAttribute('tabindex')) { cell.setAttribute('tabindex', '0'); }
    cell.setAttribute('role', 'button');
    var lbl = cell.getAttribute('data-ie-label');
    cell.setAttribute('title', lbl ? ('Click to edit ' + lbl.toLowerCase()) : 'Click to edit');
    if (cell.getAttribute('data-ie-value') === null) {
      cell.setAttribute('data-ie-value', cell.textContent.trim());
    }
  }

  function scan(root) {
    (root || document).querySelectorAll('[data-ie-table][data-ie-column][data-ie-id]').forEach(decorate);
  }

  document.addEventListener('click', function (e) {
    var cell = e.target.closest ? e.target.closest('[data-ie-table][data-ie-column][data-ie-id]') : null;
    if (!cell || e.target.tagName === 'INPUT') { return; }
    e.preventDefault();
    beginEdit(cell);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') { return; }
    var cell = document.activeElement;
    if (!cell || !cell.hasAttribute || !cell.hasAttribute('data-ie-table') || cell.classList.contains('is-editing')) { return; }
    e.preventDefault();
    beginEdit(cell);
  });

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { scan(); }); }
  else { scan(); }

  window.MAM_INLINE_EDIT = { scan: scan, _internals: { errorText: errorText } };
})();
