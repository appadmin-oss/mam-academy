/*! MAM Academy — "Ask MAM" assistant widget  (provider-agnostic, context-aware)
 * ---------------------------------------------------------------------------
 * A floating chat launcher + panel that talks to /api/ai/chat. The persona is
 * chosen by <body data-ai-context="site|student|practice|admin"> (default
 * "site"). Self-contained: injects its own styles, runs its own CSRF + fetch, so
 * it works on the public site, the portals and /admin alike. It only appears
 * when the server reports the assistant is enabled — otherwise it does nothing,
 * so dropping it on every page is safe. Fully guarded; never throws.
 */
(function () {
  'use strict';
  if (window.__mamAI) return; window.__mamAI = true;

  var API = '/api';
  var CONTEXT = (document.body && document.body.getAttribute('data-ai-context')) || 'site';
  var META = {
    site:     { title: 'Ask MAM', greet: "Hi! I'm the MAM Academy assistant. Ask me about our programmes, the MAM Auditer, applying — or anything music. 🎵" },
    student:  { title: 'Study buddy', greet: "Hi! I'm your MAM study buddy. Ask me about theory, technique, or planning your practice." },
    practice: { title: 'Practice coach', greet: "Practice coach here 👋 — ask what to work on, or any music concept you'd like explained." },
    admin:    { title: 'MAM co-pilot', greet: "Co-pilot ready. I can draft announcements or emails, or summarise — what do you need?" }
  };
  var meta = META[CONTEXT] || META.site;
  var messages = [];                 // {role:'user'|'assistant', content}
  var csrf = null, busy = false, els = {};

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmt(s) {            // minimal, safe markdown → HTML
    s = esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>');
    return s.replace(/\n/g, '<br>');
  }

  /* ── API (self-contained CSRF + fetch) ── */
  function getCsrf(force) {
    if (csrf && !force) return Promise.resolve(csrf);
    return fetch(API + '/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : {}; }).then(function (j) { csrf = (j && j.token) || ''; return csrf; })
      .catch(function () { return ''; });
  }
  function postChat(tok) {
    return fetch(API + '/ai/chat', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': tok || '' },
      body: JSON.stringify({ context: CONTEXT, messages: messages })
    }).then(function (r) {
      return r.text().then(function (t) { var j = {}; try { j = JSON.parse(t); } catch (e) {} if (!r.ok) { var e2 = new Error((j && j.error) || ('http_' + r.status)); e2.status = r.status; e2.body = j; throw e2; } return j; });
    });
  }
  function ask() {
    return getCsrf().then(postChat).catch(function (err) {
      if (err && err.status === 403 && err.body && err.body.error === 'csrf_invalid') return getCsrf(true).then(postChat);
      throw err;
    });
  }

  /* ── UI ── */
  function injectCss() {
    var css = '' +
      '.maiw-fab{position:fixed;right:18px;bottom:18px;z-index:9000;display:inline-flex;align-items:center;gap:9px;padding:12px 18px;border:0;border-radius:100px;cursor:pointer;font:700 0.9rem/1 Inter,system-ui,sans-serif;color:#1A120B;background:linear-gradient(135deg,var(--gold,#E08A1A),var(--gold-light,#F0A92E));box-shadow:0 10px 28px rgba(224,138,26,0.45)}' +
      '.maiw-fab:hover{transform:translateY(-1px)}.maiw-fab i{font-size:1.05rem}' +
      '.maiw-panel{position:fixed;right:18px;bottom:18px;z-index:9001;width:min(380px,calc(100vw - 28px));height:min(560px,calc(100vh - 36px));display:none;flex-direction:column;background:var(--bg-surface,#1c1409);border:1px solid var(--border,#2a2014);border-radius:18px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,0.5)}' +
      '.maiw-panel.open{display:flex;animation:maiwUp .22s ease}' +
      '@keyframes maiwUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}' +
      '.maiw-head{display:flex;align-items:center;gap:10px;padding:14px 16px;background:linear-gradient(135deg,var(--gold,#E08A1A),var(--gold-light,#F0A92E));color:#1A120B}' +
      '.maiw-head b{font:800 0.98rem/1 Inter,system-ui,sans-serif;flex:1}.maiw-head small{font-weight:600;opacity:.8;font-size:.7rem}' +
      '.maiw-x{background:rgba(0,0,0,0.15);border:0;color:#1A120B;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:1rem;line-height:1}' +
      '.maiw-body{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:var(--bg,#140e07)}' +
      '.maiw-msg{max-width:85%;padding:10px 13px;border-radius:13px;font:400 0.9rem/1.5 Inter,system-ui,sans-serif;white-space:normal;word-wrap:break-word}' +
      '.maiw-msg.user{align-self:flex-end;background:linear-gradient(135deg,var(--gold,#E08A1A),var(--gold-light,#F0A92E));color:#1A120B;border-bottom-right-radius:4px}' +
      '.maiw-msg.bot{align-self:flex-start;background:var(--bg-raised,#241a10);color:var(--text-soft,#EADBC4);border:1px solid var(--border,#2a2014);border-bottom-left-radius:4px}' +
      '.maiw-msg code{background:rgba(0,0,0,0.25);padding:1px 5px;border-radius:5px;font-size:0.85em}' +
      '.maiw-msg.err{background:rgba(244,63,94,0.12);border:1px solid rgba(244,63,94,0.4);color:#FCA5A5}' +
      '.maiw-msg.err a{color:var(--gold-bright,#FCC647)}' +
      '.maiw-typing{align-self:flex-start;display:inline-flex;gap:4px;padding:12px 14px;background:var(--bg-raised,#241a10);border:1px solid var(--border,#2a2014);border-radius:13px}' +
      '.maiw-typing i{width:7px;height:7px;border-radius:50%;background:var(--gold-bright,#FCC647);opacity:.5;animation:maiwBlink 1s infinite}' +
      '.maiw-typing i:nth-child(2){animation-delay:.2s}.maiw-typing i:nth-child(3){animation-delay:.4s}' +
      '@keyframes maiwBlink{0%,100%{opacity:.3}50%{opacity:1}}' +
      '.maiw-foot{display:flex;gap:8px;padding:10px;border-top:1px solid var(--border,#2a2014);background:var(--bg-surface,#1c1409)}' +
      '.maiw-foot textarea{flex:1;resize:none;max-height:96px;padding:10px 12px;border-radius:11px;border:1px solid var(--border-strong,#3a2c1a);background:var(--bg,#140e07);color:var(--text,#F5EEE3);font:400 0.9rem/1.4 Inter,system-ui,sans-serif}' +
      '.maiw-foot textarea:focus{outline:none;border-color:var(--gold,#E08A1A)}' +
      '.maiw-send{flex-shrink:0;width:44px;border:0;border-radius:11px;cursor:pointer;color:#1A120B;background:linear-gradient(135deg,var(--gold,#E08A1A),var(--gold-light,#F0A92E));font-size:1rem}' +
      '.maiw-send:disabled{opacity:.5;cursor:default}' +
      '.maiw-note{padding:0 14px 10px;font:400 0.66rem/1.4 Inter,system-ui,sans-serif;color:var(--text-muted,#9a8a70);background:var(--bg,#140e07)}' +
      '@media (max-width:480px){.maiw-panel{right:8px;bottom:8px;width:calc(100vw - 16px);height:calc(100vh - 80px)}.maiw-fab span{display:none}.maiw-fab{padding:14px}}';
    var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  }

  function mount() {
    injectCss();
    var fab = document.createElement('button');
    fab.className = 'maiw-fab'; fab.type = 'button'; fab.setAttribute('aria-label', 'Open ' + meta.title);
    fab.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><span>' + esc(meta.title) + '</span>';

    var panel = document.createElement('div');
    panel.className = 'maiw-panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', meta.title);
    panel.innerHTML =
      '<div class="maiw-head"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><b>' + esc(meta.title) + ' <small>AI</small></b><button class="maiw-x" type="button" aria-label="Close">×</button></div>' +
      '<div class="maiw-body" id="maiwBody"></div>' +
      '<div class="maiw-foot"><textarea id="maiwIn" rows="1" placeholder="Type your question…" aria-label="Message"></textarea><button class="maiw-send" id="maiwSend" type="button" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button></div>' +
      '<div class="maiw-note">AI can be wrong — check anything important with the academy.</div>';

    document.body.appendChild(fab); document.body.appendChild(panel);
    els.body = panel.querySelector('#maiwBody'); els.input = panel.querySelector('#maiwIn'); els.send = panel.querySelector('#maiwSend');

    var opened = false;
    function open() { panel.classList.add('open'); fab.style.display = 'none'; if (!opened) { opened = true; addBot(meta.greet); } setTimeout(function () { els.input.focus(); }, 60); }
    function close() { panel.classList.remove('open'); fab.style.display = ''; }
    fab.addEventListener('click', open);
    panel.querySelector('.maiw-x').addEventListener('click', close);

    els.send.addEventListener('click', send);
    els.input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
    els.input.addEventListener('input', function () { els.input.style.height = 'auto'; els.input.style.height = Math.min(96, els.input.scrollHeight) + 'px'; });
  }

  function row(cls, html) { var d = document.createElement('div'); d.className = 'maiw-msg ' + cls; d.innerHTML = html; els.body.appendChild(d); els.body.scrollTop = els.body.scrollHeight; return d; }
  function addBot(text) { row('bot', fmt(text)); }
  function addUser(text) { row('user', esc(text)); }
  function typing(on) {
    if (on) { var d = document.createElement('div'); d.className = 'maiw-typing'; d.id = 'maiwTyping'; d.innerHTML = '<i></i><i></i><i></i>'; els.body.appendChild(d); els.body.scrollTop = els.body.scrollHeight; }
    else { var t = document.getElementById('maiwTyping'); if (t) t.remove(); }
  }

  function send() {
    if (busy) return;
    var text = (els.input.value || '').trim(); if (!text) return;
    els.input.value = ''; els.input.style.height = 'auto';
    addUser(text); messages.push({ role: 'user', content: text });
    busy = true; els.send.disabled = true; typing(true);
    ask().then(function (r) {
      typing(false); busy = false; els.send.disabled = false;
      var reply = (r && r.reply) || "I didn't catch that — try rephrasing?";
      messages.push({ role: 'assistant', content: reply }); addBot(reply);
      els.input.focus();
    }).catch(function (err) {
      typing(false); busy = false; els.send.disabled = false;
      messages.pop();                       // drop the unanswered turn so retry is clean
      var st = err && err.status;
      var msg = st === 401 ? 'Please <a href="/portal/login.html">sign in</a> to use the assistant.'
        : st === 429 ? "You're going a bit fast — give it a few seconds and try again."
        : st === 503 ? "The assistant isn't enabled yet."
        : "I couldn't reach the assistant just now. Please try again.";
      row('bot err', msg);
    });
  }

  /* ── Only appear if the server has the assistant enabled ── */
  function boot() {
    if (!document.body) { return; }
    try {
      fetch(API + '/config/public', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { if (j && j.ai_enabled) mount(); })
        .catch(function () { /* stays hidden */ });
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
