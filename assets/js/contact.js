/* Contact form (/contact) on the 2026-11 shell. Posts the same fields the page
   always sent — name, email, phone, topic, message — to the CSRF-gated
   POST /api/contact, the way site.js's newsletter does, so the page needs
   neither config.js nor main.js. If the API cannot be reached, it opens an
   email to the academy with the message filled in, as the old page did. */
(function () {
  'use strict';
  var form = document.getElementById('contactForm');
  if (!form) return;
  var $ = function (id) { return document.getElementById(id); };
  var submit = $('cf-submit'), msg = $('cf-msg'), topicEl = $('cf-topic'), tip = $('cf-topic-tip');
  var FALLBACK = 'academy@mamcentre.org';

  /* Most senders are looking for a flow that already exists; say so beside the topic. */
  var TIPS = {
    'Enrollment & Pathways':     "Tip: you can start your enrollment directly at <a href='/apply'>start your application</a>, with auto-save.",
    'MAM Auditer Assessment':    "Tip: the MAM Auditer is automatically included with your application. <a href='/auditer'>Read about it</a>.",
    'MetaMusic Order':           "The MetaMusic Order is a 12-week intensive. <a href='/apply?pathway=meta-order'>Apply</a> or <a href='/metamusic-order'>learn more</a>.",
    'Power Play series':         "Catch the series at <a href='/power-play'>the Power Play page</a>.",
    'Crash Programmes':          "6-week fast-track. <a href='/crash'>See details</a> or <a href='/apply?pathway=crash'>start an application</a>.",
    'Teaching at MAM (Faculty)': "Great — tell us your instrument(s)/discipline and a line about your experience. We'll automatically email you the full instructor application form (details + headshot).",
    'Partnerships & Speaking':   'Briefly include the organisation, timeline and audience size so we can route this to the right team.',
    'Press & Media':             'For press kits and interview requests, please include your outlet and deadline.'
  };
  var showTip = function () {
    var h = TIPS[topicEl.value];
    tip.hidden = !h;
    tip.innerHTML = h || '';
    Array.prototype.forEach.call(tip.querySelectorAll('a'), function (a) { a.className = 'ul'; a.style.color = '#8A5208'; });
  };
  topicEl.addEventListener('change', showTip);
  /* Deep link: ?topic=Faculty preselects the matching option. */
  try {
    var wanted = new URLSearchParams(location.search).get('topic');
    if (wanted) {
      var lw = wanted.toLowerCase();
      Array.prototype.forEach.call(topicEl.options, function (o) { if (o.value && o.value.toLowerCase().indexOf(lw) >= 0) topicEl.value = o.value; });
      showTip();
    }
  } catch (e) { /* old browser */ }

  var setErr = function (id, text) {
    var input = $(id), p = $(id + '-err');
    if (!p) return;
    p.textContent = text || ''; p.hidden = !text;
    if (text) { input.setAttribute('aria-invalid', 'true'); input.style.borderColor = '#B0361C'; input.style.borderWidth = '2px'; }
    else { input.removeAttribute('aria-invalid'); input.style.borderColor = ''; input.style.borderWidth = ''; }
  };
  var validate = function () {
    var errs = [];
    var name = $('cf-name').value.trim(), email = $('cf-email').value.trim(), message = $('cf-message').value.trim();
    var e1 = !name ? 'Enter your name' : (name.length < 2 ? 'Enter your full name' : '');
    var e2 = !email ? 'Email is required' : (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? 'Enter an email address like name@example.com' : '');
    var e3 = !topicEl.value ? 'Pick a topic' : '';
    var e4 = !message ? 'Tell us how we can help' : (message.length < 10 ? 'A little more detail would help' : '');
    [['cf-name', e1], ['cf-email', e2], ['cf-topic', e3], ['cf-message', e4]].forEach(function (p) { setErr(p[0], p[1]); if (p[1]) errs.push(p[0]); });
    if (errs.length) $(errs[0]).focus();
    return !errs.length;
  };

  var label = submit.innerHTML;
  var busy = function (on) { submit.disabled = on; submit.innerHTML = on ? 'Sending…' : label; submit.setAttribute('aria-busy', on ? 'true' : 'false'); };
  var say = function (t, bad) { msg.textContent = t; msg.style.color = bad ? '#B0361C' : '#3E342B'; };

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (form.querySelector('[name=_honeypot]').value) { say("Thanks — we'll be in touch shortly."); return; }
    if (!validate()) return;
    var payload = { name: $('cf-name').value.trim(), email: $('cf-email').value.trim(), phone: $('cf-phone').value.trim(),
                    topic: topicEl.value, message: $('cf-message').value.trim() };
    var mailto = function () {
      var body = 'From: ' + payload.name + ' <' + payload.email + '>\nPhone: ' + (payload.phone || '—') + '\nTopic: ' + payload.topic + '\n\n' + payload.message;
      say('Opening your email…');
      location.href = 'mailto:' + FALLBACK + '?subject=' + encodeURIComponent('MAM Contact — ' + payload.topic) + '&body=' + encodeURIComponent(body);
    };
    busy(true); say('');
    var post = function (token) {
      return fetch('/api/contact', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify(payload) });
    };
    var csrf = function () {
      return fetch('/api/csrf', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { return j && (j.token || (j.data && j.data.token)) || ''; });
    };
    csrf().then(post).then(function (r) {
      /* A stale token is refused once with csrf_invalid: fetch a fresh one and retry. */
      if (r.status !== 403) return r;
      return r.clone().json().catch(function () { return {}; }).then(function (j) {
        return j && j.error === 'csrf_invalid' ? csrf().then(post) : r;
      });
    }).then(function (r) {
      busy(false);
      if (r.ok) { say("Thanks — we'll be in touch shortly."); form.reset(); showTip(); return; }
      if (r.status === 429) { say('Too many submissions — please try later.', true); return; }
      if (r.status === 400 || r.status === 422) { say('Please check the form and try again.', true); return; }
      mailto();
    }).catch(function () { busy(false); mailto(); });
  });
})();
