/* MAM Academy — class spaces and "tell the safeguarding officer", shared by the
 * student, instructor and guardian portals.
 *
 *   MAM_SPACES.mount(root, { toast, paint, onTitle })   the list → a space → its feed
 *   MAM_SPACES.concernForm(root, { toast, aboutPost })  a concern, from anywhere
 *
 * The server decides everything about who sees what (api/modules/community/lib/spaces.php): this
 * file draws what it is sent and offers the actions the role allows. It never
 * hides a mark or a held post itself — if a payload carries a score, the viewer
 * was allowed it.
 *
 * Markup is class-only (no style attributes — this repo bans them) and every
 * write keeps its button busy until the server answers.
 */
(function () {
  'use strict';

  var REACT = { clap: 'hands-clapping', heart: 'heart', star: 'star' };
  var REASONS = { unkind: 'Unkind or bullying', unsafe: 'Makes me feel unsafe', private_info: 'Shares private details', spam: 'Spam or off topic', other: 'Something else' };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function api(path, opts) { return window.MAM_API.request(path, opts); }
  function when(ts) {
    if (!ts) return '';
    var d = new Date(String(ts).replace(' ', 'T') + (String(ts).indexOf('Z') < 0 && String(ts).length <= 19 ? 'Z' : ''));
    if (isNaN(d)) return esc(ts);
    var mins = Math.round((Date.now() - d.getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + ' min ago';
    if (mins < 60 * 24) return Math.round(mins / 60) + ' h ago';
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  }
  function clock(sec) { sec = Math.max(0, Math.round(sec || 0)); return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2); }
  function errText(e, fb) { return (window.P && P.errText) ? P.errText(e, fb) : ((e && e.body && e.body.message) || fb); }
  function staff(role) { return role === 'teacher' || role === 'admin'; }
  function roleWord(role) { return ({ teacher: 'Teacher', admin: 'Admin', student: 'Student', guardian: 'Reading as a guardian' })[role] || ''; }

  function busy(btn, on, label) {
    if (!btn) return;
    if (on) { btn.dataset.label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch p-spin" aria-hidden="true"></i> ' + (label || 'Saving…'); }
    else { btn.disabled = false; if (btn.dataset.label) btn.innerHTML = btn.dataset.label; }
  }

  /* ── Mount ───────────────────────────────────────────────────────── */

  function mount(root, opts) {
    var st = { root: root, toast: opts.toast || function () {}, paint: opts.paint || function (h) { root.innerHTML = h; },
               space: null, data: null, view: 'list', uploading: null };
    root.__sp = st;
    if (!root.__spBound) { root.addEventListener('click', onClick); root.addEventListener('submit', onSubmit); root.addEventListener('change', onChange); root.__spBound = true; }
    showList(st);
    return st;
  }
  function S(root) { return root.__sp; }

  function showList(st) {
    st.view = 'list';
    api('/api/spaces').then(function (d) {
      var list = d.spaces || [];
      var anyStaff = list.some(function (s) { return staff(s.role); });
      var html = '<h1 class="p-h1" tabindex="-1">Class</h1>'
        + '<p class="p-body p-lead">Hand in your work, see your classmates\' and hear from your teacher. Everything here is seen by your class\'s teacher.</p>'
        + '<div class="p-stack">';
      if (anyStaff) html += '<button type="button" class="p-ghost" data-sp="queue"><i class="fa-solid fa-inbox" aria-hidden="true"></i> Waiting for you</button>';
      if (!list.length) html += '<div class="p-empty"><i class="fa-solid fa-people-group" aria-hidden="true"></i><p>No class spaces yet. One appears when you are in a class.</p></div>';
      list.forEach(function (s) {
        html += '<button type="button" class="p-card sp-space" data-sp="open" data-id="' + s.id + '">'
          + '<span class="sp-space-head"><span class="p-title">' + esc(s.title) + '</span>'
          + (s.visibility === 'private' ? '<span class="p-chip"><i class="fa-solid fa-lock" aria-hidden="true"></i> Private</span>' : '')
          + (s.status === 'archived' ? '<span class="p-chip">Archived</span>' : '') + '</span>'
          + '<span class="p-caption">' + esc(roleWord(s.role)) + ' · ' + s.members + ' people'
          + (s.tasks && s.tasks.length ? ' · ' + s.tasks.length + ' open task' + (s.tasks.length > 1 ? 's' : '') : '') + '</span>'
          + (s.held ? '<span class="p-chip is-warn">' + s.held + ' waiting for a decision</span>' : '')
          + (s.to_mark ? '<span class="p-chip">' + s.to_mark + ' to mark</span>' : '')
          + '</button>';
      });
      if (anyStaff) {
        html += '<details class="p-card is-quiet sp-new"><summary class="p-title">Open a private space</summary>'
          + '<form class="p-form" data-sp-form="create"><label class="p-field"><span class="p-label">Name</span>'
          + '<input name="title" maxlength="160" required></label>'
          + '<p class="p-caption">A private space has only the people you add. Your classes\' own spaces appear above by themselves.</p>'
          + '<button class="p-primary" type="submit">Open it</button></form></details>';
      }
      html += '</div>';
      st.paint(html);
    }).catch(function (e) { st.paint('<h1 class="p-h1" tabindex="-1">Class</h1>' + emptyErr(errText(e, 'Could not load your class spaces.'))); });
  }

  function emptyErr(msg) {
    return '<div class="p-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><p>' + esc(msg) + '</p>'
      + '<button type="button" class="p-ghost" data-sp="list">Try again</button></div>';
  }

  function showSpace(st, id) {
    st.view = 'space';
    api('/api/spaces?id=' + id).then(function (d) { st.space = id; st.data = d; drawSpace(st); })
      .catch(function (e) { st.paint(back() + emptyErr(errText(e, 'Could not open this space.'))); });
  }

  function back() { return '<button type="button" class="p-back" data-sp="list"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> All spaces</button>'; }

  function drawSpace(st) {
    var d = st.data, s = d.space, role = s.role, isStaff = staff(role);
    var html = back() + '<h1 class="p-h1" tabindex="-1">' + esc(s.title) + '</h1>'
      + '<p class="p-body p-lead">' + (s.about ? esc(s.about) + ' · ' : '') + esc(roleWord(role))
      + (s.visibility === 'private' ? ' · private space' : ' · everyone in the class') + '</p><div class="p-stack">';
    if (d.needs_consent) {
      html += '<div class="p-notice"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Your videos and photos go to your teacher only, '
        + 'until your family agrees to class videos in the parent portal. Your work still counts.</span></div>';
    }
    html += drawTasks(st, d.tasks || [], role);
    if (d.can_post) html += drawComposer(st, d.tasks || [], role);
    html += '<h2 class="p-label sp-feedhead">The class feed</h2>';
    var posts = (d.feed && d.feed.posts) || [];
    if (!posts.length) html += '<div class="p-empty"><i class="fa-regular fa-comments" aria-hidden="true"></i><p>Nothing here yet.</p></div>';
    posts.forEach(function (p) { html += drawPost(p, role); });
    if (d.feed && d.feed.more) html += '<button type="button" class="p-ghost" data-sp="more" data-before="' + d.feed.next + '">Older posts</button>';
    html += drawPeople(d.members || [], role);
    if (isStaff) html += drawSettings(s);
    html += '<button type="button" class="p-link sp-tell" data-sp="concern"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Worried about something? Tell the safeguarding officer</button>';
    html += '</div>';
    st.paint(html);
  }

  function drawTasks(st, tasks, role) {
    var isStaff = staff(role), open = tasks.filter(function (t) { return t.status === 'open'; });
    var html = '<section class="p-card"><h2 class="p-title">Tasks</h2>';
    if (!tasks.length) html += '<p class="p-caption">' + (isStaff ? 'No tasks yet. Set one below.' : 'No tasks yet.') + '</p>';
    tasks.forEach(function (t) {
      html += '<div class="sp-task' + (t.status !== 'open' ? ' is-closed' : '') + '"><div class="p-row-main"><span class="p-title">' + esc(t.title) + '</span>'
        + '<span class="p-caption">' + (t.wants === 'video' ? 'A video' : t.wants === 'audio' ? 'A recording' : 'Any file') + ' · ' + t.points + ' points'
        + (t.due_at ? ' · due ' + esc(String(t.due_at).slice(0, 10)) : '') + (t.share === 'teacher' ? ' · seen by the teacher only' : '')
        + (t.status !== 'open' ? ' · closed' : '') + '</span>'
        + (t.brief ? '<span class="p-body">' + esc(t.brief) + '</span>' : '') + '</div>';
      if (role === 'student') {
        if (t.mine) html += '<span class="p-chip' + (t.mine.reviewed ? ' is-good' : '') + '">' + (t.mine.reviewed ? 'Marked · ' + t.mine.score + '/' + t.mine.max : (t.mine.status === 'held' ? 'Handed in · waiting for a check' : 'Handed in')) + '</span>';
        else if (t.status === 'open') html += '<span class="p-chip' + (t.overdue ? ' is-warn' : '') + '">' + (t.overdue ? 'Overdue' : 'To do') + '</span>';
      } else if (isStaff) {
        html += '<span class="p-caption">' + t.handed_in + ' handed in' + (t.to_mark ? ' · ' + t.to_mark + ' to mark' : '') + '</span>'
          + '<button type="button" class="p-link" data-sp="task-feed" data-task="' + t.id + '">See the work</button>'
          + (t.status === 'open' ? ' <button type="button" class="p-link" data-sp="task-close" data-task="' + t.id + '">Close it</button>' : '');
      }
      html += '</div>';
    });
    if (isStaff) {
      html += '<details class="sp-newtask"><summary class="p-link">Set a task</summary><form class="p-form" data-sp-form="task">'
        + '<label class="p-field"><span class="p-label">What to do</span><input name="title" maxlength="160" required></label>'
        + '<label class="p-field"><span class="p-label">Instructions</span><textarea name="brief" rows="3" maxlength="2000"></textarea></label>'
        + '<div class="p-grid2"><label class="p-field"><span class="p-label">They hand in</span><select name="wants"><option value="video">A video</option><option value="audio">A recording</option><option value="any">Any file</option></select></label>'
        + '<label class="p-field"><span class="p-label">Points</span><input name="points" type="number" min="1" max="100" value="10"></label></div>'
        + '<div class="p-grid2"><label class="p-field"><span class="p-label">Due</span><input name="due_at" type="date"></label>'
        + '<label class="p-field"><span class="p-label">Who sees the work</span><select name="share"><option value="class">The class</option><option value="teacher">Only teachers</option></select></label></div>'
        + '<button class="p-primary" type="submit">Set it</button></form></details>';
    }
    return html + '</section>';
  }

  function drawComposer(st, tasks, role) {
    var open = tasks.filter(function (t) { return t.status === 'open'; });
    var html = '<section class="p-card"><h2 class="p-title">' + (role === 'student' ? 'Post or hand in' : 'Post to the class') + '</h2>'
      + '<form class="p-form" data-sp-form="post">';
    if (role === 'student' && open.length) {
      html += '<label class="p-field"><span class="p-label">This is</span><select name="task_id"><option value="">A post for the class</option>';
      open.forEach(function (t) { html += '<option value="' + t.id + '">Work for: ' + esc(t.title) + '</option>'; });
      html += '</select></label>';
    }
    if (staff(role)) html += '<label class="p-check"><input type="checkbox" name="notice" value="1"> <span>A notice (everybody is told)</span></label>';
    html += '<label class="p-field"><span class="p-label">Say something</span><textarea name="body" rows="3" maxlength="2000"></textarea></label>'
      + '<label class="p-field"><span class="p-label">Add a video, recording or photo</span>'
      + '<input type="file" name="file" accept="video/*,audio/*,image/*,application/pdf"></label>'
      + '<p class="p-caption">Videos are made smaller on your phone before they are sent, so they upload faster and use less data. Keep the phone still and close enough to hear.</p>'
      + '<div class="sp-progress" hidden><progress max="100" value="0"></progress><span class="p-caption sp-progress-text" role="status" aria-live="polite"></span></div>'
      + '<button class="p-primary" type="submit">Send</button></form></section>';
    return html;
  }

  function drawMedia(m, pid) {
    if (!m) return '';
    if (m.kind === 'video') return '<video class="sp-media" controls playsinline preload="metadata" src="' + esc(m.url) + '" data-post-video="' + pid + '"></video>';
    if (m.kind === 'audio') return '<audio class="sp-audio" controls preload="metadata" src="' + esc(m.url) + '"></audio>';
    if (m.kind === 'image') return '<img class="sp-media" alt="" loading="lazy" src="' + esc(m.url) + '">';
    return '<a class="p-link" href="' + esc(m.url) + '" target="_blank" rel="noopener">Open the file</a>';
  }

  function drawPost(p, role) {
    var isStaff = staff(role);
    var html = '<article class="p-card sp-post' + (p.status !== 'visible' ? ' is-held' : '') + '" data-post="' + p.id + '">'
      + '<header class="sp-post-head"><span class="p-title">' + esc(p.author) + (p.author_is_staff ? ' <span class="p-chip">Teacher</span>' : '') + '</span>'
      + '<span class="p-caption">' + when(p.created_at) + (p.kind === 'notice' ? ' · notice' : '') + '</span></header>';
    if (p.task_title) html += '<p class="p-caption">Work for: ' + esc(p.task_title) + '</p>';
    if (p.status === 'held') html += '<p class="p-chip is-warn">' + (p.hold_reason === 'review' ? 'Waiting for a teacher to check it' : 'Waiting for a teacher' + (p.hold_reason ? ' · ' + esc(p.hold_reason) : '')) + '</p>';
    if (p.status === 'removed') html += '<p class="p-chip">Taken down' + (p.hold_reason ? ' · ' + esc(p.hold_reason) : '') + '</p>';
    if (p.audience === 'teacher') html += '<p class="p-chip"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i> Only teachers' + (role === 'student' ? ' and you' : role === 'guardian' ? ' and your family' : '') + ' see this</p>';
    if (p.body) html += '<p class="p-body sp-body">' + esc(p.body) + '</p>';
    html += drawMedia(p.media, p.id);
    if (p.score !== null && p.score !== undefined) {
      html += '<div class="p-notice is-good sp-mark"><span><strong>' + p.score + ' / ' + p.max_score + '</strong>'
        + (p.feedback ? ' — ' + esc(p.feedback) : '') + '</span></div>';
    } else if (p.reviewed) {
      html += '<p class="p-caption">Reviewed by the teacher.</p>';
    }
    if (p.status === 'visible') {
      html += '<div class="sp-react">';
      Object.keys(REACT).forEach(function (k) {
        var r = (p.reactions || {})[k] || { n: 0, mine: false };
        html += '<button type="button" class="p-chip' + (r.mine ? ' is-on' : '') + '" data-sp="react" data-kind="' + k + '" data-on="' + (r.mine ? 0 : 1) + '"'
          + (role === 'guardian' ? ' disabled' : '') + ' aria-pressed="' + (r.mine ? 'true' : 'false') + '" aria-label="' + k + ' (' + r.n + ')">'
          + '<i class="fa-solid fa-' + REACT[k] + '" aria-hidden="true"></i> ' + (r.n || '') + '</button>';
      });
      html += '</div>';
    }
    (p.comments || []).forEach(function (c) {
      html += '<div class="sp-comment' + (c.status !== 'visible' ? ' is-held' : '') + '"><span class="p-title">' + esc(c.author)
        + (c.author_is_staff ? ' <span class="p-chip">Teacher</span>' : '') + '</span> '
        + (c.at_sec !== null && c.at_sec !== undefined ? '<button type="button" class="p-link" data-sp="seek" data-at="' + c.at_sec + '">at ' + clock(c.at_sec) + '</button> ' : '')
        + '<span class="p-body">' + esc(c.body) + '</span>'
        + (c.status === 'held' ? ' <span class="p-chip is-warn">Waiting for a teacher' + (c.hold_reason ? ' · ' + esc(c.hold_reason) : '') + '</span>' : '')
        + (isStaff && c.status === 'held' ? ' <button type="button" class="p-link" data-sp="moderate" data-type="comment" data-id="' + c.id + '" data-decision="approve">Let it through</button> <button type="button" class="p-link" data-sp="moderate" data-type="comment" data-id="' + c.id + '" data-decision="remove">Take it down</button>' : '')
        + (!c.mine && !isStaff && c.status === 'visible' ? ' <button type="button" class="p-link sp-report" data-sp="report-open" data-type="comment" data-id="' + c.id + '">Report</button>' : '')
        + '</div>';
    });
    if (p.status === 'visible' && role !== 'guardian') {
      html += '<form class="sp-commentform" data-sp-form="comment"><label class="p-sr" for="c' + p.id + '">Comment</label>'
        + '<input id="c' + p.id + '" name="body" maxlength="800" placeholder="Say something kind and useful">'
        + (p.media && p.media.kind === 'video' ? '<label class="p-check"><input type="checkbox" name="at" value="1"> <span>At this moment in the video</span></label>' : '')
        + '<button class="p-ghost" type="submit">Comment</button></form>';
    }
    var acts = [];
    if (isStaff && p.status === 'held') {
      acts.push('<button type="button" class="p-primary" data-sp="moderate" data-type="post" data-id="' + p.id + '" data-decision="approve">Let the class see it</button>');
      acts.push('<button type="button" class="p-ghost" data-sp="moderate" data-type="post" data-id="' + p.id + '" data-decision="remove">Take it down</button>');
    }
    if (isStaff && p.kind === 'submission' && p.status !== 'removed') {
      html += '<details class="sp-review"' + (p.reviewed ? '' : ' open') + '><summary class="p-link">' + (p.reviewed ? 'Change the mark' : 'Mark this work') + '</summary>'
        + '<form class="p-form" data-sp-form="review"><div class="p-grid2"><label class="p-field"><span class="p-label">Mark out of ' + (p.max_score || 10) + '</span>'
        + '<input name="score" type="number" min="0" max="' + (p.max_score || 10) + '" required value="' + (p.score != null ? p.score : '') + '"></label></div>'
        + '<label class="p-field"><span class="p-label">What to keep and what to work on</span><textarea name="feedback" rows="3" required>' + esc(p.feedback || '') + '</textarea></label>'
        + '<p class="p-caption">Only the student, their family and teachers see the mark. The class sees that it was reviewed.</p>'
        + '<button class="p-primary" type="submit">Save the mark</button></form></details>';
    }
    if ((p.mine || isStaff) && p.status !== 'removed') acts.push('<button type="button" class="p-link" data-sp="remove">' + (p.mine ? 'Delete' : 'Take down') + '</button>');
    if (!p.mine && !isStaff && p.status === 'visible') acts.push('<button type="button" class="p-link sp-report" data-sp="report-open" data-type="post" data-id="' + p.id + '">Report</button>');
    if (acts.length) html += '<div class="sp-acts">' + acts.join(' ') + '</div>';
    return html + '</article>';
  }

  function drawPeople(members, role) {
    var html = '<details class="p-card is-quiet"><summary class="p-title">People (' + members.length + ')</summary><ul class="sp-people">';
    members.forEach(function (m) {
      html += '<li><span>' + esc(m.name) + '</span> <span class="p-caption">' + (m.role === 'teacher' ? 'Teacher' : 'Student') + '</span>'
        + (staff(role) && m.role === 'student' ? ' <button type="button" class="p-link" data-sp="member-remove" data-user="' + m.id + '">Remove</button>' : '') + '</li>';
    });
    html += '</ul>';
    if (staff(role)) {
      html += '<form class="p-form" data-sp-form="members"><label class="p-field"><span class="p-label">Add students by account number</span>'
        + '<input name="ids" placeholder="e.g. 112, 118" inputmode="numeric"></label>'
        + '<p class="p-caption">You can add only students you teach.</p><button class="p-ghost" type="submit">Add</button></form>';
    }
    return html + '</details>';
  }

  function drawSettings(s) {
    return '<details class="p-card is-quiet"><summary class="p-title">Space settings</summary><form class="p-form" data-sp-form="settings">'
      + '<label class="p-field"><span class="p-label">Name</span><input name="title" maxlength="160" value="' + esc(s.title) + '"></label>'
      + '<label class="p-field"><span class="p-label">About</span><input name="about" maxlength="1000" value="' + esc(s.about) + '"></label>'
      + (s.cohort_id ? '<label class="p-field"><span class="p-label">Who is in it</span><select name="visibility"><option value="class"' + (s.visibility === 'class' ? ' selected' : '') + '>Everyone in the class</option><option value="private"' + (s.visibility === 'private' ? ' selected' : '') + '>Only people I add</option></select></label>' : '')
      + '<label class="p-check"><input type="checkbox" name="review_media" value="1"' + (s.review_media ? ' checked' : '') + '> <span>Check students\' videos and photos before the class sees them</span></label>'
      + '<label class="p-check"><input type="checkbox" name="students_comment" value="1"' + (s.students_comment ? ' checked' : '') + '> <span>Students may comment</span></label>'
      + '<label class="p-check"><input type="checkbox" name="archived" value="1"' + (s.status === 'archived' ? ' checked' : '') + '> <span>Archived (read-only)</span></label>'
      + '<button class="p-primary" type="submit">Save</button></form></details>';
  }

  function showQueue(st) {
    st.view = 'queue';
    api('/api/spaces?queue=1').then(function (d) {
      var q = d.queue || {}, html = back() + '<h1 class="p-h1" tabindex="-1">Waiting for you</h1><div class="p-stack">';
      html += '<h2 class="p-label">Held for a decision</h2>';
      if (!(q.held || []).length) html += '<p class="p-caption">Nothing is held.</p>';
      (q.held || []).forEach(function (h) {
        html += '<article class="p-card sp-post is-held"><header class="sp-post-head"><span class="p-title">' + esc(h.author || 'Somebody') + '</span>'
          + '<span class="p-caption">' + esc(h.space_title) + ' · ' + when(h.created_at) + '</span></header>'
          + '<p class="p-chip is-warn">' + (h.hold_reason === 'review' ? 'A video to check' : esc(h.hold_reason || 'Held')) + '</p>'
          + (h.body ? '<p class="p-body sp-body">' + esc(h.body) + '</p>' : '') + drawMedia(h.media, h.id)
          + (h.own ? '<p class="p-caption">This is yours, so an admin decides it.</p>'
             : '<div class="sp-acts"><button type="button" class="p-primary" data-sp="moderate" data-type="' + h.type + '" data-id="' + h.id + '" data-decision="approve">Let it through</button>'
             + ' <button type="button" class="p-ghost" data-sp="moderate" data-type="' + h.type + '" data-id="' + h.id + '" data-decision="remove">Take it down</button></div>')
          + '</article>';
      });
      html += '<h2 class="p-label">Work to mark</h2>';
      if (!(q.to_mark || []).length) html += '<p class="p-caption">Everything handed in is marked.</p>';
      (q.to_mark || []).forEach(function (m) {
        html += '<button type="button" class="p-row" data-sp="open" data-id="' + m.space_id + '"><span class="p-row-main"><span class="p-title">' + esc(m.author || 'A student') + ' · ' + esc(m.task_title || 'Work') + '</span>'
          + '<span class="p-caption">' + esc(m.space_title) + ' · ' + when(m.created_at) + '</span></span><i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i></button>';
      });
      st.paint(html + '</div>');
    }).catch(function (e) { st.paint(back() + emptyErr(errText(e, 'Could not load what is waiting.'))); });
  }

  /* ── Events ──────────────────────────────────────────────────────── */

  function postIdOf(el) { var a = el.closest('[data-post]'); return a ? +a.dataset.post : 0; }

  function act(st, btn, body, ok, after) {
    busy(btn, true);
    return api('/api/spaces', { method: 'POST', body: body }).then(function (r) {
      busy(btn, false);
      if (r && r.note) st.toast(r.note, 'good'); else if (ok) st.toast(ok, 'good');
      if (after) after(r); else if (st.space) showSpace(st, st.space);
    }).catch(function (e) { busy(btn, false); st.toast(errText(e, 'That did not work.'), 'bad'); });
  }

  function onClick(ev) {
    var b = ev.target.closest('[data-sp]'); if (!b) return;
    var root = ev.currentTarget, st = S(root); if (!st) return;
    var a = b.dataset.sp;
    if (a === 'list') return showList(st);
    if (a === 'open') return showSpace(st, +b.dataset.id);
    if (a === 'queue') return showQueue(st);
    if (a === 'concern') return concernForm(root, { toast: st.toast, paint: st.paint, back: function () { showSpace(st, st.space); } });
    if (a === 'more') {
      busy(b, true, 'Loading…');
      return api('/api/spaces?id=' + st.space + '&before=' + b.dataset.before).then(function (d) {
        var html = ''; (d.feed.posts || []).forEach(function (p) { html += drawPost(p, st.data.space.role); });
        b.insertAdjacentHTML('beforebegin', html);
        if (d.feed.more) { busy(b, false); b.dataset.before = d.feed.next; } else b.remove();
      }).catch(function (e) { busy(b, false); st.toast(errText(e, 'Could not load more.'), 'bad'); });
    }
    if (a === 'task-feed') {
      return api('/api/spaces?id=' + st.space + '&task=' + b.dataset.task).then(function (d) {
        st.data.feed = d.feed; drawSpace(st);
        var head = root.querySelector('.sp-feedhead'); if (head) { head.textContent = 'Work for this task'; head.scrollIntoView({ block: 'start' }); }
      });
    }
    if (a === 'task-close') return act(st, b, { action: 'task', space_id: st.space, id: +b.dataset.task, status: 'closed',
      title: (st.data.tasks.filter(function (t) { return t.id === +b.dataset.task; })[0] || {}).title }, 'Task closed.');
    if (a === 'react') return act(st, b, { action: 'react', post_id: postIdOf(b), kind: b.dataset.kind, on: b.dataset.on === '1' });
    if (a === 'remove') {
      if (!window.confirm('Take this post down?')) return;
      return act(st, b, { action: 'remove', post_id: postIdOf(b) }, 'Taken down.');
    }
    if (a === 'moderate') {
      var note = '';
      if (b.dataset.decision === 'remove') { note = window.prompt('Why is it coming down? (the author is told)') || ''; }
      return act(st, b, { action: 'moderate', type: b.dataset.type, id: +b.dataset.id, decision: b.dataset.decision, note: note },
        b.dataset.decision === 'approve' ? 'It is up.' : 'Taken down.', st.view === 'queue' ? function () { showQueue(st); } : null);
    }
    if (a === 'member-remove') {
      if (!window.confirm('Take this person out of the space? Their work stays on the record.')) return;
      return act(st, b, { action: 'member_remove', space_id: st.space, user_id: +b.dataset.user }, 'Removed.');
    }
    if (a === 'seek') {
      var v = b.closest('.sp-post').querySelector('video');
      if (v) { v.currentTime = +b.dataset.at; v.focus(); v.play().catch(function () {}); }
      return;
    }
    if (a === 'report-open') {
      var host = b.closest('.sp-comment') || b.closest('.sp-post');
      if (host.querySelector('.sp-reportform')) return;
      var html = '<form class="p-form sp-reportform" data-sp-form="report" data-type="' + b.dataset.type + '" data-id="' + b.dataset.id + '">'
        + '<label class="p-field"><span class="p-label">What is wrong?</span><select name="reason">';
      Object.keys(REASONS).forEach(function (k) { html += '<option value="' + k + '">' + REASONS[k] + '</option>'; });
      html += '</select></label><label class="p-field"><span class="p-label">Anything else (optional)</span><input name="note" maxlength="1000"></label>'
        + '<p class="p-caption">Only teachers see who reported it. "Makes me feel unsafe" hides it at once and tells the safeguarding officer.</p>'
        + '<button class="p-primary" type="submit">Report</button></form>';
      host.insertAdjacentHTML('beforeend', html);
      host.querySelector('.sp-reportform select').focus();
    }
  }

  function onChange(ev) {
    var f = ev.target.closest('[data-sp-form="post"]');
    if (f && ev.target.name === 'file' && ev.target.files[0]) {
      var file = ev.target.files[0], k = window.MAM_MEDIA ? MAM_MEDIA.kindOf(file) : 'other';
      var tx = f.querySelector('.sp-progress-text'), box = f.querySelector('.sp-progress');
      box.hidden = false;
      tx.textContent = (k === 'video' ? 'A video of ' : 'A file of ') + Math.round(file.size / 1048576) + ' MB.'
        + (k === 'video' ? (MAM_MEDIA.canCompressVideo() ? ' It will be made smaller before it is sent.' : ' This browser cannot make it smaller, so it will be sent as it is.') : '');
    }
  }

  function onSubmit(ev) {
    var form = ev.target.closest('[data-sp-form]'); if (!form) return;
    ev.preventDefault();
    var root = ev.currentTarget, st = S(root) || {}, kind = form.dataset.spForm, btn = form.querySelector('[type="submit"]');
    var val = function (n) { var el = form.elements[n]; return el ? (el.type === 'checkbox' ? el.checked : el.value) : undefined; };
    if (kind === 'concern') return; // handled by concernForm's own listener
    if (kind === 'create') {
      return act(st, btn, { action: 'create', title: val('title') }, 'Space opened.', function (r) { showSpace(st, r.id); });
    }
    if (kind === 'task') {
      return act(st, btn, { action: 'task', space_id: st.space, title: val('title'), brief: val('brief'), wants: val('wants'),
        points: +val('points'), due_at: val('due_at'), share: val('share') }, 'Task set. The class has been told.');
    }
    if (kind === 'settings') {
      return act(st, btn, { action: 'settings', space_id: st.space, title: val('title'), about: val('about'), visibility: val('visibility'),
        review_media: val('review_media'), students_comment: val('students_comment'), status: val('archived') ? 'archived' : 'open' }, 'Saved.');
    }
    if (kind === 'members') {
      var ids = String(val('ids') || '').split(/[^0-9]+/).filter(Boolean).map(Number);
      return act(st, btn, { action: 'members_add', space_id: st.space, user_ids: ids, role: 'student' }, null, function (r) {
        st.toast(r.added + ' added' + (r.refused && r.refused.length ? '; ' + r.refused.map(function (x) { return '#' + x.id + ': ' + x.why; }).join(' ') : '.'), r.refused && r.refused.length ? 'bad' : 'good');
        showSpace(st, st.space);
      });
    }
    if (kind === 'review') {
      return act(st, btn, { action: 'review', post_id: postIdOf(form), score: +val('score'), feedback: val('feedback') }, 'Marked. The student has been told.');
    }
    if (kind === 'comment') {
      var at = null, v = form.closest('.sp-post').querySelector('video');
      if (val('at') && v) at = v.currentTime;
      return act(st, btn, { action: 'comment', post_id: postIdOf(form), body: val('body'), at_sec: at });
    }
    if (kind === 'report') {
      return act(st, btn, { action: 'report', type: form.dataset.type, id: +form.dataset.id, reason: val('reason'), note: val('note') });
    }
    if (kind === 'post') return sendPost(st, form, btn);
  }

  function sendPost(st, form, btn) {
    var file = form.elements.file && form.elements.file.files[0];
    var body = form.elements.body.value.trim();
    var task = form.elements.task_id ? +form.elements.task_id.value : 0;
    var notice = form.elements.notice && form.elements.notice.checked;
    if (!file && !body) { st.toast('Write something or add a file.', 'bad'); return; }
    var bar = form.querySelector('progress'), tx = form.querySelector('.sp-progress-text'), box = form.querySelector('.sp-progress');
    var words = { prepare: 'Getting it ready…', compress: 'Making the video smaller…', upload: 'Sending…', check: 'Checking it arrived…' };
    busy(btn, true, file ? 'Sending…' : 'Posting…');
    var up = file ? MAM_MEDIA.upload(file, { purpose: 'community', onProgress: function (f, stage) {
      box.hidden = false; bar.value = Math.round(f * 100); tx.textContent = words[stage] + (stage === 'compress' || stage === 'upload' ? ' ' + Math.round(f * 100) + '%' : '');
    } }) : Promise.resolve(null);
    up.then(function (m) {
      return api('/api/spaces', { method: 'POST', body: { action: 'post', space_id: st.space, kind: task ? 'submission' : (notice ? 'notice' : 'post'),
        task_id: task || null, body: body, media_id: m ? m.id : null } });
    }).then(function (r) {
      busy(btn, false);
      st.toast(r.note || (task ? 'Handed in.' : 'Posted.'), 'good');
      showSpace(st, st.space);
    }).catch(function (e) {
      busy(btn, false); box.hidden = true;
      st.toast(errText(e, 'That did not send.'), 'bad');
    });
  }

  /* ── Tell the safeguarding officer ───────────────────────────────── */

  function concernForm(root, opts) {
    var paint = opts.paint || function (h) { root.innerHTML = h; };
    api('/api/safeguarding/concern').then(function (d) {
      var cats = d.categories || {};
      var html = (opts.back ? '<button type="button" class="p-back" data-sg-back><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Back</button>' : '')
        + '<h1 class="p-h1" tabindex="-1">Tell the safeguarding officer</h1>'
        + '<p class="p-body p-lead">If something has worried you, tell us here. You do not need to be sure. Only the safeguarding officer reads it.</p>'
        + '<div class="p-stack"><div class="p-notice is-warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>'
        + '<span>If anybody is in danger right now, call <strong>112</strong> first.</span></div>'
        + '<form class="p-card p-form" data-sg-form><label class="p-field"><span class="p-label">What is it about?</span><select name="category">';
      Object.keys(cats).forEach(function (k) { html += '<option value="' + k + '">' + esc(cats[k]) + '</option>'; });
      html += '</select></label><label class="p-field"><span class="p-label">What happened?</span>'
        + '<textarea name="body" rows="6" required minlength="10" maxlength="5000"></textarea></label>'
        + '<label class="p-check"><input type="checkbox" name="urgent" value="1"> <span>This is urgent</span></label>'
        + '<button class="p-primary" type="submit">Send to the officer</button></form>';
      if ((d.mine || []).length) {
        html += '<section class="p-card is-quiet"><h2 class="p-title">What you have told us</h2>';
        d.mine.forEach(function (m) { html += '<p class="p-caption">' + esc(cats[m.category] || m.category) + ' · ' + when(m.created_at) + ' · received</p>'; });
        html += '</section>';
      }
      paint(html + '</div>');
      var form = root.querySelector('[data-sg-form]');
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = form.querySelector('[type="submit"]'); busy(btn, true, 'Sending…');
        api('/api/safeguarding/concern', { method: 'POST', body: { category: form.elements.category.value, body: form.elements.body.value,
          urgency: form.elements.urgent.checked ? 'urgent' : 'normal' } }).then(function (r) {
          busy(btn, false);
          form.outerHTML = '<div class="p-notice is-good" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>' + esc(r.message) + '</span></div>';
        }).catch(function (e) { busy(btn, false); (opts.toast || function () {})(errText(e, 'That did not send. Try again, or tell a teacher.'), 'bad'); });
      });
      var b = root.querySelector('[data-sg-back]');
      if (b && opts.back) b.addEventListener('click', opts.back);
    }).catch(function (e) { paint('<h1 class="p-h1" tabindex="-1">Tell the safeguarding officer</h1>' + emptyErr(errText(e, 'Could not open the form.'))); });
  }

  window.MAM_SPACES = { mount: mount, concernForm: concernForm };
})();
