/* /verify — certificate check (verify.php). Same endpoint, same states and words as
   the page before the 2026-11 redesign: GET /api/verify-certificate?ref=…, no
   credentials, ?ref= in the address auto-loads and the address follows the lookup. */
(function () {
  'use strict';
  var body = document.getElementById('vfBody');
  if (!body) return;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  var H = 'margin: 0; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 500; font-size: 34px; line-height: 1.1';
  var P = 'margin: 8px 0 0; font-size: 17px; line-height: 1.6; color: #574B40';
  function askForm(msg) {
    body.innerHTML = '<h2 style="' + H + '">Verify a certificate</h2><p style="' + P + '">' + esc(msg || 'Enter the certificate ID printed on the document.') + '</p>'
      + '<form id="vfForm" style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px" novalidate>'
      + '<label for="vfRef" class="sr-xs">Certificate ID</label>'
      + '<input id="vfRef" placeholder="Certificate ID" autocomplete="off" style="flex: 1 1 240px; min-height: 48px; padding: 0 16px; border: 1px solid #CFC3B3; border-radius: 12px; font: inherit; font-size: 16px; color: #211A13; background: #FFFFFF">'
      + '<button type="submit" class="btn" style="min-height: 48px; padding: 0 24px; border: 0; border-radius: 999px; background: #F2A93B; color: #211A13; font: inherit; font-weight: 600; cursor: pointer">Verify</button></form>';
    var f = document.getElementById('vfForm'), inp = document.getElementById('vfRef');
    f.addEventListener('submit', function (e) { e.preventDefault(); var v = inp.value.trim(); if (v) load(v); });
    if (msg) inp.focus();
  }
  function fmtDate(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? String(s).slice(0, 10) : d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }); }
  function row(k, v, mono) {
    return '<div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 24px; padding: 12px 0; border-top: 1px solid #E6DED2">'
      + '<dt style="font-size: 15px; color: #574B40">' + k + '</dt><dd style="margin: 0; font-weight: 600; text-align: right' + (mono ? '; font-family: ui-monospace, monospace; font-size: 15px' : '') + '">' + v + '</dd></div>';
  }
  function load(ref) {
    body.innerHTML = '<p style="margin: 0; font-size: 17px; color: #574B40">Checking…</p>';
    try { history.replaceState(null, '', '/verify?ref=' + encodeURIComponent(ref)); } catch (e) { /* file:// */ }
    fetch('/api/verify-certificate?ref=' + encodeURIComponent(ref), { credentials: 'omit' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var d = (res && res.data) || res || {};
        if (!d.valid) { askForm('No certificate matches that ID. Check the ID and try again.'); return; }
        var rows = row('Issued to', esc(d.student_name)) + row('Credential', esc(d.title));
        if (d.program_name) rows += row('Programme', esc(d.program_name) + (d.level_name ? ' · ' + esc(d.level_name) : ''));
        if (d.certification) rows += row('Standard', esc(d.certification));
        if (d.issued_at) rows += row('Issued', esc(fmtDate(d.issued_at)));
        rows += row('Certificate ID', esc(d.reference), true);
        body.innerHTML = '<p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Verified</p>'
          + '<h2 style="' + H + '; margin-top: 8px">Valid certificate</h2><p style="' + P + '">This credential was issued by MAM Academy.</p>'
          + '<dl style="margin: 20px 0 0">' + rows + '</dl>'
          + '<p style="margin: 20px 0 0; font-size: 14px; color: #574B40">Verified against MAM Academy records · academy.mamcentre.org</p>';
      })
      .catch(function () { askForm('Could not reach the verification service. Please try again.'); });
  }
  var initial = body.getAttribute('data-ref');
  if (initial) load(initial); else askForm();
})();
