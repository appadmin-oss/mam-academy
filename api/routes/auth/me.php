<?php
declare(strict_types=1);

$u = mam_current_user();
if (!$u) mam_ok(['authenticated' => false, 'user' => null, 'portals' => []]);
/* The portal list travels WITH the identity. `mam_portal_targets()` is the
   authority on where a role goes, and it already knew about 'parent' — but
   three clients carried their own copy of the map and none of them did, so a
   guardian was bounced to the homepage by portal/login.html, offered the
   student portal by portal/choose.html, and given a student-portal link by
   MAM_AUTH.portalFor(). Four copies of one fact is three too many; the
   clients render this list now instead of deciding for themselves. */
$roles = mam_user_roles($u);
mam_ok([
    'authenticated' => true,
    'portals' => mam_portal_targets($roles),
    'user' => [
        'id' => (int)$u['id'],
        'email' => $u['email'],
        'name' => $u['name'],
        'role' => $u['role'],
        'roles' => $roles,
        'avatar_url' => $u['avatar_url'],
        'must_change_password' => (int)$u['must_change_password'] === 1,
    ],
]);
