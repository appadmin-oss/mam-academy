/* MAM Academy — the online class page (portal/live.html?session=N).
 *
 * Asks /api/live/join who this person is in the class, then:
 *   · JaaS: loads 8x8's external_api.js and embeds the call with the signed
 *     token. A teacher in a class with children turns the lobby on, so
 *     students wait until they are let in.
 *   · anything else: a button to the meeting link (Google Meet, or a plain
 *     Jitsi room), because those cannot be embedded with a signed identity.
 * While the call is open it beats once a minute (paused when the tab is
 * hidden — a page left open in the background is not a person in class).
 * A teacher can take the register from the call when it ends.
 */
(function () {
  'use strict';
  var main = document.getElementById('main');
  var sid = Number(new URLSearchParams(location.search).get('session') || 0);
  var beat = null, live = null, api = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function toast(msg, tone) {
    var t = document.getElementById('toast'); document.getElementById('toastText').textContent = msg;
    t.className = 'p-toast' + (tone ? ' is-' + tone : ''); t.setAttribute('role', tone === 'bad' ? 'alert' : 'status'); t.hidden = false;
    if (tone !== 'bad') setTimeout(function () { t.hidden = true; }, 5000);
  }
  function errText(e, fb) { return (window.P && P.errText) ? P.errText(e, fb) : fb; }
  function back() { return '<a class="p-back" href="/portal/"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Back</a>'; }

  function startBeats() {
    if (beat) return;
    var send = function () {
      if (document.visibilityState !== 'visible') return;
      MAM_API.request('/api/live/beat', { method: 'POST', body: { session: sid } }).catch(function () {});
    };
    send();
    beat = setInterval(send, (live && live.beat_sec ? live.beat_sec : 60) * 1000);
  }
  function stopBeats() { if (beat) { clearInterval(beat); beat = null; } }

  function head() {
    return '<header class="lv-head"><div><h1 class="p-h1" tabindex="-1">' + esc(live.title) + '</h1>'
      + '<p class="p-caption">' + new Date(live.starts_at).toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' })
      + ' · ' + esc(live.minutes) + ' min' + (live.moderator ? ' · you are leading this class' : '') + '</p></div>'
      + (live.moderator ? '<button type="button" class="p-ghost" id="lvRegister"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Take the register from the call</button>' : '')
      + '</header>';
  }

  function render() {
    if (live.provider === 'jaas') {
      main.innerHTML = head()
        + (live.minors && !live.moderator ? '<p class="p-notice"><i class="fa-solid fa-door-open" aria-hidden="true"></i><span>You may wait in the lobby for a moment — your teacher lets everyone in.</span></p>' : '')
        + '<div id="meet" class="lv-meet"></div><div id="lvReg"></div>';
      var s = document.createElement('script');
      s.src = live.script;
      s.onload = function () {
        api = new window.JitsiMeetExternalAPI(live.domain, {
          roomName: live.room, jwt: live.jwt, parentNode: document.getElementById('meet'), width: '100%', height: '100%',
          configOverwrite: live.config, userInfo: { displayName: live.name }
        });
        api.addListener('videoConferenceJoined', function () {
          startBeats();
          if (live.moderator && live.lobby) { try { api.executeCommand('toggleLobby', true); } catch (e) {} }
        });
        api.addListener('videoConferenceLeft', function () { stopBeats(); });
        api.addListener('readyToClose', function () { stopBeats(); main.querySelector('#meet').innerHTML = '<div class="p-empty"><p>You have left the class.</p></div>'; });
      };
      s.onerror = function () { main.innerHTML = head() + '<div class="p-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><p>The class could not load. Check your connection and try again.</p></div>'; };
      document.head.appendChild(s);
    } else {
      main.innerHTML = head() + '<section class="p-card"><p class="p-body">This class runs in '
        + (live.provider === 'meet' ? 'Google Meet' : 'Zoom') + '. Keep this page open while you are in it — it is how your time in class is counted.</p>'
        + '<a class="p-action" href="' + esc(/^https:\/\//.test(live.link || '') ? live.link : '#') + '" target="_blank" rel="noopener" id="lvOpen">Join the class</a></section><div id="lvReg"></div>';
      document.getElementById('lvOpen').addEventListener('click', startBeats);
    }
    var reg = document.getElementById('lvRegister');
    if (reg) reg.addEventListener('click', takeRegister);
  }

  function takeRegister(ev) {
    var b = ev.currentTarget; b.disabled = true;
    MAM_API.request('/api/live/register', { method: 'POST', body: { session: sid } }).then(function (r) {
      b.disabled = false;
      var g = r.register, html = '<section class="p-card"><h2 class="p-title">Register from the call</h2>';
      if (g.note) html += '<p class="p-notice is-warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>' + esc(g.note) + '</span></p>';
      if (!g.rows.length) html += '<p class="p-caption">Nobody has been counted yet.</p>';
      g.rows.forEach(function (x) {
        html += '<div class="p-row"><span class="p-row-main"><span class="p-title">' + esc(x.name) + '</span><span class="p-caption">'
          + esc(x.minutes) + ' min in class' + (x.late ? ' · ' + esc(x.late) + ' min late' : '') + '</span></span>'
          + '<span class="p-chip">' + esc(x.role === 'student' ? String(x.written).replace('_', ' ') : 'staff') + '</span></div>';
      });
      document.getElementById('lvReg').innerHTML = html + '<p class="p-caption">A mark a teacher made by hand is kept; the call only fills in the times.</p></section>';
      toast('Register written.', 'good');
    }).catch(function (e) { b.disabled = false; toast(errText(e, 'The register could not be taken.'), 'bad'); });
  }

  if (!sid) { main.innerHTML = back() + '<div class="p-empty"><p>Open a class from your timetable.</p></div>'; return; }
  MAM_API.request('/api/live/join?session=' + sid).then(function (r) { live = r.live; render(); var h = main.querySelector('.p-h1'); if (h) h.focus(); })
    .catch(function (e) {
      if (e && e.status === 401) { location.href = '/portal/login?next=' + encodeURIComponent(location.pathname + location.search); return; }
      main.innerHTML = back() + '<div class="p-empty"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i><p>' + esc(errText(e, 'This class cannot be opened.')) + '</p></div>';
    });
  window.addEventListener('pagehide', stopBeats);
})();
