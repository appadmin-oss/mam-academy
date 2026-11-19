/* MAM Academy — the growth screen (api/lib/growth.php), shared by the student,
 * guardian and instructor portals.
 *
 *   MAM_GROWTH.render(root, { paint, toast, student, back })
 *
 * The server decides who may see a student and what (a teacher alone gets the
 * adaptive suggestion). Bars are a `data-pct` table in portal-v2.css, never a
 * style attribute. A week with no class scheduled is drawn as "no class", not
 * as a zero.
 */
(function () {
  'use strict';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function pct(v) { return Math.max(0, Math.min(100, Math.round(Number(v) || 0))); }
  function wk(d) { var x = new Date(d + 'T12:00:00Z'); return x.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }); }
  function errText(e, fb) { return (window.P && P.errText) ? P.errText(e, fb) : fb; }

  function render(root, opts) {
    var paint = opts.paint || function (h) { root.innerHTML = h; };
    var toast = opts.toast || function () {};
    var q = opts.student ? '?student=' + encodeURIComponent(opts.student) : '';
    MAM_API.request('/api/growth' + q).then(function (r) {
      var g = r.growth, weeks = g.timeline.weeks, own = !opts.student;
      var maxP = Math.max.apply(null, weeks.map(function (w) { return w.practice_min; }).concat([1]));
      var html = (opts.back ? '<button type="button" class="p-back" data-gr-back><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Back</button>' : '')
        + '<h1 class="p-h1" tabindex="-1">' + (own ? 'My growth' : esc(g.student.name) + '’s growth') + '</h1>'
        + '<p class="p-body p-lead">The last twelve weeks, week by week. Nobody is compared with anybody else here.</p><div class="p-stack">';
      if ((g.timeline.summary || []).length) {
        html += '<section class="p-card"><h2 class="p-title">Lately</h2>' + g.timeline.summary.map(function (s) {
          return '<p class="p-body gr-line"><i class="fa-solid fa-arrow-trend-' + (s.up ? 'up' : 'down') + '" aria-hidden="true"></i> ' + esc(s.text) + '</p>';
        }).join('') + '</section>';
      }
      (g.suggestions || []).forEach(function (s) {
        html += '<div class="p-notice' + (s.key === 'needs_support' ? ' is-warn' : '') + '"><i class="fa-solid fa-compass" aria-hidden="true"></i><span><strong>'
          + esc(s.course) + ':</strong> ' + esc(s.words) + ' <span class="p-caption">(for teachers only; from ' + esc(s.answered) + ' answers)</span></span></div>';
      });
      html += '<section class="p-card"><h2 class="p-title">Week by week</h2><div class="gr-weeks">';
      weeks.slice().reverse().forEach(function (w) {
        var bits = [];
        bits.push(w.practice_min ? w.practice_min + ' min practice' : 'no practice logged');
        bits.push(w.classes == null ? 'no class' : w.attended + ' of ' + w.classes + ' classes');
        if (w.lessons) bits.push(w.lessons + ' lesson' + (w.lessons > 1 ? 's' : '') + ' finished');
        if (w.handins) bits.push(w.handins + ' handed in' + (w.mark_avg != null ? ', marked ' + w.mark_avg + '%' : ''));
        if (w.band) bits.push('standing ' + w.band[0] + '–' + w.band[1]);
        html += '<div class="gr-week"><span class="p-label">' + esc(wk(w.week)) + '</span>'
          + '<span class="p-bar" aria-hidden="true"><span class="is-secure" data-pct="' + pct(w.practice_min / maxP * 100) + '"></span></span>'
          + '<span class="p-caption">' + esc(bits.join(' · ')) + '</span></div>';
      });
      html += '</div></section>';
      var p = g.timeline.practice;
      if (p && p.streak != null) html += '<p class="p-caption">Practice streak: ' + esc(p.streak) + ' day' + (p.streak === 1 ? '' : 's') + '.</p>';

      html += '<section class="p-card"><h2 class="p-title">Goals</h2>';
      if (!g.goals.length) html += '<p class="p-caption">No goals yet.</p>';
      g.goals.forEach(function (o) {
        var pr = o.progress || {};
        html += '<div class="gr-goal"><div class="p-row-main"><span class="p-title">' + esc(o.title) + '</span><span class="p-caption">'
          + (pr.target != null ? esc(pr.now) + ' of ' + esc(pr.target) + (o.kind === 'practice_week' ? ' minutes this week' : '') : '')
          + (o.due_on ? ' · by ' + esc(o.due_on) : '') + ' · set by ' + esc(o.set_by) + '</span>'
          + (pr.pct != null ? '<span class="p-bar" aria-hidden="true"><span class="is-strong" data-pct="' + pct(pr.pct) + '"></span></span>' : '') + '</div>'
          + '<span class="p-chip' + (o.status === 'achieved' ? ' is-good' : '') + '">' + (o.status === 'achieved' ? 'Done' : o.status === 'dropped' ? 'Dropped' : 'Going') + '</span>'
          + (g.can_set_goals && o.status === 'active' ? '<span class="p-actions"><button type="button" class="p-link" data-gr-close="' + o.id + '" data-st="achieved">Done</button>'
             + '<button type="button" class="p-link" data-gr-close="' + o.id + '" data-st="dropped">Drop</button></span>' : '')
          + '</div>';
      });
      if (g.can_set_goals) {
        html += '<details class="p-gap-t"><summary class="p-label">' + (own ? 'Set yourself a goal' : 'Suggest a goal') + '</summary>'
          + '<div class="p-field p-gap-t"><label for="grKind">Kind</label><select id="grKind">'
          + Object.keys(g.goal_kinds).map(function (k) { return '<option value="' + k + '">' + esc(g.goal_kinds[k]) + '</option>'; }).join('') + '</select></div>'
          + '<div class="p-field"><label for="grTarget">How many</label><input id="grTarget" type="number" min="1" inputmode="numeric"></div>'
          + '<div class="p-field"><label for="grTitle">In your own words (optional)</label><input id="grTitle" maxlength="160"></div>'
          + '<div class="p-field"><label for="grDue">By (optional)</label><input id="grDue" type="date"></div>'
          + '<button type="button" class="p-action" data-gr-save>Save the goal</button></details>';
      }
      html += '</section></div>';
      paint(html);
      var b = root.querySelector('[data-gr-back]'); if (b) b.addEventListener('click', opts.back);
      var save = root.querySelector('[data-gr-save]');
      if (save) save.addEventListener('click', function () {
        save.disabled = true;
        MAM_API.request('/api/growth', { method: 'POST', body: { action: 'goal', student: opts.student || null, kind: root.querySelector('#grKind').value,
          target: Number(root.querySelector('#grTarget').value) || null, title: root.querySelector('#grTitle').value, due_on: root.querySelector('#grDue').value } })
          .then(function () { toast('Goal saved.', 'good'); render(root, opts); })
          .catch(function (e) { save.disabled = false; toast(errText(e, 'The goal did not save.'), 'bad'); });
      });
      Array.prototype.forEach.call(root.querySelectorAll('[data-gr-close]'), function (x) {
        x.addEventListener('click', function () {
          x.disabled = true;
          MAM_API.request('/api/growth', { method: 'POST', body: { action: 'goal_close', goal_id: Number(x.dataset.grClose), status: x.dataset.st } })
            .then(function () { render(root, opts); }).catch(function (e) { x.disabled = false; toast(errText(e, 'That did not save.'), 'bad'); });
        });
      });
    }).catch(function (e) {
      paint('<h1 class="p-h1" tabindex="-1">Growth</h1><div class="p-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><p>' + esc(errText(e, 'Could not load this.')) + '</p></div>');
    });
  }
  window.MAM_GROWTH = { render: render };
})();
