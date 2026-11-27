# Drive uploads via a Google Apps Script web app (#5) — no OAuth

The site uploads lesson videos and materials to Google Drive **without** OAuth or
Drive API credentials. Instead you deploy a tiny Apps Script web app that runs as
the Google account owning a Drive folder; the site calls it over HTTPS with an
HMAC-signed request. The gateway on the PHP side is `api/modules/integrations/lib/drive.php`.

## 1. Create the Apps Script

1. Go to <https://script.google.com> → **New project**.
2. Paste the code below into `Code.gs`.
3. **Project Settings → Script properties**, add:
   - `SHARED_SECRET` — a long random string (the same value you'll set as the
     site's `drive_shared_secret`).
   - `FOLDER_ID` — the Drive folder id to store files in (from the folder URL
     `.../folders/<FOLDER_ID>`). Optional; omit to use the account's root.
4. **Deploy → New deployment → Web app**: *Execute as* **Me**, *Who has access*
   **Anyone**. Copy the `/exec` URL — that's the site's `drive_webapp_url`.

```javascript
// Drive gateway for MAM Academy. Verifies an HMAC over (ts \n action \n sha256(body))
// with SHARED_SECRET, rejects requests older than 5 minutes, then uploads or
// returns files from FOLDER_ID. Deploy as a web app (execute as me, anyone).
function doPost(e) {
  try {
    var p = JSON.parse(e.postData.contents);
    var props = PropertiesService.getScriptProperties();
    var secret = props.getProperty('SHARED_SECRET') || '';
    var action = String(p.action || '');
    var body = action === 'upload' ? String(p.data || '') : String(p.file_id || '');
    // Replay window: 5 minutes.
    if (!p.ts || Math.abs(Date.now() / 1000 - Number(p.ts)) > 300) return json_({ ok: false, error: 'stale' });
    if (!verify_(secret, Number(p.ts), action, body, String(p.sig || ''))) return json_({ ok: false, error: 'bad_signature' });

    if (action === 'upload') {
      var bytes = Utilities.base64Decode(p.data);
      var blob = Utilities.newBlob(bytes, p.mime || 'application/octet-stream', p.name || 'upload');
      var folder = p.folder ? DriveApp.getFolderById(p.folder) : DriveApp.getRootFolder();
      var file = folder.createFile(blob);
      file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
      var id = file.getId();
      return json_({
        ok: true, file_id: id, name: file.getName(), mime: file.getMimeType(),
        view_url: 'https://drive.google.com/file/d/' + id + '/view',
        download_url: 'https://drive.google.com/uc?export=download&id=' + id
      });
    }
    if (action === 'fetch') {
      var f = DriveApp.getFileById(p.file_id);
      var b = f.getBlob();
      return json_({ ok: true, name: f.getName(), mime: f.getMimeType(), data: Utilities.base64Encode(b.getBytes()) });
    }
    return json_({ ok: false, error: 'unknown_action' });
  } catch (err) {
    return json_({ ok: false, error: String(err) });
  }
}

function verify_(secret, ts, action, body, sig) {
  var raw = ts + '\n' + action + '\n' + sha256hex_(body);
  var mac = Utilities.computeHmacSha256Signature(raw, secret);
  var expected = mac.map(function (b) { return ('0' + (b & 0xff).toString(16)).slice(-2); }).join('');
  // Constant-time-ish compare.
  if (expected.length !== sig.length) return false;
  var diff = 0;
  for (var i = 0; i < expected.length; i++) diff |= expected.charCodeAt(i) ^ sig.charCodeAt(i);
  return diff === 0;
}

function sha256hex_(s) {
  var d = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, s, Utilities.Charset.UTF_8);
  return d.map(function (b) { return ('0' + (b & 0xff).toString(16)).slice(-2); }).join('');
}

function json_(o) { return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON); }
```

> The signing payload must match `mam_drive_sign()` exactly:
> `HMAC-SHA256(ts + "\n" + action + "\n" + sha256_hex(body))`, where `body` is the
> base64 `data` for `upload` and the `file_id` for `fetch`.

## 2. Point the site at it

Set on the site (env or admin settings):

```
DRIVE_WEBAPP_URL=https://script.google.com/macros/s/AKfy.../exec
DRIVE_SHARED_SECRET=<the same SHARED_SECRET>
DRIVE_FOLDER_ID=<optional; matches the script's FOLDER_ID>
# DRIVE_TRANSPORT=live   (default; set to "mock" only for local dev/tests)
# DRIVE_MAX_MB=256       (upload size cap)
```

Until both `DRIVE_WEBAPP_URL` and `DRIVE_SHARED_SECRET` are set,
`mam_drive_enabled()` is false and the upload UI reports "not configured" — the
rest of the portal is unaffected.

## How it flows through the app

- Instructor uploads a file (`POST /api/instructor/drive-upload`) → the gateway
  stores it in Drive and records a **learning resource** (on the class or cohort)
  whose URL is the Drive view link. Because resources are dual-read (portal-audit
  S2), both class- and cohort-enrolled students see it.
- The file stays fetchable two ways: the stored view URL (link-shared), or the
  access-gated proxy `GET /api/drive/file?rid=<resource_id>`, which re-fetches the
  bytes server-side and streams them only to someone allowed to see that resource.
