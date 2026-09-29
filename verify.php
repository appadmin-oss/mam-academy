<?php
/**
 * /verify — public certificate verification (Advanced Modernization §6.4).
 * A QR on every certificate resolves here. No login; data comes from the
 * rate-limited public API. ?ref=MAM-CERT-… auto-loads.
 */
declare(strict_types=1);
$refGet = trim((string)($_GET['ref'] ?? ''));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify Certificate — MAM Academy</title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/svg+xml" href="/mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<style>
  :root{--gold:#C79A3A;--gold-bright:#E8961F;--ink:#1B2430;--muted:#5b6472;--border:#e4e6ea;--bg:#f4f5f7;--card:#fff;--ok:#1FA971;--bad:#E0574F}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.6;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.10);max-width:520px;width:100%;overflow:hidden}
  .card-head{padding:22px 26px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
  .card-head img{width:38px;height:38px}
  .card-head b{font-family:Cinzel,serif;letter-spacing:.04em;font-size:1.05rem}
  .card-head span{display:block;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
  .body{padding:26px}
  .state{display:flex;align-items:center;gap:14px;margin-bottom:20px}
  .state .ico{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;font-size:1.5rem;flex:0 0 auto}
  .state.ok .ico{background:rgba(31,169,113,.12);color:var(--ok)}
  .state.bad .ico{background:rgba(224,87,79,.12);color:var(--bad)}
  .state.load .ico{background:rgba(199,154,58,.12);color:var(--gold)}
  .state h1{font-size:1.15rem;line-height:1.25}
  .state p{color:var(--muted);font-size:.9rem}
  dl{display:grid;grid-template-columns:auto 1fr;gap:10px 18px;margin-top:6px}
  dt{color:var(--muted);font-size:.82rem}
  dd{font-weight:600;text-align:right}
  .ref{font-family:ui-monospace,monospace;font-size:.82rem}
  .foot{margin-top:22px;padding-top:16px;border-top:1px solid var(--border);font-size:.78rem;color:var(--muted);text-align:center}
  .form{display:flex;gap:8px;margin-top:14px}
  .form input{flex:1;padding:11px 13px;border:1px solid var(--border);border-radius:10px;font:inherit}
  .form button{padding:11px 18px;border:0;border-radius:10px;background:linear-gradient(135deg,var(--gold-bright),#FBBF24);color:#1B2430;font-weight:700;cursor:pointer}
</style>
</head>
<body>
<div class="card">
  <div class="card-head">
    <img src="/mam-academy-logo.svg" alt="" onerror="this.style.display='none'">
    <div><b>MAM Academy</b><span>Certificate verification</span></div>
  </div>
  <div class="body" id="body">
    <div class="state load"><div class="ico"><i class="fa-solid fa-circle-notch fa-spin"></i></div><div><h1>Checking…</h1></div></div>
  </div>
</div>
<script>
(function () {
  var body = document.getElementById('body');
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
  function askForm(msg) {
    body.innerHTML = '<div class="state bad"><div class="ico"><i class="fa-solid fa-magnifying-glass"></i></div><div><h1>Verify a certificate</h1><p>' + esc(msg || 'Enter the certificate ID printed on the document.') + '</p></div></div>'
      + '<form class="form" onsubmit="return false"><input id="ref" placeholder="Certificate ID" autocomplete="off"><button id="go">Verify</button></form>';
    document.getElementById('go').addEventListener('click', function () { var v = document.getElementById('ref').value.trim(); if (v) load(v); });
    document.getElementById('ref').addEventListener('keydown', function (e) { if (e.key === 'Enter') { var v = this.value.trim(); if (v) load(v); } });
  }
  function fmtDate(s){ if(!s) return ''; var d=new Date(String(s).replace(' ','T')); return isNaN(d)?String(s).slice(0,10):d.toLocaleDateString(undefined,{year:'numeric',month:'long',day:'numeric'}); }
  function load(ref) {
    body.innerHTML = '<div class="state load"><div class="ico"><i class="fa-solid fa-circle-notch fa-spin"></i></div><div><h1>Checking…</h1></div></div>';
    try { history.replaceState(null, '', '/verify?ref=' + encodeURIComponent(ref)); } catch (e) {}
    fetch('/api/verify-certificate?ref=' + encodeURIComponent(ref), { credentials: 'omit' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var d = (res && res.data) || res || {};
        if (!d.valid) { askForm('No certificate matches that ID. Check the ID and try again.'); return; }
        var rows = '';
        rows += '<dt>Issued to</dt><dd>' + esc(d.student_name) + '</dd>';
        rows += '<dt>Credential</dt><dd>' + esc(d.title) + '</dd>';
        if (d.program_name) rows += '<dt>Programme</dt><dd>' + esc(d.program_name) + (d.level_name ? ' · ' + esc(d.level_name) : '') + '</dd>';
        if (d.certification) rows += '<dt>Standard</dt><dd>' + esc(d.certification) + '</dd>';
        if (d.issued_at) rows += '<dt>Issued</dt><dd>' + esc(fmtDate(d.issued_at)) + '</dd>';
        rows += '<dt>Certificate ID</dt><dd class="ref">' + esc(d.reference) + '</dd>';
        body.innerHTML = '<div class="state ok"><div class="ico"><i class="fa-solid fa-circle-check"></i></div><div><h1>Valid certificate</h1><p>This credential was issued by MAM Academy.</p></div></div><dl>' + rows + '</dl>'
          + '<div class="foot">Verified against MAM Academy records · academy.mamcentre.org</div>';
      })
      .catch(function () { askForm('Could not reach the verification service. Please try again.'); });
  }
  var initial = <?= json_encode($refGet) ?>;
  if (initial) { load(initial); } else { askForm(); }
})();
</script>
</body>
</html>
