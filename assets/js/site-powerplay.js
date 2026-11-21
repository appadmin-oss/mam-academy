/*
 * Power Play (power-play.php) — the countdown recounted on the visitor's clock,
 * and registration. The server printed the date and count for Lagos (from
 * mam_powerplay_next(), the same function that dates the confirmation email), so
 * with no script the panel still says something true.
 *
 * Registration posts what the previous page posted through MAM_API.subscribeNewsletter:
 * POST /api/newsletter {email, source: 'power-play'} with the CSRF token, and says
 * the outcome out loud — including a mailto fallback when the API is unreachable.
 */
(function () {
  'use strict';
  var d = document;

  /* ---------- countdown ---------- */
  var dateEl = d.querySelector('[data-pp-at]');
  var countEl = d.querySelector('[data-pp-count]');
  var timeEl = d.querySelector('[data-pp-time]');
  if (dateEl && countEl) {
    var at = new Date(dateEl.getAttribute('data-pp-at'));
    if (!isNaN(at)) {
      try {
        timeEl.textContent = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(at) + ' your time';
      } catch (e) {}
      var diff = function () {
        var s = Math.max(0, Math.floor((at - Date.now()) / 1000));
        var days = Math.floor(s / 86400); s -= days * 86400;
        var hrs = Math.floor(s / 3600); s -= hrs * 3600;
        var mins = Math.floor(s / 60);
        if (days > 0) return days + (days === 1 ? ' day ' : ' days ') + hrs + 'h to go';
        if (hrs > 0) return hrs + 'h ' + mins + 'm to go';
        if (mins > 0) return mins + ' minutes to go — almost live';
        return 'Live now';
      };
      countEl.textContent = diff();
      setInterval(function () { countEl.textContent = diff(); }, 60000);
    }
  }

  /* ---------- a configured Meet room: the register buttons open it ---------- */
  var meet = window.MAM_POWERPLAY && window.MAM_POWERPLAY.meetUrl;
  if (meet) {
    d.querySelectorAll('[data-pp-cta]').forEach(function (a) { a.href = meet; a.target = '_blank'; a.rel = 'noopener'; });
  }

  /* ---------- registration ---------- */
  var form = d.getElementById('ppForm');
  if (!form) return;
  var emailEl = d.getElementById('ppEmail');
  var msg = d.getElementById('ppMsg');
  var btn = form.querySelector('button[type="submit"]');
  var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  var mailto = function (email) {
    return 'mailto:academy@mamcentre.org?subject=' + encodeURIComponent('Power Play — register me') +
      '&body=' + encodeURIComponent('Please send me the link for the next Power Play session.\nEmail: ' + email);
  };
  var say = function (text, link) {
    msg.textContent = text;
    if (link) {
      var a = d.createElement('a');
      a.href = link.href; a.textContent = link.text; a.className = 'ul'; a.style.color = '#F2A93B';
      msg.appendChild(d.createTextNode(' ')); msg.appendChild(a);
    }
  };
  var busy = function (on) { btn.disabled = on; btn.textContent = on ? 'Reserving…' : 'Reserve my seat'; };

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var email = emailEl.value.trim();
    if (!re.test(email)) {
      emailEl.setAttribute('aria-invalid', 'true'); emailEl.style.borderColor = '#B0361C'; emailEl.focus();
      say('Please enter a valid email address, like name@example.com.');
      return;
    }
    emailEl.removeAttribute('aria-invalid'); emailEl.style.borderColor = '';
    busy(true); say('Reserving your seat…');
    fetch('/api/csrf', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      var token = j && (j.token || (j.data && j.data.token)) || '';
      return fetch('/api/newsletter', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ email: email, source: 'power-play' }) });
    }).then(function (r) {
      busy(false);
      if (r.ok) { form.reset(); say('You’re in — check your inbox for the Google Meet link.'); }
      else if (r.status === 429) say('You’ve tried a few times — please wait a minute, then try again.');
      else say('We couldn’t reserve your seat just now. Please try again, or', { href: mailto(email), text: 'email us your request.' });
    }).catch(function () {
      busy(false);
      say('Registration is briefly unavailable.', { href: mailto(email), text: 'Email us your seat request and we’ll send the Meet link.' });
    });
  });
})();
