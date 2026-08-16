<?php
/**
 * Admin data tables — the row's action must always be reachable.
 *
 * The defect this guards against was reported by the academy's admin as the
 * page being "impossible to use", and that was literal rather than rhetorical.
 * Measured in a real browser against the production export at 1440×900:
 *
 *   • Payments  — "✓ Confirm" rendered at x=1437–1501 on a 1440px viewport.
 *   • Applications — a 1666px table inside a 1122px card put 104 controls
 *     outside the viewport, including "⚙ Manage" and "Save".
 *   • Users — 34 more, including the password "Reset".
 *
 * `.card` scrolls horizontally, so the controls existed but could only be
 * reached by discovering a scrollbar INSIDE the table. On the two screens the
 * admin uses daily — 21 applications awaiting a decision, 11 bank transfers
 * awaiting confirmation — the submit button was off-screen.
 *
 * These are static checks on the markup and stylesheet. They cannot measure a
 * layout, so the browser pass is what proves the fix; what they CAN do is fail
 * the moment someone removes the pin or reintroduces the inline style that
 * defeated it, which is how this would come back.
 */
declare(strict_types=1);

$admin = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');

t_group('admin tables — the actions column is pinned');

t_ok((bool) preg_match('/\.card table td:last-child,\s*\.card table th:last-child\{[^}]*position:sticky/s', $admin),
    'the last column of every card table is sticky, so the row action never leaves the viewport');
t_ok((bool) preg_match('/\.card table td:last-child,\s*\.card table th:last-child\{[^}]*right:0/s', $admin),
    '…pinned to the right edge');
// Without an opaque background the scrolling row shows through the pinned cell.
t_ok((bool) preg_match('/\.card table td:last-child,\s*\.card table th:last-child\{[^}]*background:var\(--panel\)/s', $admin),
    '…with an opaque background, or the row scrolls through it');
t_ok(strpos($admin, '.card table tr:hover td:last-child{background:var(--raised)}') !== false,
    '…and the hover tint follows, so the pinned cell is not read as a different row');

// A descendant selector, not a child one: the Users table is nested one level
// deeper inside its card, and `.card > table` silently missed it — which left
// 135px of that table still unreachable after the first attempt at this fix.
t_ok(strpos($admin, '.card > table td:last-child') === false,
    'the pin uses a descendant selector (a child combinator misses the nested Users table)');

t_group('admin tables — nothing defeats the pin');

// Inline styles outrank the stylesheet. `white-space:nowrap` on an actions cell
// forced it to its full content width, making the PINNED column itself wider
// than the screen — the pin was applied and the buttons were still off-screen.
t_ok(!preg_match('/<td data-label=""[^>]*white-space:\s*nowrap/', $admin),
    'no actions cell carries an inline white-space:nowrap (it beats the stylesheet and un-wraps the cell)');
t_ok((bool) preg_match('/\.card table td:last-child\{[^}]*white-space:normal!important/s', $admin),
    'and the override is in place for any that return');
t_ok((bool) preg_match('/\.card table td form\.inline,\s*\.card table td form\[style\*="inline-flex"\]\{[^}]*flex-wrap:wrap!important/s', $admin),
    'action forms wrap even when an inline display:inline-flex is present');

// The wrap must apply to ANY action cell, not only the pinned one: Users kept
// "new password / Reset" in a middle column, which pushed the table wide from
// the inside where the pin could not help.
t_ok(strpos($admin, '.card table td form.inline,') !== false,
    'the wrap targets every action cell, not just the last column');
t_ok(strpos($admin, '<th>Created</th><th>Actions</th></tr></thead>') !== false,
    'Users has ONE actions column — a second one sits outside the pinned edge and scrolls away');

t_group('admin tables — the stacked mobile layout opts out');

t_ok((bool) preg_match('/\.card table\.stack td:last-child,\s*\.card table\.stack th:last-child\{position:static/s', $admin),
    'stacked rows drop the pin (they are full-width cards; nothing overflows)');

t_group('admin — the pending queue includes money');

// The queue's own heading claims "everything pending across the academy, one
// list". It listed applications, registrations, proposals, messages, faculty
// and tickets — and no payments, while 11 bank transfers waited. An incomplete
// queue under a complete-sounding heading is worse than none, because it is
// trusted.
t_ok((bool) preg_match("/FROM payments WHERE status = 'pending_bank_transfer'.*needs\[\] = \['bank transfer'/s", $admin),
    'bank transfers awaiting confirmation appear in "Needs your attention"');
