/*
 * The 2026-11 application (apply.php). One question per screen, in the handoff's
 * own markup and style strings (Apply.dc.html: tileStyle, dot, the field and
 * error treatments), driven by plain DOM.
 *
 * What it adds to the design, and why — the server's mam_validate_application()
 * needs each of these, and an application the server refuses is worse than a
 * longer one:
 *   dob      instead of an age band: age, the KTAMS track and the guardian rule
 *            are all derived from it server-side; the band the design showed is
 *            derived from it here, so its "great first instrument" and
 *            recommendation still work.
 *   sex, style (expression), focus (program), level, address/state/LGA,
 *   the guardian's own email (their portal account is made from it), and a
 *   typed signature on the class policies (it must match the guardian's name
 *   for an under-16).
 *
 * Behaviour the handoff specifies and this keeps: the heading takes focus on every
 * screen, Enter continues, errors are summarised at the top AND said beside the
 * field, a likely email typo is offered back, answers are saved on this device
 * (localStorage "mam-apply-v1") with Welcome back / Start over, Change on the
 * review returns there with "Save and return".
 *
 * Money: the estimate uses window.MAM_SITE.fees (the server's figures). The only
 * charge here is the $5 registration, through the existing booking-fee payment.
 */
(function () {
  'use strict';
  var F = (window.MAM_SITE && window.MAM_SITE.fees) || null;
  var root = document.querySelector('[data-ap="screen"]');
  if (!root || !F) return;
  var $ = function (k) { return document.querySelector('[data-ap="' + k + '"]'); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var KEY = 'mam-apply-v1';
  var CFG = window.MAM_CONFIG || {};

  /* ---------- the questions ---------- */
  var PROGS = [
    { k: 'standard', srv: 'standard', l: 'Standard', d: 'Your main instrument, graded by level.', unit: 'a term', levelled: true },
    { k: 'certified', srv: 'certified-musician', l: 'Certified Musician', d: 'Instrument plus theory, aural and the certification track.', unit: 'a term', levelled: true, pop: true },
    { k: 'grand', srv: 'grand-musician', l: 'Grand Musician', d: 'Three disciplines together, ' + pct(F.grandPct) + ' saving.', unit: 'a term', levelled: true },
    { k: 'crash', srv: 'crash', l: 'Crash Programme', d: 'Six-week intensive in any discipline.', unit: 'flat' },
    { k: 'meta', srv: 'meta-order', l: 'The MetaMusic Order', d: 'Twelve weeks, post-diploma.', unit: 'flat' }
  ];
  var INSTR = [['Piano & Keyboard', 'piano'], ['Voice & Choir', 'voice'], ['Violin & Strings', 'violin'], ['Guitar', 'guitar'], ['Drums & Percussion', 'drums'],
    ['Wind & Brass', 'wind'], ['Music Theory & Ear', 'theory'], ['Performance', 'performance'], ['Help me choose', 'undecided']];
  var WIND = [['Saxophone', 'saxophone'], ['Trumpet', 'trumpet'], ['Flute', 'flute'], ['Clarinet', 'clarinet']];
  var FIRST_INSTR = ['voice', 'piano', 'drums'];
  var STYLES = [['classical', 'Classical', 'Western art music, from Bach to today'], ['contemporary', 'Contemporary', 'Pop, gospel, jazz and modern worship'], ['african', 'African', 'Music of the continent, its rhythms and forms']];
  var FOCUS = [['perform', 'Performing', 'On stage, in the style you chose'], ['songwriting', 'Songwriting & Composition', 'Writing the material itself'], ['music-production', 'Music Production', 'From idea to finished record'],
    ['audio-engineering', 'Audio Engineering', 'Capture, mixing and the room'], ['arranging', 'Arranging', 'Shaping music for an ensemble'], ['orchestration', 'Orchestration', 'Writing for the orchestra'],
    ['film-scoring', 'Film Scoring', 'Music for picture'], ['conducting', 'Conducting', 'Leading an ensemble'], ['music-direction', 'Music Direction', 'Leading a band or a church'],
    ['artistic-direction', 'Artistic Direction', 'Shaping a production or a season'], ['music-education', 'Music Education', 'Teaching music to others'], ['music-business', 'Music Business', 'Careers, rights and the industry']];
  var LEVELS = F.levels.map(function (l) { return [l.id, l.name, 'Grades ' + l.grades]; });
  var DOM = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'live.com', 'ymail.com'];

  var blank = function () {
    return { who: '', first: '', last: '', dob: '', sex: '', instrument: '', wind: '', prog: '', level: '', style: '', focus: '', mode: '',
      gname: '', gemail: '', email: '', phone: '', learnerEmail: '', address: '', state: '', lga: '', country: 'Nigeria',
      pay: 'card', terms: false, sign: '' };
  };
  var s = { step: 'who', f: blank(), err: {}, back2review: false, restored: false, ref: '', busy: false, payNote: '' };

  /* ---------- derived facts ---------- */
  function age() {
    var d = s.f.dob; if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return null;
    var b = new Date(d + 'T00:00:00'), n = new Date(), a = n.getFullYear() - b.getFullYear();
    if (n.getMonth() < b.getMonth() || (n.getMonth() === b.getMonth() && n.getDate() < b.getDate())) a--;
    return b > n ? null : a;
  }
  function band() { var a = age(); return a == null ? '' : a <= 7 ? '4–7' : a <= 12 ? '8–12' : a <= 17 ? '13–17' : '18+'; }
  function child() { return s.f.who === 'child'; }
  function needsGuardian() { var a = age(); return child() || (a != null && a < 16); }
  function under16() { var a = age(); return a != null && a < 16; }
  function prog() { return PROGS.filter(function (p) { return p.k === s.f.prog; })[0] || null; }
  function flat() { var p = prog(); return !!p && p.unit === 'flat'; }
  function first() { return s.f.first.trim(); }
  function them() { return child() ? (first() || 'your child') : 'you'; }

  /* The screens that apply to these answers, in order. */
  function order() {
    var p = prog(), o = ['who', 'name', 'dob', 'sex', 'instrument', 'prog'];
    if (!p || p.levelled) o.push('level');
    if (!p || (p.k !== 'grand' && p.k !== 'meta')) o.push('style');
    o.push('focus', 'mode', 'contact', 'address', 'review', 'pay', 'done');
    return o;
  }
  var SECTIONS = [['About the learner', ['who', 'name', 'dob', 'sex']], ['The course', ['instrument', 'prog', 'level', 'style', 'focus', 'mode']], ['Contact', ['contact', 'address']], ['Review and pay', ['review', 'pay']]];

  /* ---------- money, from the server's figures ---------- */
  function pct(n) { return (Math.round(n * 10) / 10) + '%'; }
  function money(n) { return '$' + Math.round(n).toLocaleString('en-US'); }
  function fee(p, online, levelIdx) {
    var B = F.levels[levelIdx || 0].base, sub;
    if (p.k === 'crash') return F.crash;
    if (p.k === 'meta') return F.meta;
    sub = p.k === 'standard' ? B : p.k === 'certified' ? B + F.compulsory : 3 * B * (1 - F.grandPct / 100);
    return online ? sub * (1 + F.onlinePct / 100) : sub;
  }
  function levelIdx() { var i = F.levels.map(function (l) { return l.id; }).indexOf(s.f.level); return i < 0 ? 0 : i; }

  /* ---------- the design's style strings ---------- */
  var tileStyle = function (on) { return 'display: flex; flex-direction: column; align-items: flex-start; gap: 6px; min-height: 88px; padding: 18px 20px; border-radius: 16px; text-align: left; font: inherit; color: #211A13; cursor: pointer; background: ' + (on ? '#FFFFFF' : 'rgba(255,255,255,.55)') + '; border: 1.5px solid ' + (on ? '#211A13' : '#E3DACD') + '; box-shadow: ' + (on ? '0 0 0 1px #211A13, 0 18px 40px -28px rgba(33,26,19,.5)' : 'none'); };
  var dot = function (on) { return 'flex: none; width: 20px; height: 20px; border-radius: 50%; box-sizing: border-box; border: ' + (on ? '6px solid #211A13' : '1.5px solid #B9AC9B') + '; background: #FFFFFF; transition: border .2s ease'; };
  var INPUT = 'width: 100%; box-sizing: border-box; min-height: 56px; padding: 0 18px; border-radius: 12px; border: 1.5px solid #CFC3B3; background: #FFFFFF; font: inherit; font-size: 17px; color: #211A13; ';
  var ERR_BORDER = 'border-color: #B0361C; border-width: 2px';
  var CAP = 'margin: 0; font-size: 14px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208';
  var H1 = 'margin: -12px 0 0; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 500; font-size: clamp(36px, 4vw, 56px); line-height: 1.05; outline: none';
  var LEDE = 'margin: -8px 0 0; font-size: 17px; color: #574B40';

  function tiles(field, label, opts, min) {
    return '<div role="radiogroup" aria-label="' + esc(label) + '" aria-describedby="tile-err" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(' + min + 'px, 1fr)); gap: 10px">'
      + opts.map(function (o) {
        var on = s.f[field] === o.v;
        return '<button type="button" role="radio" aria-checked="' + on + '" tabindex="' + (on || (!s.f[field] && o === opts[0]) ? 0 : -1) + '" data-pick="' + field + '" data-v="' + esc(o.v) + '" class="tile" style="' + tileStyle(on) + '">'
          + '<span style="display: flex; justify-content: space-between; align-items: center; gap: 12px; width: 100%"><span style="font-family: \'Cormorant Garamond\', Georgia, serif; font-size: 24px; font-weight: 600; line-height: 1.1">' + esc(o.l) + '</span><span aria-hidden="true" style="' + dot(on) + '"></span></span>'
          + (o.d ? '<span style="font-size: 14px; color: #574B40; text-align: left">' + esc(o.d) + '</span>' : '')
          + (o.price ? '<span style="margin-top: 4px; font-size: 14px; font-weight: 600; color: #8A5208">' + esc(o.price) + '</span>' : '')
          + '</button>';
      }).join('') + '</div>'
      + '<span id="tile-err" role="alert" style="font-size: 15px; font-weight: 600; color: #B0361C">' + esc(s.err.tile || '') + '</span>';
  }
  function field(id, label, hint, type, auto, extra) {
    var e = s.err[id];
    return '<div style="display: flex; flex-direction: column; gap: 8px"><label for="' + id + '" style="font-weight: 600">' + esc(label)
      + (hint ? '<span style="display: block; font-size: 14px; color: #574B40; margin-top: 2px">' + esc(hint) + '</span>' : '') + '</label>'
      + '<span id="' + id + '-err" style="font-size: 15px; font-weight: 600; color: #B0361C">' + esc(e || '') + '</span>'
      + '<input id="' + id + '" data-f="' + id + '" type="' + type + '" autocomplete="' + auto + '" value="' + esc(s.f[id]) + '" aria-invalid="' + (e ? 'true' : 'false') + '" aria-describedby="' + id + '-err" ' + (extra || '') + ' style="' + INPUT + (e ? ERR_BORDER : '') + '"></div>';
  }
  function head(cap, q, lede) {
    return '<p style="' + CAP + '">' + esc(cap) + '</p><h1 tabindex="-1" data-ap="h1" style="' + H1 + '">' + esc(q) + '</h1>' + (lede ? '<p style="' + LEDE + '">' + lede + '</p>' : '');
  }

  /* ---------- the screens ---------- */
  function screen(k) {
    var learnerCap = child() ? 'About your child' : 'About you', a = age(), b = band(), p = prog();
    switch (k) {
      case 'who': return head('Getting started', 'Who is this application for?', 'You can apply for yourself, or as a parent or guardian.')
        + tiles('who', 'Applying for', [{ v: 'self', l: 'Myself', d: 'I am the learner' }, { v: 'child', l: 'My child', d: 'I am a parent or guardian' }], 220);
      case 'name': return head(learnerCap, child() ? 'What is your child’s name?' : 'What is your name?')
        + '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">' + field('first', 'First name', '', 'text', 'given-name') + field('last', 'Last name', '', 'text', 'family-name') + '</div>';
      case 'dob': return head(learnerCap, child() ? 'When was ' + them() + ' born?' : 'When were you born?', 'This places ' + esc(them()) + ' in the right class, and tells us whether a parent or guardian signs.')
        + field('dob', 'Date of birth', 'For example 2015-03-24', 'date', 'bday', 'max="' + new Date().toISOString().slice(0, 10) + '"');
      case 'sex': return head(learnerCap, child() ? 'Is ' + them() + ' a girl or a boy?' : 'Which describes you?', 'We ask so classes and changing arrangements are right for everyone.')
        + tiles('sex', 'Sex', [{ v: 'female', l: 'Female' }, { v: 'male', l: 'Male' }, { v: 'prefer-not-to-say', l: 'Prefer not to say' }], 180);
      case 'instrument': return head('The course', child() ? 'Which instrument would ' + them() + ' like to learn?' : 'Which instrument would you like to learn?', 'Not sure? Choose “Help me choose” and we will talk it through at the assessment.')
        + tiles('instrument', 'Instrument', INSTR.map(function (i) { return { v: i[1], l: i[0], d: b === '4–7' && FIRST_INSTR.indexOf(i[1]) >= 0 ? 'Great first instrument' : '' }; }), 190)
        + (s.f.instrument === 'wind' ? '<p style="margin: 8px 0 0; font-weight: 600">Which one?</p>' + tiles('wind', 'Wind or brass instrument', WIND.map(function (w) { return { v: w[1], l: w[0] }; }), 150).replace('id="tile-err"', 'id="wind-err"') : '');
      case 'prog':
        var rec = b ? (b === '4–7' ? 'standard' : 'certified') : '';
        return head('The course', 'Choose a programme', 'Prices are per term at the starting level. Your exact level is set by the assessment.')
          + tiles('prog', 'Programme', PROGS.map(function (q) {
            var price = (q.unit === 'flat' ? money(fee(q, false)) + ' flat' : 'from ' + money(fee(q, false)) + ' a term')
              + (rec === q.k ? ' · Recommended for ' + b : (!rec && q.pop ? ' · Most chosen' : ''));
            return { v: q.k, l: q.l, d: q.d, price: price };
          }), 250);
      case 'level': return head('The course', 'Where would ' + them() + ' start?', 'Choose the nearest. The MAM Auditer assessment confirms it, and the fee follows the level it sets.')
        + tiles('level', 'Starting level', LEVELS.map(function (l) { return { v: l[0], l: l[1], d: l[2] }; }).concat([{ v: 'unsure', l: 'Not sure yet', d: 'Start at Pre-Certificate; the assessment will place me' }]), 200);
      case 'style': return head('The course', 'Which style of music?', p && p.k === 'crash' ? 'The six weeks are taught in one style.' : 'Every programme is taught in one of three expressions.')
        + tiles('style', 'Style', STYLES.map(function (x) { return { v: x[0], l: x[1], d: x[2] }; }), 200);
      case 'focus': return head('The course', 'Where is ' + (child() ? them() : 'your music') + ' heading?', 'Your focus shapes the courses beside your instrument. You can change it later.')
        + tiles('focus', 'Focus area', FOCUS.filter(function (x) { return !(x[0] === 'perform' && p && p.k === 'meta'); }).map(function (x) { return { v: x[0], l: x[1], d: x[2] }; }), 220);
      case 'mode': return head('The course', child() ? 'How would ' + them() + ' like to learn?' : 'How would you like to learn?')
        + tiles('mode', 'Mode', [{ v: 'onsite', l: 'In person', d: b === '4–7' ? 'Best for early years — at our studios.' : 'At our studios, in small groups.' },
          { v: 'online', l: 'Online', d: flat() ? 'Live classes. Same price.' : 'Live classes, anywhere. +' + pct(F.onlinePct) }], 240);
      case 'contact':
        var g = needsGuardian(), sug = suggest(g ? 'gemail' : 'email');
        return head('Contact', g ? 'Your contact details' : 'How can we reach you?', g ? (child() ? '' : 'Because you are under 16, a parent or guardian applies with you. ') + 'We will only use these to send your confirmation and book the assessment.' : 'We will only use these to send your confirmation and book the assessment.')
          + '<div style="display: flex; flex-direction: column; gap: 18px">'
          + (g ? field('gname', child() ? 'Your full name' : 'Your parent or guardian’s full name', 'Parent or guardian', 'text', 'name')
            + field('gemail', child() ? 'Your email address' : 'Their email address', 'They get their own account to follow progress', 'email', 'email')
            + suggestLine('gemail', sug)
            + field('phone', child() ? 'Your phone number' : 'Their phone number', 'Include the country code, like +234', 'tel', 'tel')
            + field('learnerEmail', (child() ? first() || 'Your child' : 'Your') + (child() ? '’s email address' : ' own email address'), 'Optional. Leave blank to use the address above', 'email', 'off')
          : field('email', 'Email address', '', 'email', 'email')
            + suggestLine('email', sug)
            + field('phone', 'Phone number', 'Include your country code, like +234', 'tel', 'tel'))
          + '</div>';
      case 'address': return head('Contact', child() ? 'Where does ' + them() + ' live?' : 'Where do you live?', 'For your records and, for in-person study, the studio nearest you.')
        + '<div style="display: flex; flex-direction: column; gap: 18px">' + field('address', 'Street address', '', 'text', 'street-address')
        + '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px">' + field('state', 'State', 'Or region, outside Nigeria', 'text', 'address-level1') + field('lga', 'Local government area', 'Or city, outside Nigeria', 'text', 'address-level2') + '</div>'
        + field('country', 'Country', '', 'text', 'country-name') + '</div>';
      case 'review': return head('Nearly done', 'Check your answers')
        + '<dl style="margin: 0; border-top: 1px solid #E3DACD">' + reviewRows().map(function (r) {
          return '<div style="display: grid; grid-template-columns: minmax(110px, 1fr) 2fr auto; gap: 12px; align-items: baseline; padding: 16px 0; border-bottom: 1px solid #E3DACD"><dt style="font-size: 15px; color: #574B40">' + esc(r[0]) + '</dt><dd style="margin: 0; font-weight: 600; overflow-wrap: anywhere">' + esc(r[1] || '—') + '</dd><dd style="margin: 0"><button type="button" data-change="' + r[2] + '" class="ul" style="min-height: 44px; padding: 0; border: 0; background: none; font: inherit; font-size: 15px; color: #8A5208; cursor: pointer">Change<span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)"> ' + esc(r[0]) + '</span></button></dd></div>';
        }).join('') + '</dl>';
      case 'pay':
        var methods = [{ v: 'card', l: 'Card', d: 'Visa, Mastercard, Verve — via Paystack' }, { v: 'transfer', l: 'Bank transfer', d: 'Details sent by email' }]
          .filter(function (m) { var pm = (CFG.paymentMethods || {})[m.v === 'card' ? 'paystack' : 'bank']; return !pm || pm.enabled !== false; });
        var signer = needsGuardian() ? 'the parent or guardian' : 'you';
        return head('Final step', 'Pay the ' + money(F.registration) + ' registration fee', 'Tuition is paid later, once your level and plan are confirmed.')
          + tiles('pay', 'Payment method', methods, 220)
          + '<label style="display: flex; gap: 14px; align-items: flex-start; font-size: 16px; cursor: pointer; padding: 18px; border-radius: 14px; background: #FFFFFF; border: 1.5px solid ' + (s.err.terms ? '#B0361C' : '#E3DACD') + '"><input type="checkbox" data-f="terms"' + (s.f.terms ? ' checked' : '') + ' aria-invalid="' + (s.err.terms ? 'true' : 'false') + '" aria-describedby="terms-err" style="width: 22px; height: 22px; margin: 1px 0 0; accent-color: #211A13; flex: none"><span>I agree to the <a href="/terms" style="color: #8A5208">terms</a> and <a href="/policies" style="color: #8A5208">class policies</a> for ' + (s.f.mode === 'online' ? 'online' : 'in-person') + ' study, and the details are correct.<span id="terms-err" style="display: block; font-size: 15px; font-weight: 600; color: #B0361C">' + esc(s.err.terms || '') + '</span></span></label>'
          + field('sign', 'Type your full name to sign', 'Signed by ' + signer + (needsGuardian() && s.f.gname ? ': ' + s.f.gname : ''), 'text', 'name')
          + (s.payNote ? '<p role="status" style="margin: 0; font-size: 16px">' + esc(s.payNote) + '</p>' : '');
      case 'done': return '<div style="display: flex; flex-direction: column; gap: 28px"><div style="padding: 32px; border-radius: 22px; background: #211A13; color: #FCF8F3; display: flex; flex-direction: column; gap: 10px">'
        + '<svg aria-hidden="true" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#F2A93B" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M7.5 12.5l3 3 6-6.5"></path></svg>'
        + '<h1 tabindex="-1" data-ap="h1" style="margin: 4px 0 0; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 500; font-size: clamp(38px, 4vw, 56px); line-height: 1.05; outline: none">Application received</h1>'
        + '<p style="margin: 0; font-size: 17px; color: #D8CEC1">Welcome to MAM, ' + esc(first() || 'friend') + '. Your reference is <strong style="color: #F2A93B; letter-spacing: .04em">' + esc(s.ref) + '</strong>. A confirmation is on its way to ' + esc(contactEmail()) + '.</p>'
        + (s.payNote ? '<p style="margin: 0; font-size: 15px; color: #D8CEC1">' + esc(s.payNote) + '</p>' : '') + '</div>'
        + '<h2 style="margin: 8px 0 0; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 500; font-size: 34px">What happens next</h2><ol style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column">'
        + [['1', 'Check your email', 'Your confirmation and receipt arrive within a few minutes.'], ['2', 'Book your MAM Auditer assessment', 'About 30 minutes, online or in person. It sets ' + (child() ? them() + '’s' : 'your') + ' level.'], ['3', 'Meet your instructor', 'You receive a plan, a timetable and your first lesson date.']]
          .map(function (n) { return '<li style="display: flex; gap: 18px; padding: 18px 0; border-top: 1px solid #E3DACD"><span aria-hidden="true" style="flex: none; font-family: \'Cormorant Garamond\', Georgia, serif; font-size: 28px; color: #C27A12; width: 28px">' + n[0] + '</span><div><p style="margin: 0; font-weight: 600">' + esc(n[1]) + '</p><p style="margin: 2px 0 0; font-size: 15px; color: #574B40">' + esc(n[2]) + '</p></div></li>'; }).join('')
        + '</ol><div style="display: flex; gap: 10px; flex-wrap: wrap"><a href="/book" class="btn" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 24px; border-radius: 999px; background: #211A13; color: #FAF8F4; font-weight: 600; text-decoration: none">Book my assessment <span aria-hidden="true">→</span></a><a href="/status?ref=' + encodeURIComponent(s.ref) + '" class="btn" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 24px; border-radius: 999px; border: 1px solid #CFC3B3; color: #211A13; font-weight: 600; text-decoration: none">Track my application</a></div></div>';
    }
    return '';
  }
  function label(list, v) { var x = list.filter(function (i) { return i[0] === v || i[1] === v; })[0]; return x ? (x[0] === v ? x[1] : x[0]) : ''; }
  function instrLabel() { return s.f.instrument === 'wind' ? label(WIND, s.f.wind) : label(INSTR, s.f.instrument); }
  function contactEmail() { return (needsGuardian() ? s.f.gemail : s.f.email).trim(); }
  function reviewRows() {
    var p = prog(), rows = [
      ['Applying for', child() ? 'My child' : 'Myself', 'who'], ['Name', (first() + ' ' + s.f.last.trim()).trim(), 'name'],
      ['Date of birth', s.f.dob, 'dob'], ['Sex', label([['female', 'Female'], ['male', 'Male'], ['prefer-not-to-say', 'Prefer not to say']], s.f.sex), 'sex'],
      ['Instrument', instrLabel(), 'instrument'], ['Programme', p ? p.l : '', 'prog']];
    if (order().indexOf('level') >= 0) rows.push(['Starting level', s.f.level === 'unsure' ? 'Not sure yet (Pre-Certificate)' : label(LEVELS, s.f.level), 'level']);
    if (order().indexOf('style') >= 0) rows.push(['Style', label(STYLES, s.f.style), 'style']);
    rows.push(['Focus', label(FOCUS, s.f.focus), 'focus'], ['Mode', s.f.mode ? (s.f.mode === 'online' ? 'Online' : 'In person') : '', 'mode']);
    rows.push(['Contact', [needsGuardian() ? s.f.gname : '', contactEmail(), s.f.phone].filter(Boolean).join(' · '), 'contact']);
    rows.push(['Address', [s.f.address, s.f.lga, s.f.state, s.f.country].filter(Boolean).join(', '), 'address']);
    return rows;
  }
  /* The typo offer is always in the markup (empty when there is nothing to offer),
     so it can appear while the address is being typed — the moment it helps. */
  function suggestLine(k, sug) {
    return '<p data-suggest-line="' + k + '" role="status" style="margin: -8px 0 0; font-size: 15px"' + (sug ? '' : ' hidden') + '>Did you mean <button type="button" data-suggest="' + k + '" class="ul" style="padding: 0; border: 0; background: none; font: inherit; font-weight: 600; color: #8A5208; cursor: pointer">' + esc(sug) + '</button>?</p>';
  }
  function suggest(k) {
    var em = (s.f[k] || '').trim().toLowerCase(), at = em.lastIndexOf('@'); if (at <= 0) return '';
    var dom = em.slice(at + 1); if (!dom || DOM.indexOf(dom) >= 0) return '';
    var lev = function (x, y) { var m = [], i, j; for (i = 0; i <= x.length; i++) m[i] = [i]; for (j = 1; j <= y.length; j++) m[0][j] = j; for (i = 1; i <= x.length; i++) for (j = 1; j <= y.length; j++) m[i][j] = Math.min(m[i - 1][j] + 1, m[i][j - 1] + 1, m[i - 1][j - 1] + (x[i - 1] === y[j - 1] ? 0 : 1)); return m[x.length][y.length]; };
    var best = DOM.map(function (d) { return [d, lev(dom, d)]; }).sort(function (p, q) { return p[1] - q[1]; })[0];
    return best[1] > 0 && best[1] <= 2 ? em.slice(0, at + 1) + best[0] : '';
  }

  /* ---------- validation: what to do, said plainly ---------- */
  function validate(k) {
    var f = s.f, e = {}, mail = /^[^@\s]+@[^@\s]+\.[^@\s]+$/, need = { who: 'Choose who the application is for', sex: 'Choose one', instrument: 'Choose an instrument, or “Help me choose”',
      prog: 'Choose a programme', level: 'Choose a starting level, or “Not sure yet”', style: 'Choose a style', focus: 'Choose a focus', mode: 'Choose how to learn', pay: 'Choose how to pay' };
    if (need[k] && !f[k]) e.tile = need[k];
    if (k === 'instrument' && f.instrument === 'wind' && !f.wind) e.tile = 'Choose which wind or brass instrument';
    if (k === 'name') { if (!f.first.trim()) e.first = 'Enter a first name'; if (!f.last.trim()) e.last = 'Enter a last name'; }
    if (k === 'dob') { var a = age(); if (a == null) e.dob = 'Enter a date of birth, like 2015-03-24'; else if (a < 3) e.dob = 'Learners start from age 4'; else if (a > 110) e.dob = 'Check the year'; }
    if (k === 'contact') {
      if (needsGuardian()) {
        if (!f.gname.trim()) e.gname = 'Enter the parent or guardian’s full name';
        if (!mail.test(f.gemail.trim())) e.gemail = 'Enter an email address like name@example.com';
        if (f.learnerEmail.trim() && !mail.test(f.learnerEmail.trim())) e.learnerEmail = 'Enter an email address like name@example.com, or leave it blank';
      } else if (!mail.test(f.email.trim())) e.email = 'Enter an email address like name@example.com';
      if ((f.phone.match(/\d/g) || []).length < 7) e.phone = 'Enter a phone number with at least 7 digits';
    }
    if (k === 'address') { if (f.address.trim().length < 3) e.address = 'Enter a street address'; if (!f.state.trim()) e.state = 'Enter a state or region'; if (!f.lga.trim()) e.lga = 'Enter a local government area or city'; }
    if (k === 'pay') {
      if (!f.terms) e.terms = 'Tick the box to agree to the terms and class policies';
      if (!f.sign.trim()) e.sign = 'Type your full name to sign';
      else if (under16() && f.sign.trim().toLowerCase() !== f.gname.trim().toLowerCase()) e.sign = 'The parent or guardian signs: type ' + f.gname.trim() + ' exactly as above';
    }
    return e;
  }

  /* ---------- painting ---------- */
  function paint(focus) {
    var o = order(), k = s.step, i = o.indexOf(k), keys = Object.keys(s.err);
    var html = '';
    if (keys.length) html += '<div role="alert" style="padding: 18px 20px; border-radius: 12px; border: 2px solid #B0361C; background: #FFFFFF"><p style="margin: 0; font-weight: 600; color: #B0361C">There is a problem</p><p style="margin: 4px 0 0; font-size: 15px">' + esc(keys.map(function (x) { return s.err[x]; }).join('. ')) + '</p></div>';
    html += '<div class="qin" style="display: flex; flex-direction: column; gap: 24px">' + screen(k) + '</div>';
    if (k !== 'done') {
      var cont = k === 'review' ? 'Confirm and continue' : k === 'pay' ? (s.busy ? 'Working…' : 'Pay ' + money(F.registration) + ' and submit') : s.back2review ? 'Save and return' : 'Continue';
      html += '<div style="display: flex; align-items: center; gap: 18px; flex-wrap: wrap; margin-top: 8px"><button type="button" data-ap="next" class="btn"' + (s.busy ? ' aria-disabled="true"' : '') + ' style="min-height: 56px; padding: 0 30px; border-radius: 999px; border: 0; background: #211A13; color: #FAF8F4; font: inherit; font-size: 17px; font-weight: 600; cursor: pointer">' + cont + '</button><span class="hide-sm" style="font-size: 14px; color: #574B40">or press <kbd style="font: inherit; padding: 2px 8px; border-radius: 6px; border: 1px solid #D6CCBE; background: #FFFFFF">Enter ↵</kbd></span></div>';
    }
    root.innerHTML = html;
    // aside, counter, bar
    var qs = o.slice(0, o.indexOf('review')), n = qs.indexOf(k);
    $('counter').textContent = n >= 0 ? 'Question ' + (n + 1) + ' of ' + qs.length : (k === 'review' ? 'Check answers' : k === 'pay' ? 'Final step' : 'Complete');
    $('bar').style.width = Math.round(i / (o.length - 1) * 100) + '%';
    $('back').hidden = !(i > 0 && k !== 'done');
    var cur = SECTIONS.map(function (x) { return x[1].indexOf(k) >= 0; }).indexOf(true); if (k === 'done') cur = 4;
    $('sections').innerHTML = SECTIONS.map(function (sec, si) {
      var done = si < cur, on = si === cur;
      var vals = si === 0 ? [s.f.who ? (child() ? 'For my child' : 'For myself') : '', (first() + ' ' + s.f.last.trim()).trim()] : si === 1 ? [instrLabel(), prog() ? prog().l : '', s.f.mode ? (s.f.mode === 'online' ? 'Online' : 'In person') : ''] : si === 2 ? [contactEmail(), s.f.phone] : [];
      var sum = done ? (vals.filter(Boolean).join(' · ') || 'Done') : on ? (k === 'review' ? 'Check your answers' : k === 'pay' ? 'Registration fee' : 'In progress') : '';
      var dotSt = 'flex: none; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; ' + (done ? 'background: #F2A93B; color: #211A13' : on ? 'border: 1.5px solid #F2A93B; color: #F2A93B' : 'border: 1px solid rgba(252,248,243,.25); color: rgba(252,248,243,.5)');
      return '<li aria-current="' + (on ? 'step' : 'false') + '" style="display: flex; gap: 16px; padding: 16px 0; border-top: 1px solid rgba(252,248,243,.12)"><span aria-hidden="true" style="' + dotSt + '">' + (done ? '✓' : si + 1) + '</span><div style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px"><div style="display: flex; justify-content: space-between; align-items: center; gap: 12px"><span style="font-weight: 600; font-size: 16px; color: ' + (on || done ? '#FCF8F3' : 'rgba(252,248,243,.5)') + '">' + sec[0] + '</span>'
        + (done && k !== 'done' && si < 3 ? '<button type="button" data-change="' + sec[1][0] + '" class="ul" style="min-height: 32px; padding: 0; border: 0; background: none; font: inherit; font-size: 14px; color: #F2A93B; cursor: pointer">Edit<span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)"> ' + sec[0] + '</span></button>' : '')
        + '</div><span style="font-size: 14px; color: #B8AC9E; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">' + esc(sum) + '</span></div></li>';
    }).join('');
    var p = prog(), online = s.f.mode === 'online';
    var feeText = p ? money(fee(p, online && !flat(), levelIdx())) : '—';
    $('fee').textContent = feeText; $('mob-fee').textContent = feeText;
    $('fee-unit').textContent = p ? ' ' + p.unit : '';
    $('fee-note').textContent = p ? (flat() ? 'One-off fee.' : 'Starting level; final after the assessment.') : 'Choose a programme to see the fee.';
    $('mob-summary').textContent = p ? p.l + (s.f.mode ? ' · ' + (online ? 'Online' : 'In person') : '') : 'Your application';
    // The line is the design's: an icon, the words, then "· Start over" — the words are its one text node.
    var line = $('saved'), tn = Array.prototype.filter.call(line.childNodes, function (n) { return n.nodeType === 3 && n.textContent.trim(); })[0];
    if (tn) tn.textContent = s.restored ? 'Welcome back — we kept your answers' : 'Progress saved on this device';
    $('reset-wrap').hidden = !(s.f.who || s.f.first) || k === 'done';
    if (focus) { var h = root.querySelector('[data-ap="h1"]'); if (h) h.focus(); window.scrollTo(0, 0); }
  }

  /* ---------- saving on this device ---------- */
  function persist() {
    try {
      if (s.step === 'done') localStorage.removeItem(KEY);
      else { var f = JSON.parse(JSON.stringify(s.f)); f.terms = false; f.sign = ''; localStorage.setItem(KEY, JSON.stringify({ f: f, step: s.step, at: Date.now() })); }
    } catch (e) { /* private mode: answers last for this visit */ }
  }
  try {
    var raw = localStorage.getItem(KEY), d = raw && JSON.parse(raw);
    if (d && d.f && d.step && d.step !== 'done') { s.f = Object.assign(blank(), d.f, { terms: false, sign: '' }); s.step = order().indexOf(d.step) >= 0 ? d.step : 'who'; s.restored = true; }
  } catch (e) { /* a broken save starts afresh */ }
  // Arriving from the fee estimator (?pathway=…&mode=…&level=…) answers those questions.
  try {
    var qs = new URLSearchParams(location.search), pw = qs.get('pathway') || qs.get('programme');
    var pk = PROGS.filter(function (p) { return p.srv === pw; })[0];
    if (pk && !s.restored) s.f.prog = pk.k;
    if (/^(online|onsite)$/.test(qs.get('mode') || '') && !s.restored) s.f.mode = qs.get('mode');
    if (F.levels.some(function (l) { return l.id === qs.get('level'); }) && !s.restored) s.f.level = qs.get('level');
    var ref = qs.get('ref');
    if (ref && window.MAM_API && sessionStorage.getItem('mam-ref-captured') !== ref) {
      sessionStorage.setItem('mam-ref-captured', ref);
      window.MAM_API.request('referral/capture', { method: 'POST', body: { code: ref } }).catch(function () {});
    }
  } catch (e) { /* a missing parameter is not an error */ }

  /* ---------- moving ---------- */
  function go(k) { s.step = k; s.err = {}; s.payNote = ''; persist(); paint(true); }
  function next() {
    if (s.busy) return;
    var e = validate(s.step);
    if (Object.keys(e).length) { s.err = e; paint(false); var first = root.querySelector('[aria-invalid="true"], [role="radio"]'); var al = root.querySelector('[role="alert"]'); if (al) al.scrollIntoView({ block: 'center' }); if (first && first.focus) first.focus(); return; }
    if (s.step === 'pay') { submit(); return; }
    var o = order(), to = s.back2review && s.step !== 'review' ? 'review' : o[o.indexOf(s.step) + 1];
    s.back2review = false; go(to);
  }
  function back() { var o = order(), i = o.indexOf(s.step); s.back2review = false; if (i > 0) go(o[i - 1]); }

  /* ---------- submitting: the application, then the $5 ---------- */
  function payload() {
    var f = s.f, g = needsGuardian(), p = prog(), discipline = f.instrument === 'wind' ? f.wind : f.instrument;
    var program = f.focus === 'perform' ? (p.k === 'grand' ? 'grand' : f.style) : f.focus;
    var email = g ? (f.learnerEmail.trim() || f.gemail.trim()) : f.email.trim();
    return {
      firstName: f.first.trim(), lastName: f.last.trim(), email: email, phone: f.phone.trim(), sex: f.sex, dob: f.dob,
      pathway: p.srv, mode: f.mode, level: p.levelled ? (f.level === 'unsure' ? F.levels[0].id : f.level) : '',
      expression: p.k === 'grand' ? 'all' : (p.k === 'meta' ? '' : f.style), program: program, discipline: discipline,
      extraCourses: 0, address: f.address.trim(), lga: f.lga.trim(), state: f.state.trim(), country: f.country.trim(),
      experience: f.level === 'unsure' ? 'unsure' : '', parentName: g ? f.gname.trim() : '', parentPhone: g ? f.phone.trim() : '',
      parentEmail: g ? f.gemail.trim() : '', auditer: true, includeRegistration: true, includeCertification: false,
      policyMode: f.mode, policyAgreed: !!f.terms, policySignedName: f.sign.trim()
    };
  }
  var SERVER_SCREEN = { firstName: 'name', lastName: 'name', dob: 'dob', sex: 'sex', discipline: 'instrument', pathway: 'prog', level: 'level', expression: 'style', program: 'focus',
    mode: 'mode', email: 'contact', phone: 'contact', parentName: 'contact', parentEmail: 'contact', address: 'address', lga: 'address', state: 'address', policyAgreed: 'pay', policySignedName: 'pay' };
  function submit() {
    var api = window.MAM_API;
    if (!api) { s.payNote = 'The form could not reach us. Please check your connection and try again.'; paint(false); return; }
    s.busy = true; s.payNote = ''; paint(false);
    if (!s.idem) s.idem = 'MAMK-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    api.submitApplication(payload(), s.idem).then(function (res) {
      s.ref = res.reference || (res.data && res.data.reference) || '';
      var provider = s.f.pay === 'transfer' ? 'bank' : 'paystack';
      return api.initializePayment({ reference: s.ref, provider: provider, purpose: 'booking_fee' }).then(function (init) {
        if (init.already_paid || init.already_valid) { s.payNote = init.message || 'No payment was needed.'; return finish(); }
        if (provider === 'bank') { s.payNote = 'Bank transfer: ' + (init.instructions || 'the account details are in your email.'); return finish(); }
        return paystack(init);
      });
    }).catch(function (err) {
      s.busy = false;
      var fields = err && err.body && err.body.fields;
      if (fields && fields.length) {
        var to = order().filter(function (k) { return fields.some(function (x) { return SERVER_SCREEN[x] === k; }); })[0] || 'review';
        s.step = to; s.err = { tile: 'Please check this answer: we could not accept it.' }; paint(true); return;
      }
      s.payNote = err && err.status === 429 ? 'Too many attempts from this connection. Please wait a few minutes and try again.'
        : 'That did not go through, and nothing was charged. Please try again, or write to academy@mamcentre.org.';
      paint(false);
    });
  }
  function paystack(init) {
    return new Promise(function (resolve, reject) {
      var sc = document.createElement('script'); sc.src = 'https://js.paystack.co/v1/inline.js';
      sc.onload = function () {
        var kobo = Math.round(Number(init.amount_ngn) * 100);
        if (!window.PaystackPop || !isFinite(kobo) || kobo <= 0) { reject(new Error('provider_unavailable')); return; }
        window.PaystackPop.setup({ key: init.public_key, email: contactEmail(), amount: kobo, currency: 'NGN', ref: init.reference,
          metadata: { reference: init.reference, purpose: 'booking_fee' },
          callback: function (r) {
            window.MAM_API.verifyPayment({ provider: 'paystack', reference: r.reference || init.reference, transaction_id: 0 })
              .then(function (v) { s.payNote = v.status === 'succeeded' ? 'Paid by card.' : 'We are confirming your payment; you will get an email when it clears.'; finish(); resolve(); })
              .catch(function () { s.payNote = 'We are confirming your payment; you will get an email when it clears.'; finish(); resolve(); });
          },
          onClose: function () { s.busy = false; s.payNote = 'Your application is saved as ' + s.ref + '. The payment window was closed; press Pay to try again, or pay from the link in your email.'; paint(false); resolve(); }
        }).openIframe();
      };
      sc.onerror = function () { reject(new Error('provider_unavailable')); };
      document.head.appendChild(sc);
    }).catch(function () {
      s.busy = false; s.payNote = 'Your application is saved as ' + s.ref + ', but the card window did not open. Choose bank transfer, or pay from the link in your email.'; paint(false);
    });
  }
  function finish() { s.busy = false; go('done'); }

  /* ---------- wiring ---------- */
  root.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-pick],[data-ap="next"],[data-change],[data-suggest]') : null; if (!t) return;
    if (t.hasAttribute('data-pick')) {
      var fld = t.getAttribute('data-pick'); s.f[fld] = t.getAttribute('data-v'); s.err = {};
      if (fld === 'instrument' && s.f.instrument !== 'wind') s.f.wind = '';
      if (fld === 'prog') { var o = order(); if (s.back2review && (o.indexOf('level') >= 0 && !s.f.level || o.indexOf('style') >= 0 && !s.f.style)) s.back2review = false; }
      persist(); paint(false);
      var again = root.querySelector('[data-pick="' + fld + '"][data-v="' + CSS.escape(s.f[fld]) + '"]'); if (again) again.focus();
    } else if (t.hasAttribute('data-change')) { s.back2review = s.step === 'review'; go(t.getAttribute('data-change')); }
    else if (t.hasAttribute('data-suggest')) { var k = t.getAttribute('data-suggest'); s.f[k] = t.textContent; persist(); paint(false); }
    else next();
  });
  document.querySelector('.ap-aside').addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-change]') : null; if (t) { s.back2review = false; go(t.getAttribute('data-change')); }
  });
  root.addEventListener('input', function (e) {
    var k = e.target.getAttribute && e.target.getAttribute('data-f'); if (!k) return;
    s.f[k] = e.target.type === 'checkbox' ? e.target.checked : e.target.value; persist();
    if (k === 'email' || k === 'gemail') {
      var line = root.querySelector('[data-suggest-line="' + k + '"]'), sug = suggest(k);
      if (line) { line.hidden = !sug; line.querySelector('button').textContent = sug; }
    }
  });
  root.addEventListener('change', function (e) { var k = e.target.getAttribute && e.target.getAttribute('data-f'); if (k === 'terms') { s.f.terms = e.target.checked; } });
  root.addEventListener('keydown', function (e) {
    var t = e.target;
    if (e.key === 'Enter' && t && t.tagName !== 'BUTTON' && t.tagName !== 'A') { e.preventDefault(); next(); return; }
    // A radio group moves with the arrow keys and selects as it moves.
    if (t.getAttribute && t.getAttribute('role') === 'radio' && /^Arrow/.test(e.key)) {
      var group = Array.prototype.slice.call(t.parentNode.querySelectorAll('[role="radio"]')), i = group.indexOf(t);
      var nx = group[(i + (e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : -1) + group.length) % group.length];
      e.preventDefault(); nx.click();
    }
  });
  $('back').addEventListener('click', back);
  $('reset').addEventListener('click', function () { try { localStorage.removeItem(KEY); } catch (e) { /* nothing saved */ } s.f = blank(); s.restored = false; s.back2review = false; go('who'); });
  paint(false);
})();