t_ok(strpos($admin, "'?v=payments&f=bank'") !== false,
    '…and the link lands on those rows, not on page 1 of every payment');
t_ok(strpos($admin, "\$bankOnly = ((\$_GET['f'] ?? '') === 'bank');") !== false,
    'and the payments view implements that filter');

t_group('admin — statuses are rendered as words');

t_ok(function_exists('str_contains') && strpos($admin, 'function mam_admin_status(') !== false,
    'there is one helper that turns a status column value into a label');
t_ok(strpos($admin, "'pending_bank_transfer' => ['Awaiting bank transfer', 'pending']") !== false,
    'pending_bank_transfer reads as "Awaiting bank transfer"');
// It also had NO pill styling: `.pill` colours key off the status as a class
// name and there is no `.pill.pending_bank_transfer` rule, so the most common
// status in the table got the bare base pill — no background at all — beside
// two neighbours that were coloured. It read like broken data.
t_ok(strpos($admin, '.pill.in_review,.pill.pending,') !== false,
    'and it borrows the existing `pending` colour rather than inventing one');
t_ok(!preg_match('/<td data-label="Status"><span class="pill <\?= htmlspecialchars\(\$r\[.status.\]\) \?>"><\?= htmlspecialchars\(\$r\[.status.\]\) \?><\/span><\/td>\s*<\/tr>\s*<\?php endforeach; \?>\s*<\/tbody>\s*<\/table>\s*<\?= mam_admin_pager/s', $admin),
    'the payments table no longer prints the raw column value as its own CSS class');

t_group('admin nav — folded by real use, never by opinion');

// Forty destinations, on an install where 34 of the backing tables are empty.
// Most of that list led to a screen saying "nothing here", so finding the three
// sections that mattered meant reading past thirty-seven that did not.
t_ok(strpos($admin, '$navProbe = [') !== false, 'each section declares what data would make it worth showing');
t_ok(strpos($admin, '$navInUse = function') !== false, 'and a predicate decides visibility from that');

// The three rules that keep this honest rather than an editorial guess.
t_ok(strpos($admin, "if (\$k === \$view) return true;") !== false,
    'the section you are currently on is never folded');
t_ok(strpos($admin, "if (!empty(\$navBadges[\$k])) return true;") !== false,
    'a section with pending work is never folded');
t_ok(strpos($admin, "if (!isset(\$navProbe[\$k])) return true;") !== false,
    'a section with no probe declared stays visible (nothing is hidden by omission)');

// A probe that errors must FAIL OPEN. Wrongly showing a link costs a glance;
// wrongly hiding one costs an admin who cannot find their work.
t_ok((bool) preg_match('/catch \(Throwable \$e\) \{ \$cache\[\$t\] = true; \}\s*\/\/ fail OPEN/', $admin),
    'a broken probe leaves the section VISIBLE rather than hiding it');

// Existence, not COUNT(*): this runs on every page load, and an academy with
// 40k attendance rows should not pay for a full count to draw a sidebar.
t_ok(strpos($admin, "'SELECT 1 FROM ' . \$t . ' LIMIT 1'") !== false,
    'visibility is an O(1) existence probe, not a COUNT over the table');
// The table name is interpolated, so it must come from the registry above and
// never from a request — the same rule upper-admin.php's {SCOPE} follows.
t_ok(!preg_match('/\$navProbe\[[^\]]*\$_(GET|POST|REQUEST)/', $admin),
    'the interpolated table name comes from the registry, never from a request');

t_group('admin nav — the fold is a default, not a restriction');

t_ok(strpos($admin, "class='nav-more-btn' aria-expanded='false'") !== false,
    'the fold is a real button with aria-expanded, not a mouse-only div');
t_ok(strpos($admin, "aria-controls='") !== false, '…wired to the panel it controls');
t_ok(strpos($admin, "not in use yet") !== false, 'it says how many are folded and why');
t_ok(strpos($admin, "localStorage.getItem(KEY)") !== false,
    'an admin who opens it gets it open next time — the fold is a default, not a lock');
t_ok(strpos($admin, "'mam.nav.open'") !== false, '…stored under one key');
t_ok(strpos($admin, ".nav-more-btn:focus-visible") !== false, 'and it has a visible focus state');
// The ⌘K palette indexes every view, so a folded section is still one keystroke
// away by name. That is what makes folding acceptable at all.
t_ok(strpos($admin, 'foreach ($navGroups as $__g => $__items)') !== false,
    'the command palette still indexes every view, folded or not');
