# Affidavit Management — Implementation Plan (for approval)

**Status: DRAFT. Date framed: 2026-08-18.**

> ## ⚠⚠ 2026-08-21 — THE AUTHORING HALF OF THIS PLAN IS SUPERSEDED
>
> **Everything below about WebDAV, `ms-word:ofe|u|`, `sabre/dav` and desktop Word is
> DEAD.** Do not implement it. Do not treat §2, §2.1–§2.5 or Phase 0/Phase 1 as current.
>
> **Why it died — and note it was NOT a technical failure of the server side.** The DAV
> endpoint was built and *passed every check* (see §2.3). What killed it is the client:
> **the desktop estate runs Office 2007**, and the `ms-word:` URI scheme was introduced in
> **Office 2013**. Proven on the dev box — no `ms-word`, `winword`, `office` or `ms-excel`
> key exists in `HKLM:\SOFTWARE\Classes` or `HKCU:\SOFTWARE\Classes`; `App Paths` points at
> `…\Office12\WINWORD.EXE` (Office12 = Office 2007); the installed-products list is entirely
> Office 2007. There is no registry or server change that makes the scheme work on 2007.
> `winword:` is **not** a substitute — it is not a URI scheme at all, and browsers will not
> launch a bare executable.
>
> **The replacement direction (user decision, 2026-08-21):** authoring moves to a
> **browser-based editor in a separate tab**. Upload `.docx` → user edits it online → the
> edited file lands back on the server → the Python `docxtpl` service merges tokens exactly
> as already planned. **Phases 3–5 (the Python merge service, the new generate path,
> config/permissions) are UNAFFECTED and remain valid.** Only the authoring transport
> changes.
>
> **Two candidate backends — decision NOT yet made, see §8.**
>
> Everything from §3 Phase 2 onward that concerns tokens, the merge service and generation
> still stands. Read §8 first, then skip §2 and Phase 0/1 entirely.

Goal: take affidavit template authoring and token merging **in-house**. Templates are
authored against a copy that lives on our server; token replacement at generate time is
done by our own Python + `docxtpl` service instead of DocuSign/PandaDoc.

This is a **new feature line**. It does not touch, revive, or depend on the parked
DocuSign M3 work or the paused DocuSign Document Generation work — those files stay
exactly as they are and the existing DocuSign routes keep working untouched.

---

## 1. What exists today (verified in code, not assumed)

| Piece | Where | Notes |
|---|---|---|
| Template upload | Library node form, `field_import_template` (`doc docx pdf rtf`) | Under Content → Library |
| Upload method popup | `assets/js/docusign_library_form.js` (lib v1.1) | Two cards: Template Upload / Document Generation |
| Token panel | `assets/js/token_picker.js` → `window.ApsTokenPicker` (lib v1.2) | Searchable, click-to-copy, `{label, value}` pairs |
| Token labels + values | `DocuSignTokenMapper::getJobVariableOptions()` | Label = `<Tab>-<Section>-<Field>` from the live Job form display; value = the token |
| Token → value for a Job | `DocuSignTokenMapper::resolveTokensForJob()` | Returns `[{name: 'Client.Mail', value: '…'}]`, via `_associatedps_pdf_templates_replace_value()` |
| Generate → Affidavit | `DocuSignController::generateViaDocuSign()` | Job → `field_documents` → `field_document` → `field_affidavit_template` → Library; **primary document wins**, else first valid |
| Template editor page | `DocuSignController::editorPage()` | Already renders `#docusign-token-picker-container` beside an iframe — the exact pattern we reuse |

The resolution chain, the token panel, and the token resolver are all **reusable as-is**.
The new feature only replaces *where the document lives* and *who does the merge*.

---

## 2. The one load-bearing technical risk — read this first

Requirement 7 ("on user save, the document automatically updates the server copy") is the
whole feature's hinge, and it is **not** something a plain HTTPS URL can do.

To be clear about terms: **WebDAV is not a separate protocol or a driver.** It is an
extension of HTTP (RFC 4918) on the same port 443, adding `PROPFIND`, `PROPPATCH`, `LOCK`,
`UNLOCK`, `MKCOL`, `COPY` and `MOVE` to the ordinary `GET`/`PUT`/`OPTIONS` verbs. Nothing is
blocking us from using it — the capability simply is not switched on yet.

### 2.1 The handshake that decides everything

When a user clicks `ms-word:ofe|u|https://host/path/file.docx`, Word does this:

1. `OPTIONS https://host/path/file.docx` — and reads the response headers, looking for
   `DAV: 1,2` and `MS-Author-Via: DAV`.
2. **Header absent** → Word abandons server editing, `GET`s the file into a local temp
   cache, and opens it read-only. Ctrl+S silently degrades to *Save As* onto the user's own
   disk. **Requirement 7 is dead, and it dies quietly.**
3. **Header present** → `PROPFIND` (metadata) → `LOCK` (exclusive write lock) → `GET`
   (content) → user edits → `PUT` (save) → `UNLOCK`. The server copy updates.
   **Requirement 7 works.**

Apache serving a static `.docx` out of `sites/default/files/` never emits that header. That
is the whole problem.

⚠ **Setting the header alone does nothing.** `Header set DAV "1,2"` would make Word *try*
the DAV verbs, and Apache would answer `405 Method Not Allowed` because nothing implements
them. The header has to come from a real DAV implementation. See §2.3 for what emits it.

### 2.2 Second constraint: Word does not send the Drupal session cookie

Word has its own HTTP stack, separate from the browser. So the DAV endpoint needs
authentication Word can actually perform.

Recommended answer to both constraints: a **capability URL** —
`https://host/aps-dav/<64-char-random-token>/<filename>.docx` — served by a DAV endpoint
inside Drupal. Word passes the whole URL, so there is no credential prompt at all, and the
token is single-purpose, time-limited, tied to one node + one user, and revocable.

### 2.3 What actually makes the header appear — two options

| Option | Pros | Cons |
|---|---|---|
| **A. `sabre/dav` in a Drupal `http_middleware`** ⭐ recommended | Pure composer + code, **no root, no server-config change**. Deploys by file copy, matching the staging process. Capability token in the path is trivial to match. Drupal authorises, logs and versions every write. | New composer dependency; must confirm mod_security does not strip the verbs. ⚠ **Must be middleware, not a controller** — see below |
| B. Apache `mod_dav` + `mod_dav_fs` | Battle-tested, no PHP in the write path | Needs **root** on staging; writes bypass Drupal, so no versioning or audit trail; token-in-path is awkward, pushing us toward Basic auth and a credential prompt |

**Option A — what emits the header.** `Sabre\DAV\Server` answers `OPTIONS` itself, emitting
`DAV: 1, 3, extended-mkcol` plus `MS-Author-Via: DAV` and an `Allow:` list. The `2` that
Word wants for locking appears **only once `Sabre\DAV\Locks\Plugin` is registered with a
backend** — registering it is not optional for this feature.

Install: `composer require sabre/dav`. Nothing else. PHP 8.3.14 ✓ and Composer 2.8.11 ✓ are
already present. **Installed 2026-08-18** — `sabre/dav 4.7.1` plus 5 transitive packages,
0 updates and 0 removals, so no collateral upgrades to the Drupal stack.

⚠ `composer require` also triggers Drupal's **scaffold plugin**, which rewrites
`web/.htaccess`, `web/robots.txt` and the two `web/sites/default/default.*` files whether or
not you asked. `web/.htaccess` carries a hand-added (commented-out) HTTPS redirect block and
the refresh drops it, while flipping `ExpiresDefault` from 2 weeks to 1 year site-wide.
**Check `git status` after any composer command here and revert the scaffold files.**

⚠⚠ **A Drupal controller CANNOT serve WebDAV. Proven by the Phase 0 spike, 2026-08-18 —
this supersedes the route-based approach originally written here.**

Two independent core behaviours block it, and neither can be configured away from a route:

| Blocker | Where | Effect |
|---|---|---|
| `RouteMethodSubscriber` | `RoutingEvents::ALTER`, priority 5000 | Any route **without** an explicit `methods:` is forced to `['GET','POST']`. Every DAV verb 405s. |
| `OptionsRequestSubscriber` | `KernelEvents::REQUEST`, priority 1000, **before routing** | Answers `OPTIONS` itself with its own `Allow:` header. No controller ever runs, so `DAV: 1,2` can never be emitted. |

Measured verb matrix on the spike route (`GET`/`HEAD` 200; `POST` 501 *from sabre*, proving
it reached the controller; `OPTIONS` 200 *from Drupal, not ours*; `PUT`, `DELETE`,
`PROPFIND`, `LOCK`, `UNLOCK`, `MKCOL` all **405**).

Declaring every verb explicitly in `methods:` fixes the 405s, but **nothing declared on a
route can fix `OPTIONS`** — that subscriber runs before routing exists. Since `OPTIONS` is
the handshake the whole feature depends on (§2.1), the DAV endpoint must sit **in front of
the kernel**:

- Implement it as an **`http_middleware`** tagged service, intercepting the DAV path prefix
  before Drupal's kernel dispatches any event. This bypasses the OPTIONS subscriber, route
  method filtering and the router altogether, in one move.
- Authorise with the **capability token from the path** — per §2.2 Word sends no session
  cookie, so there is nothing for a permission check to read anyway.
- The middleware still has the full Drupal container available for token validation,
  logging and versioning.

✅ **The middleware approach is now PROVEN on local WAMP, 2026-08-19.** Same sabre code that
failed as a controller works unchanged behind `http_middleware`:

```
DAV: 1, 3, extended-mkcol, 2
MS-Author-Via: DAV
Allow: OPTIONS, GET, HEAD, DELETE, PROPFIND, PUT, PROPPATCH, COPY, MOVE, REPORT, LOCK, UNLOCK
X-Sabre-Version: 4.7.1
```

Measured verb matrix, all previously **405**: `PROPFIND` 207 with valid multistatus XML;
`LOCK` 200 returning a `Lock-Token`, with a second conflicting `LOCK` correctly refused;
`UNLOCK` 204 clearing it; `PUT` of a 44 KB `.docx` landing on disk **byte-identical (md5
verified)**. Normal site pages unaffected — the path-prefix fall-through costs one `strpos`.

Three implementation details are load-bearing and must carry into Phase 1:

- **Priority 600**, above every core middleware (`ajax_page_state` 500, `negotiation` 400,
  `reverse_proxy` 300, `cors` 250, `page_cache` 200, `content_length` 140,
  `kernel_pre_handle` 100, `session` 50). Content negotiation would otherwise try to resolve
  a request format for a `PROPFIND`, and page cache must never see a DAV response.
- **Declare no constructor arguments** in `*.services.yml`. `StackedKernelPass` prepends the
  inner kernel as the first argument automatically; declaring it injects it twice.
- **Do not let sabre write to the SAPI.** It calls `header()` and streams to `php://output`,
  but Symfony sends its own headers first, so every sabre header — `DAV:` included — is
  silently discarded once output has started. Replace `Server::$sapi` with a subclass whose
  `sendResponse()` captures the response object, then translate it into a Symfony `Response`.
  `exec()` + `exit()` also works but skips kernel termination and cannot be composed.

⚠ A fatal error in a middleware takes down **every page on the site**, not just the DAV path.
`php -l` before `drush cr`. Recovery is deleting the `services.yml` and rebuilding.

⚠ Test destructive verbs against a **scratch filename**, never the fixture — a `PUT`/`DELETE`/
`MKCOL` sweep across the test document silently replaced it with an empty file, then a
directory of the same name.

**Option B — what emits the header.** `mod_dav` hooks the `OPTIONS` handler and emits
`DAV: 1,2` for any path where `Dav On` is set. Locking (the `2`) needs `DavLockDB` pointing
at a directory the web server user can write.

Local WAMP — the modules are already shipped, just uncomment `httpd.conf:118-120`:

```apache
LoadModule dav_module modules/mod_dav.so
LoadModule dav_fs_module modules/mod_dav_fs.so

DavLockDB "D:/wamp64/tmp/DavLock"
Alias /aps-dav "D:/wamp64/dav-store"
<Directory "D:/wamp64/dav-store">
    Dav On
    Options None
    AllowOverride None
    Require all granted
</Directory>
```

Staging (Debian/Ubuntu):

```bash
sudo a2enmod dav dav_fs
sudo systemctl restart apache2
```

with the equivalent `Alias` / `<Directory>` block in the vhost and
`DavLockDB /var/lock/apache2/DavLock` (must be writable by `www-data`).

Note the `Alias` matters: it maps `/aps-dav` to a directory **outside** DocumentRoot, so
Drupal's `.htaccess` rewrite never sees the request. A DAV directory placed *inside* the
docroot would need an explicit rewrite exclusion.

### 2.4 Verified environment findings (checked, not assumed)

- **Staging is a real Linux VPS**, not shared hosting — `dev.stallioni.com.conf` shows a
  Debian/Ubuntu Apache vhost (`/var/www/…`, `${APACHE_LOG_DIR}`, `AllowOverride All`) with a
  forced HTTPS redirect. **Both DAV options are therefore open to us**, and the Phase 3
  Python service can run under systemd.
- **`web/.htaccess` contains no `<Limit>` / `<LimitExcept>` blocks**, so nothing there
  rejects the DAV verbs. Its catch-all rewrite (lines 129–132) only fires for paths that are
  not real files — which is exactly what routes `/aps-dav/…` into Drupal under Option A,
  with no `.htaccess` change needed.
- Locally, `mod_dav.so` / `mod_dav_fs.so` / `mod_dav_lock.so` are all present under
  `bin/apache/apache2.4.62.1/modules/` and commented out at `httpd.conf:118-120`.

### 2.5 Client-side requirements — nothing to install

| Item | Status |
|---|---|
| **WebClient service** (the Windows WebDAV redirector) | Built into Windows. On this box: `Stopped` / `Manual` — the normal default; it trigger-starts when a DAV URL is hit. If unreliable in practice, set to Automatic — a setting, not an install. |
| **Word desktop 2016+** | Already deployed to staff. |
| **Valid HTTPS certificate** | Required — Word degrades on self-signed. Staging already force-redirects to HTTPS ✓. |
| **Trusted Sites / Trusted Locations** | Add the domain in Word's Trust Center or IE/Edge Trusted Sites, otherwise Word may open in **Protected View (read-only) even when DAV is working correctly**. One-time per PC, or pushed by Group Policy. |
| Registry `FileSizeLimitInBytes` | Default 50 MB, far above any `.docx`. Expect not to touch it. |
| Registry `BasicAuthLevel` | Only relevant to Basic auth over plain HTTP. The capability-URL design avoids Basic auth entirely. |

**Because this risk is load-bearing, Phase 0 is a spike that must pass before any other
phase starts.**

---

## 3. Phases

### Phase 0 — Spike: prove the Word round trip (½–1 day)

> **CLOSED 2026-08-21 — ABANDONED. Do not run these steps.**
>
> **Outcome: server side PASSED, client side IMPOSSIBLE.** Steps 1–2 succeeded completely
> once the route+controller was replaced by an `http_middleware` (§2.3): `OPTIONS` returned
> `DAV: 1, 3, extended-mkcol, 2` + `MS-Author-Via: DAV`, `PROPFIND` 207, `LOCK` 200 with a
> `Lock-Token`, a conflicting `LOCK` correctly refused, `UNLOCK` 204, and a 44 KB `.docx`
> `PUT` back byte-identical (md5 verified).
>
> **Steps 3–6 can never run on this estate.** They require `ms-word:ofe|u|`, which needs
> Office 2013+; the desktops run Office 2007. See the banner at the top of this file.
>
> **Still to clean up (throwaway, safe to delete whenever):**
> `drush pmu aps_dav_spike -y`, then delete `web/modules/custom/aps_dav_spike/`,
> `private/dav_spike/` and `private/dav_spike_locks`. Optionally `composer remove sabre/dav`
> — ⚠ if you do, `git status` and revert the scaffold rewrites of `web/.htaccess` etc.
> ⚠ The middleware sits in front of **every** request; if it is left in place and later
> breaks, the whole site 500s. Removing it is the safer default once the pivot is committed.
>
> **What was worth having:** the three findings in §2.3 about `StackedKernelPass`,
> middleware priority and SAPI capture are the only durable output of this phase, and they
> apply to any future middleware in this project — not just DAV.

Smallest possible slice, thrown away afterwards if it fails:

1. `composer require sabre/dav` ✅ *(done)*; stand it up behind one throwaway
   **`http_middleware`** — *not a route or controller, see §2.3* — serving one hard-coded
   `.docx`, with `Sabre\DAV\Locks\Plugin` registered (without it there is no `2` in the
   `DAV:` header).
2. **Check the handshake before involving Word at all** —
   `curl -i -X OPTIONS https://…/aps-dav/<token>/test.docx` must return `DAV: 1,2` and
   `MS-Author-Via: DAV`. This is the single cheapest go/no-go signal in the whole feature;
   if it is missing, nothing downstream can work.
3. From a staff PC: click `ms-word:ofe|u|https://…` → confirm Word opens it **for editing**
   (title bar shows the server path, not "Read-Only" / "Protected View").
4. Type a change, Ctrl+S, close Word.
5. Confirm the file on the server changed.
6. Repeat on **staging over real HTTPS** — local WAMP passing proves nothing about the host.

**Exit gate:** if step 5 fails on staging, stop and re-decide. Fallback ladder, in order of
preference: (a) switch to Option B, `sudo a2enmod dav dav_fs` — now known to be available
since staging is a root-capable VPS (§2.4); (b) accept a manual download → edit → re-upload
loop for authoring only, keeping everything else in this plan.

If step 3 fails while step 2 passed, the cause is almost always client-side Protected View,
not the server — see §2.5 Trusted Locations.

---

### Phase 1 — Server document store + DAV edit sessions

**New service `WordTemplateStore`**
- Stores master templates under `sites/default/files/word_templates/<uuid>/v<N>/<name>.docx`.
- Keeps every version (Word saves are cheap to version, and an author overwriting a live
  template with a mistake is otherwise unrecoverable).
- Directory gets an `.htaccess` deny — the files are reachable **only** through the DAV
  route, never by guessing a public path.

**New service `EditSessionManager`** + new table `aps_word_edit_sessions`
`(token, kind[template|generated], nid, job_nid, path, uid, created, expires, last_write)`
- `mint(path, uid, ttl)` → 64-char token.
- `validate(token)` → path + permission check, or 403.
- Every `PUT` logs the write and bumps the version.
- Cron prunes expired sessions (default TTL: 8 hours — long enough for a work day).

**New DAV `http_middleware`** — *not a controller, and not a route*; see §2.3 for why a
controller cannot work. It intercepts the `/aps-dav/` path prefix ahead of Drupal's kernel,
resolves `{token}` to exactly one file, and hands that single-file tree to `sabre/dav` with
`Locks\Plugin` registered. Everything else falls through to `$this->httpKernel->handle()`
untouched, so no other request in the site is affected.

---

### Phase 2 — Template authoring UX (requirements 3–8)

**Library upload popup** gains a **third card**: *"Word Template — edit in MS Word, merged
in-house"* (`.docx` only). The two DocuSign cards stay exactly as they are; this follows the
precedent already set when Document Generation was added alongside Template Upload.

**New route** `/admin/content/word-template/ajax-upload` (POST, multipart)
→ store the `.docx` via `WordTemplateStore` → mint an edit session → return
`{ key, wordUrl, httpUrl, editorUrl }`.

**New route + page** `/admin/content/word-template/editor/{nid}` — the authoring page.
Deliberately mirrors `DocuSignController::editorPage()`, minus the iframe:
- **"Open in MS Word"** primary button → `ms-word:ofe|u|<https DAV url>`
- The **existing token panel** — same `ApsTokenPicker`, same
  `DocuSignTokenMapper::getJobVariableOptions()` label/value pairs, so the author sees the
  identical `<Tab>-<Section>-<Field>` list they already know, and clicking copies the
  placeholder to the clipboard to paste into Word. *(Requirement 8.)*
- A "Download a copy" link and a plain-HTTPS view link as fallbacks.
- **"Done — Return to Template"** → writes the key onto the Library node, returns to the
  Library form. (Same callback shape as `editorCallback()`.)
- Live "last saved from Word: HH:MM" indicator polling `last_write`, so the author gets
  positive confirmation the save-back actually worked.

**New field** `field_word_template_key` on Library (string 255, readonly, on the default
form display) — update hook **8012**, written in code not config export, because
`config/sync` has drifted from both environments.

`.libraries.yml`: new `word-template-editor` entry; bump `docusign-library-form` to **1.2**
for the third card. (The pinned `version:` is what makes the `?v=` bust — mandatory here.)

---

### Phase 3 — The Python merge service (requirement 10 iii–v)

Lives **outside the webroot**, e.g. `/services/aps-docgen/`:

```
app.py            FastAPI application
render.py         docxtpl render + pre-normalisation
requirements.txt  fastapi, uvicorn, docxtpl, python-multipart
README.md         run/deploy notes
```

**`POST /render`** — `multipart/form-data`: `file` (the `.docx`) + `data` (JSON token→value
map). Header `X-APS-Key: <shared secret>`. Returns the merged `.docx` as a binary stream.
**`GET /health`** — used by the config form's "Test connection" button.

Bound to `127.0.0.1` only (Drupal and Python are on the same box), so the shared secret is
defence in depth rather than the only wall. Run under `uvicorn` — systemd unit on Linux,
NSSM service on Windows.

**Two implementation details that will otherwise bite:**

1. **Placeholder syntax.** `docxtpl` is Jinja2 — it merges `{{ Client_Mail }}`, *not*
   `[node:field_email]`. Recommendation: the token panel copies **docxtpl-native**
   placeholders derived from the names the mapper already produces
   (`Client.Mail` → `{{ Client_Mail }}`). This is not just compatibility — it is what
   unlocks `{%tr for row in … %}` **dynamic table rows**, which is the exact capability the
   DocuSign Document Generation trial was chasing and never reached. The service also
   pre-converts any legacy `[node:…]` token it finds, so old templates are not orphaned.
2. **Run splitting.** Word fragments typed text across XML runs (spellcheck, autocorrect),
   which can split `{{` from the variable name and make the placeholder invisible to
   docxtpl. `render.py` runs a paragraph-level run-merge pass before rendering. Without
   this, tokens will fail intermittently and look like random bugs.

---

### Phase 4 — New generate path (requirements 9, 10 i–ii, 11)

**New service `WordMergeClient`** — Guzzle multipart POST to the Python service, explicit
timeout, error mapped to a clean message, constructor-injected (matches `XeroClient`).

**New controller `WordTemplateController::generateAffidavit({jobId})`** on
`/word-template/generate-affidavit/{jobId}`:

1. Resolve the Library template using the **existing** logic — primary-document precedence,
   category match, manual `library_nid` override. Extracted from
   `DocuSignController::generateViaDocuSign()` into a shared helper so both flows use one
   copy and cannot drift apart.
2. `DocuSignTokenMapper::resolveTokensForJob($jobNode)` → the token→value map. **Unchanged
   and reused** — this is exactly the JSON that requirement 10 iii describes.
3. POST template + JSON to Python; receive the merged `.docx`.
4. Store it as a generated document, mint an edit session, record it against the Job.
5. Return `{ wordUrl, httpUrl, docId }`.

**Front end**: `word_generate.js` opens `ms-word:ofe|u|…` so the finished affidavit lands
straight in Word desktop, editable, saving back to our server. *(Requirement 11.)* It reuses
the full-screen loader already built for the Generate → Affidavit wait.

Wiring on the Job form: `resolveLibraryTemplate()` gains a third precedence step —
**Word template → docgen → tabs** — so a Library item set up the new way routes to the new
flow while everything else behaves exactly as it does now. The user still presses one
Generate button.

---

### Phase 5 — Config, permissions, housekeeping

- **`/admin/config/associatedps/word-docgen`** — service URL, shared key, session TTL,
  "Test connection" button. Menu link under Configuration (weight 38).
- `.env`: `APS_DOCGEN_URL`, `APS_DOCGEN_KEY`, `APS_DOCGEN_TIMEOUT`, `APS_DAV_SESSION_TTL`.
- Logger channel `word_docgen`, mirroring the `docusign` channel.
- New permission `administer word templates`; DAV writes additionally re-check the session
  owner.
- Cron: prune expired sessions, and old generated documents past a retention window.
- `.htaccess` deny inside the storage directory; correct `.docx` MIME on the DAV route.

---

### Phase 6 — Deploy & verify

Per house process: **manual file copy** to staging (never `drush cim`, never `git pull`,
never copy `settings.php`), then `drush updb` for hook 8012, then `drush cr`. Verify the
library `version:` pins actually bumped — a stale pin survives a cache rebuild and will make
this look broken when it is not.

Python service deployed separately and started as a system service. Staging round-trip
re-tested end to end: upload → Word edit → save-back → Generate → Word opens merged doc.

---

## 4. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Word save-back does not work on the host | **Feature-defining** | Phase 0 spike, gated, before any other work. The `curl -X OPTIONS` check in step 2 gives the answer in minutes |
| ~~Apache / mod_security blocks `PROPFIND` / `LOCK`~~ | Reduced | `web/.htaccess` verified to contain no `<Limit>` / `<LimitExcept>` blocks (§2.4). A host-level mod_security rule is still possible — the Phase 0 spike on staging covers it |
| ~~No server-config access for `mod_dav`~~ | **Resolved** | Staging is a root-capable Linux VPS, not shared hosting (§2.4). Option B is a genuine fallback, not a hope |
| Word opens in Protected View despite DAV working | Looks identical to a server failure | Trusted Locations / Trusted Sites per §2.5. Diagnose by whether the `OPTIONS` check passed |
| No Python on the servers | Phase 3 blocked | **Python is not installed on the local dev box** — confirm both boxes before Phase 3. Staging being a VPS means installing it is straightforward |
| ~~Shared / managed hosting forbids a long-running Python process~~ | **Resolved** | VPS with systemd available (§2.4). Fallback (PHP shelling out to a Python script per request) retained but no longer expected |
| Run splitting silently breaks tokens | Looks like random merge failures | Normalisation pass, plus a template pre-flight check that reports unresolved placeholders |
| Two people open the same template in Word | Lost edits | DAV `LOCK` support + versioning; last-write-wins stays recoverable rather than silent |
| Capability URL leaks (pasted into an email) | Unauthorised template access | Short TTL, single-file scope, revocable, every access logged |
| `ms-word:` blocked by browser or Group Policy | Cannot open in Word | Always render a plain download link beside the button |

---

## 5. Assumptions I am proceeding on unless told otherwise

1. **DocuSign stays installed and untouched.** This feature is added alongside it, not
   swapped in over it. Existing Library items keep generating through DocuSign until they
   are deliberately re-authored the new way.
2. **E-signature is out of scope here.** Requirement 10 removes the third party from the
   *merge*. If affidavits still need signing afterwards, that stays a separate DocuSign step
   and is not part of this plan.
3. **Staff are on Windows with Word desktop installed.** The `ms-word:` scheme and WebDAV
   behave differently on Mac and do not exist on mobile.
4. **Drupal and Python run on the same server**, so the merge service can be localhost-only.
5. **Affidavit category first.** Correspondence follows the same path once Affidavit is
   proven.

---

## 6. Open questions for you

1. **Staging server access** — *partly answered.* Staging is a Debian/Ubuntu VPS with its own
   Apache vhost (§2.4), so installing Python and enabling `mod_dav` are both technically
   possible. What I still need from you: **do we have root/sudo on it, and who holds it?**
   Option A needs no root at all, which is one reason it is the recommendation.
2. **Client PCs** — can Group Policy push the Trusted Sites entry (§2.5), or will that be a
   manual one-time step per staff machine?
3. **Placeholder syntax** — approve moving the token panel to docxtpl-native
   `{{ Client_Mail }}`? (Recommended: yes — it is what enables dynamic tables. Legacy
   `[node:…]` still handled.)
4. **Generated affidavits** — should the merged document also attach itself back onto the
   Job's Document record, or is opening it in Word enough for now?
5. **Retention** — how long should generated documents and their edit sessions be kept?

---

## 7. Rough sequencing

| Phase | Work | Depends on |
|---|---|---|
| 0 | Word round-trip spike | — |
| 1 | Store + DAV edit sessions | 0 passing |
| 2 | Upload UX + authoring page + token panel | 1 |
| 3 | Python merge service | Python available (can run parallel to 1–2) |
| 4 | Generate path + Word hand-off | 2 + 3 |
| 5 | Config, permissions, cron | 4 |
| 6 | Staging deploy + end-to-end verify | 5 |

Phases 1–2 and 3 are independent and can proceed in parallel.

⚠ Phases 0 and 1 in this table are **superseded** — see §8. Phases 3–5 are unaffected.

---

## 8. 2026-08-21 pivot — browser-based authoring (supersedes §2 and Phases 0–1)

**The requirement, restated by the user:** on uploading a `.docx`, the user is *not* given a
link that opens desktop Word. They are shown the document **in a browser tab**, edit it
online, and on save the **server copy** is updated. The Python `docxtpl` service then merges
job data into that server copy exactly as already planned.

**Driver:** the desktop estate is Office 2007, so `ms-word:` is impossible (see top banner).

**Hard constraint the user must understand:** Office for the web **cannot save to our
server**. It only reads and writes files held in OneDrive/SharePoint. Pointing Word Online at
our own storage requires the **WOPI** protocol, and Microsoft grants WOPI hosting only to
members of its Cloud Storage Partner Program — not available to us. So "saves straight back
to the server" is achievable *literally* only with Option 2 below.

### Option 1 — Microsoft 365 + Graph (real Word for the web)

```
Drupal ──Graph upload──▶ SharePoint library (our tenant)
                               │  user opens webUrl in a new tab,
                               │  edits in Word for the web (autosaves to SharePoint)
Drupal ◀──Graph download───────┘  ← this is the "save to server" step
```

- The file **must stay in our SharePoint library**. If the user opens it in *their own* M365
  account the edited copy lands in their personal OneDrive and there is no route back.
- ⭐ **Fidelity argument, and it is the strong one:** the documents are for judicial use, so
  font and layout matter. A `.docx` saved by Word for the web *is* a Word file — Microsoft's
  own code wrote it. No third-party interpretation of OOXML anywhere in authoring.
- ⚠ **No "finished" signal.** Word for the web autosaves continuously; there is no Save
  button and no completion event. Needs an explicit "Done editing" button in Drupal that
  triggers the pull-back, or Graph change-notification webhooks (which expire, need renewal
  and are eventually consistent). The button is the robust default.

**⚠⚠ LICENSING — PROBED 2026-08-21, AND IT IS THE BLOCKER.** Ran a read-only probe with the
existing `.env` credentials (`MS_GRAPH_TENANT_ID` / `_CLIENT_ID` / `_CLIENT_SECRET`):

```
GRANTED APPLICATION PERMISSIONS
  - Mail.Send            ← the complete list, nothing else
ENDPOINT PROBES
  GET /sites/root        403 accessDenied
  GET /sites/root/drives 403 accessDenied
  GET /subscribedSkus    403 Authorization_RequestDenied
```

⚠ Read those 403s correctly: **"the app may not ask"**, NOT "SharePoint does not exist."
Permission is evaluated before the resource is resolved, so this probe **cannot** see past it.
Probe script kept at `…\scratchpad\graph_probe.php` (reads `.env` itself, prints no secrets).

> ### ✅ 2026-08-21 — RESOLVED VIA GRAPH EXPLORER (delegated, signed in as `info@`)
>
> | Query | Result | Meaning |
> |---|---|---|
> | `GET /sites/root` | **200** | ⭐⭐ **SharePoint IS provisioned. The M365 path is OPEN.** An Exchange-Online-only tenant cannot return 200 here. |
> | `GET /me/licenseDetails` | **200** | Plan readable — ⚠ body not yet captured, still need `skuPartNumber` + `servicePlans` |
> | `GET /me/drive` | 403, even after consent | ⚠ **Does not matter — see below** |
>
> ⚠ **The `/me/drive` 403 is a non-issue and must not be treated as a blocker.**
> `/me/drive` is the *signed-in user's personal OneDrive*. This design deliberately does not
> use it: templates live in a **tenant-owned SharePoint document library** precisely so the
> file never sits in an individual's drive (if it did, we would lose the route back to it).
> `/sites/root/drives` is the endpoint that matters.
> If it is wanted anyway, the usual cause is that **Graph Explorer still holds the token
> minted before consent** — sign out and back in. Failing that, the `SharePoint Online`
> service plan may be disabled for that specific user even though the tenant has it.
>
> ⚠ **Still outstanding:** confirm **`SHAREPOINTWAC`** is `Success` in the `servicePlans`
> array of `/me/licenseDetails`. That service plan *is* Office for the web — SharePoint
> storage existing does **not** by itself mean the browser editors are licensed. Also run
> `GET /sites/root/drives` to see the libraries.

⭐ **Working Graph email proves nothing about files.** Three independent layers:

| Layer | State | Costs money? |
|---|---|---|
| App registration (client id/secret) | ✅ works | No — free, always available |
| API permissions granted to it | `Mail.Send` only | No, but needs **tenant admin consent** |
| **Licence on the tenant** | ❓ **UNKNOWN — decides everything** | **Yes** |

`Mail.Send` works because `info@associatedps.com.au` has a **licensed Exchange mailbox** —
i.e. the service behind the API was already paid for. Files are the same deal. There is no
free tier of SharePoint/OneDrive via Graph.

| Plan | Mail | SharePoint/OneDrive | Office for the web | ≈ cost |
|---|---|---|---|---|
| Exchange Online Plan 1/2 | ✅ | ❌ | ❌ | $4–8/user/mo |
| **M365 Business Basic** | ✅ | ✅ | ✅ browser only | ~$6/user/mo |
| **Business Standard** | ✅ | ✅ | ✅ + **desktop Office** | ~$12.50/user/mo |

⭐ If a licence change is needed anyway, **Business Standard also retires the Office 2007
problem** — two problems, one purchase.

**How to settle it (user action, no code):**
- ⭐ Fastest and needs no admin change — https://developer.microsoft.com/graph/graph-explorer
  signed in as `info@`, then `GET /me/licenseDetails` (names the plan outright),
  `GET /me/drive` (200 ⇒ OneDrive exists ⇒ plan includes it), `GET /sites/root`.
  Delegated identity, so the missing app permission does not block it.
- Authoritative: https://admin.microsoft.com/Adminportal/Home#/subscriptions
- If SharePoint loads at all, it exists: https://admin.microsoft.com/sharepoint
- No-admin sniff: https://www.office.com — do SharePoint/OneDrive tiles appear?

**One admin action is still required** (it is not a code change, and it is unaffected by
anything proven in Graph Explorer — that was *delegated*, Drupal runs **app-only**):
https://entra.microsoft.com → App registrations → our app → API permissions → add
**`Sites.ReadWrite.All` (Application)** → **Grant admin consent**.
⭐ `Sites.ReadWrite.All`, not `Files.ReadWrite.All` — we target a **site library**, not
personal drives. The app currently holds `Mail.Send` and nothing else.

### Option 2 — Self-hosted ONLYOFFICE Docs

Matches the stated requirement *literally* — edits in a browser tab and POSTs the saved
`.docx` straight back to our server. No Microsoft dependency, no per-user licensing, no admin
consent.

⚠ **It is NOT a library** — it is a **standalone server application** (own host/port, own
Postgres + RabbitMQ + Node services, ~4 GB RAM, Docker). Closest analogy: self-hosting our
own Google Docs. Only a small `api.js`, *served by that server*, lands in the Drupal page and
creates an iframe. It cannot be `composer require`d.

```
Drupal page ──iframe──▶ ONLYOFFICE Docs (VPS, own HTTPS cert)
      ▲                          │ server-to-server: fetches document.url from Drupal
      └── /onlyoffice/callback ◀─┘ POSTs status:2 ≈10s after last editor closes
```

Config keys that matter: `document.url` (server-side fetch, so a capability token works —
no browser session needed), ⚠ **`document.key`** (version id — **must change when the file
changes** or a stale cached copy is served; this is the classic integration bug),
`editorConfig.callbackUrl`, `editorConfig.mode`. Callback statuses: 1 started, 2 ready to
save, 4 closed unchanged, 6 force-save mid-session, 3/7 errors. A Save button = `forcesave`
→ status 6. ⚠ **Set `JWT_SECRET`** or anyone who finds the document server can point it at
arbitrary URLs.

Community Edition free (AGPL v3), capped at **20 simultaneous connections** — ample here.
⭐ Staging is a root-capable Debian VPS (§2.4), so this is genuinely installable.

⚠ Fidelity: high, because **OOXML is ONLYOFFICE's native internal format** — no lossy
conversion round trip as with LibreOffice-based editors. UI is deliberately Word-like
(ribbon). ⚠ **#1 real-world fidelity issue is missing fonts** — if a template font is not
installed *in the container* it substitutes and pagination shifts. **Install the template
fonts into the container before judging fidelity.** No VBA. Complex SmartArt/OLE weaker.
Needs its own cert and must be reachable *by the browser* **and** able to reach *back into
Drupal*.

*(Unverified: there may be an `onlyoffice` module on drupal.org. Check D10 support before
hand-rolling the integration.)*

### Comparison

| | ONLYOFFICE self-hosted | M365 + Graph |
|---|---|---|
| "auto-saves to our server" | ✅ literally, POSTs the file back | ⚠ saves to SharePoint; we pull it back |
| Per-user cost | none | Business Basic+ per editor |
| Depends on tenant admin | no | **yes** — consent + possibly a plan change |
| Blocked by current licensing | no | **possibly — unknown** |
| Ops burden | new service, Docker, cert, RAM | none |
| Fidelity for court documents | very good, not Word | **actual Word** |
| Unlicensed users can edit | ✅ anyone | only via anonymous edit links, if tenant allows |
| "Done" detection | built in (callback status 2) | must be engineered |

### ⚠ Fidelity risk is not only the editor

For judicial documents, fidelity can be lost in **three** places, and the editor is usually
not the worst:

1. **Authoring round trip** — Word for the web: zero risk. ONLYOFFICE: small, mostly fonts.
2. **Token merge** — `docxtpl`/`python-docx` edit XML in place without re-laying-out. Low.
3. ⭐ **Final render to PDF for filing** — *often the dominant risk*, and independent of 1–2.
   This project already uses **pandoc + pdftohtml**. If anything other than Word produces the
   filed PDF, *that* converter decides what the court sees. Settle this before optimising 1.

### ⚠ Unchanged by the pivot

The **paragraph-level run-merge pass** is still mandatory. Both Word and ONLYOFFICE split
typed text across XML runs, which breaks `{{ … }}` for docxtpl. Not a reason to prefer
either editor — just do not expect either to remove the step.

---

## 9. 2026-08-22 — Option 1 BUILT. ✅ ROUND TRIP VERIFIED END TO END.

**Decision taken: Option 1 (M365 + Graph).** Driver was fidelity — these are judicial
documents, and a `.docx` saved by Word for the web *is* a Word file. Option 2 (ONLYOFFICE)
was not pursued.

> ### ⭐ 2026-08-24 — REVERSED: anonymous sharing links are now the authoring transport
>
> Anonymous links were declined on 2026-08-21; that decision was **revisited and reversed**
> after the user asked why a `microsoftonline.com` session was needed at all.
>
> **The question, and the answer.** Our app-only token authorises the *Graph API calls*
> (upload / download / delete) and needs no user whatsoever. But Word for the web is a **page
> rendering in the author's browser**, and SharePoint authenticates that top-level navigation
> **by cookie**. A bearer token cannot be attached to a tab — `window.open()` sets no headers,
> and the token's audience is `graph.microsoft.com`, not the SharePoint front end. Pushing the
> client secret to the browser is out of the question: it carries tenant-wide write. So with
> the plain item URL, **only someone holding tenant credentials could author a template** —
> which is exactly the limitation the user objected to.
>
> **A sharing link is a capability, not an identity.** `POST /items/{id}/createLink` with
> `{"type":"edit","scope":"anonymous"}` returns a URL carrying an opaque share token recorded
> against **one item**. No account, no password; SharePoint issues a guest session scoped to
> that item and renders the editor. ⚠ `scope: organization` still demands a sign-in and does
> **not** solve this — `anonymous` is the only value that does.
>
> **✅ PROVEN AGAINST THIS TENANT 2026-08-24** by probe (upload throwaway `.docx` → createLink
> → open in a browser with no Microsoft session):
> - the link opened Word for the web **in edit mode with no sign-in**;
> - from that same clean session, `https://associatedpscomau.sharepoint.com/` was **not**
>   reachable — no profile, no tenant content. The token really is item-scoped.
>
> ⚠ An earlier test appeared to reach a profile page. That browser was **already signed in**;
> it was the user's own cookie session showing through, not the share token. **Always test a
> sharing link in a clean browser** — a signed-in one tells you nothing.
>
> **Exposure and how it is bounded.** The URL *is* the credential (bearer, no second factor),
> so it lives in the address bar, history and proxy logs. Four things bound it: scope is one
> file (versus the secret's tenant-wide write); a **1-hour expiry** is set on the link; the
> file is **deleted from SharePoint at Done**, which kills the link with it; and the audit
> trail is unaffected because the authoritative copy is ours and the Drupal node revision
> records the real logged-in staff member. SharePoint's own history shows "Guest Contributor",
> which is expected.
>
> **Built:** `createEditLink()` is no longer dead — it gained `$ttl` and now defaults to
> `anonymous`. New `GraphDriveClient::getAuthoringUrl()` degrades rather than fails:
> anonymous + expiry → anonymous open-ended (some tenants reject an explicit
> `expirationDateTime` that conflicts with their link policy) → plain `getEditUrl()` for
> signed-in staff. `M365TemplateController::startSession()` calls it, so **both** the first
> upload and the "Update document" re-edit get the link. The JSON carries `anonymousLink`, and
> the panel says so when the tenant refused, rather than leaving the author at an unexplained
> login screen. Library `docusign-library-form` → **1.6**.
>
> ⚠ Prerequisite that can silently withdraw this: SharePoint admin centre → external sharing
> must stay at **"Anyone"**, tenant *and* site collection. Drop it lower and `createLink`
> starts failing — the fallback keeps the feature working, but only for signed-in users.

> ### ✅ VERIFIED 2026-08-22 — the feature works
>
> User edited a document in Word for the web, pressed Done, and **confirmed the changes are
> present in the server copy**. Landed at
> `web/sites/default/files/template_uploads/<name>.docx` with a new node revision (node 544
> now has 5). The round trip is proven.
>
> ### ⚠⚠ CORRECTION — the earlier "licence blocker" was WRONG
>
> §9 previously recorded that editing was blocked by licensing, because
> `GET /me/licenseDetails` for `info@` returned `"value": []` and a document opened read-only
> straight from the SharePoint UI. **That diagnosis was wrong and must not be acted on.**
>
> The real cause was **file-specific**: `87977-a1.docx` (in
> `D:\Drupal\Stallioni\CaseManagement\SampleTemplates`) has **document protection enabled on
> one or more sections**. Word for the web refuses those outright — *"Word cannot open this
> document for editing in the browser because it has document protection enabled for one or
> more sections. To edit it please open it in the desktop version."* Every other `.docx`
> tested opens in edit mode normally.
>
> ⚠ The bisect test was run with that same protected file, which is exactly why the fault
> looked tenant-wide. **When a document opens read-only, check the document before checking
> the tenant.** No client credentials are needed; nothing is blocked on licensing.

### What is built and working

Entra grant landed (`Sites.ReadWrite.All`, Application, admin-consented). Verified against
the live tenant: site `Communication site` @ `associatedpscomau.sharepoint.com`, library
`Documents`, folder `AffidavitTemplates`. Upload, `cTag`, `webUrl`, download and delete all
confirmed working end to end with a real 228 KB `.docx`.

| File | Role |
|---|---|
| `src/Service/GraphDriveClient.php` | app-only Graph client; own token cache cid (⭐ deliberately NOT shared with `Microsoft365MailService` so production email is never at risk); upload/download/delete/edit-URL; `diagnostics()` |
| `src/Service/M365EditSessionStore.php` | `nid → (driveId, itemId, cTag)` mapping |
| `src/Controller/M365TemplateController.php` | `ajaxUpload` / `startSession` / `pull` / `discard` / `reopen` / `removeRemote` / `status` / `resetToken` |
| `update_8012()` | creates `m365_edit_sessions` (**run; table exists**) |
| `.routing.yml` | 6 routes under `/admin/content/m365/*` + `/admin/config/services/m365-templates` |
| `assets/js/docusign_library_form.js` | third card + the re-edit button — library version now **1.4** |
| `.module` | `_associatedps_affidavit_template_m365_settings()`; two-step Library add form; re-edit button; `hook_cron` |

### Re-editing later — the "Update at DocuSign" equivalent

A button appears on the Library **edit** form whenever the item already holds a `.docx`,
labelled with the filename, beside the DocuSign ones. `POST /admin/content/m365/reopen/{nid}`.

⭐ **It needs NO stored template id, and that is the point.** DocuSign keeps
`field_docusign_template_id` because the authoritative copy lives at DocuSign. Here the
authoritative copy is **ours** — `field_import_template` — and SharePoint is only ever
scratch space. So re-editing is simply "push the current server file up again". Nothing to
store, nothing to go stale. Do not add a field for this.

`ajaxUpload()` and `reopen()` both delegate to `startSession()`, so first upload and every
later edit produce an identical panel and the ids are captured in exactly one place.

### ⚠⚠ HTTP 423 resourceLocked on delete is NORMAL — do not treat it as a bug

Observed every time:

```
DELETE /drives/{id}/items/{id} failed (423):
  {"error":{"code":"notAllowed","message":"The resource you are attempting to access is locked",
   "innerError":{"code":"resourceLocked"}}}
```

**Word for the web holds a lock for several minutes after the last editor closes**, and Done
is pressed while that tab is usually still open — so this delete is close to guaranteed to
fail. ⚠ The pull itself always succeeded; only cleanup did not, which is why documents were
seen lingering in SharePoint after a successful save.

Handled rather than logged and forgotten:

- a failed delete parks the session `cleanup_pending` instead of `pulled`
- `hook_cron` retries anything pending for **10+ minutes** (past the lock)
- cron also sweeps sessions left `open` for **4+ hours** — the author closed the tab and never
  pressed Done, and a court document must not sit in SharePoint indefinitely
- repeated failures escalate to a warning, so a genuinely stuck file becomes visible
- the immediate failure logs at **info**, not warning — the expected case should not look like
  an error

### ✅ Token panel — built 2026-08-22

Container `#m365-token-panel` on the Library form, `#weight` 100, immediately **above the form
actions** — so it sits above both Save and the Done button. ⚠ `$form['actions']['#weight']` is
set to **101** explicitly; without that the panel can render *below* Save, which is exactly
where nobody looks.

⭐ Reuses `ApsTokenPicker` (the same component as the DocuSign editor page and the email
picker) fed from the same `DocuSignTokenMapper::getJobVariableOptions()` — **116 options**,
readable `Tab-Section-Field` labels. One source, so the labels an author sees while writing a
Word template cannot drift from the ones they see elsewhere. Carries `$template_field_states`,
so it shows for Affidavit/Correspondence and stays hidden for Email, which already has its own
picker (two on one form would be worse than none).

Library `docusign-library-form` now **1.5**, with `associatedps_affidavit_template/token-picker`
added as a dependency so `window.ApsTokenPicker` is guaranteed present.

Also: the re-edit button label is now the fixed string **"Update document"** — it previously
embedded the filename, whose length dictated the button width and made consistent styling
impossible. The filename still shows in the session panel.

### Still to do

1. ✅ **TOKEN SYNTAX SETTLED 2026-08-24 — Jinja2 `{{ … }}`.** The M365 panel now copies
   `{{ Attendance_Notes.Date_Time }}`, which is what `docxtpl` substitutes and what unlocks
   `{%tr for %}` **dynamic tables** — the capability the DocuSign Doc Gen trial chased and
   never reached.

   ⚠⚠ **Implemented as a SEPARATE method, at the user's explicit direction:**
   `DocuSignTokenMapper::getJobVariableOptionsJinja()` wraps the values;
   `getJobVariableOptions()` is **untouched** and still returns bare names. This is not
   stylistic — DocuSign's merge matches the **bare** name against a tab's Data Label, and both
   DocuSign editor pages read that method, so wrapping in place would have broken DocuSign
   authoring **with no visible error** (tokens would just stop resolving at generate time).
   The syntax itself lives in one place, `toJinjaToken()`. Labels are identical in both
   pickers; only the pasted text differs. Verified: 116 options each, raw side unchanged.
2. **Phases 3–5 — the Python `docxtpl` merge service.** IN PROGRESS — see **§10**, which
   supersedes the Phase 3 sketch above on two points. ⚠ The paragraph-level **run-merge pass**
   may be redundant: `docxtpl`'s own `patch_xml` already merges many split runs, so §10 calls
   for a fixture that *proves* whether we need our own pass rather than writing one on faith.
3. ⚠ **Non-.docx handling.** The re-edit button hides itself for `.doc`/PDF, but says nothing —
   a `.doc` template silently offers no option at all.
4. Consider detecting `w:documentProtection` in the OOXML and warning before opening, rather

---

## 10. 2026-08-26 — Python merge service: decisions settled, build started

**Status: paused mid-Phase-A at the user's request. Nothing written but a virtualenv.**
No Drupal code changed in this session. All findings below are verified against live local
data, not assumed.

### ⚠⚠ §Phase 3 above is STALE on two points

1. **Token syntax.** Phase 3 says `Client.Mail` → `{{ Client_Mail }}` (underscore-flattened).
   What actually shipped in `toJinjaToken()` is `{{ Client.Mail }}` — **dot preserved**.
   Templates authored since 08-24 use dots. **Decision: keep the dot**, solve it in Python.
   Re-flattening now would silently break templates already authored.
2. **`{%tr for %}` dynamic tables are not free.** Phase 3 implies Python unlocks them. It does
   not: the current resolver flattens multi-value fields into parallel comma-joined strings —
   `Job_Fees.Description => "Delivery Fee, Collection Fee"`, `Job_Fees.Amount => "35.00, 34.94"`.
   Real rows need a **structured payload built on the Drupal side**. Now IN SCOPE (below).

### ⭐⭐ The finding that dictates the whole design: parent/child collision

Every one of the 14 parent names is **both a scalar and a namespace**:

```
Affidavits, Attendance_Address_Served, Attendance_Notes, Client, Court, Cover_Letters,
Documents, Job_Fees, Job_Types, Other_Parties, Para_Address, Parties, Party_2, Service_Address
```

`Client` resolves to `"Australian Financial Security Authority"` **and** parents `Client.Mail`.
A flat dict fails (`{{ Client.Mail }}` needs attribute lookup); a nested dict fails
(`{{ Client }}` renders a dict). One object must serve both — a `str` subclass carrying a
children map, with `__getattr__` falling through to it:

- ⭐ Safe **only because** `formatVariableName()` Ucfirsts every segment (`Mail`, `Title`,
  `Address_Attendance`) while every `str` method is lowercase — so a child can never shadow
  `str.title`, `str.count`, etc. Assert this at context-build time; it is load-bearing.
- ⚠ Do **NOT** override `__len__` — truthiness of a scalar would break `{% if Client %}`.
- `__iter__` yields table rows when present, empty otherwise (never per-character).
- Pair with `jinja2.ChainableUndefined` so an author's typo renders empty instead of 500-ing.

### ✅ The data half already exists — no new extraction code

`DocuSignTokenMapper::resolveTokensForJob($jobNode)` already returns exactly the payload
required: `['name' => 'Client.Mail', 'value' => '…']`. Verified on job nid 0932733 —
**152 pairs, 51 non-empty**. Values are already `strip_tags()`-ed to plain text.

### ✅ ROUTING SETTLED — by file extension, no new field

User's call, and the data proves it unambiguous. Across all **40** Library nodes:

| Library shape | Count | Routes to |
|---|---|---|
| `.docx` on `field_import_template`, no DocuSign ID | 3 | **Python / FastAPI** |
| DocuSign ID, no file | 3 | DocuSign, unchanged |
| Neither | 34 | "No template" modal, unchanged |

**Zero overlap** — no Library carries both, so extension routing and ID routing can never
disagree. This closes the "add a `field_template_engine` marker" question: no new field, no
config export, **no staging drift**.

⭐ **Document nodes hold NO file of their own** — the bundle carries only
`field_affidavit_template` (→ Library), `field_court_name`, `field_notice_period`. So "the
document associated to the job" resolves to the **Library**, and the file to merge is its
`field_import_template`. Verified against the bundle definition and 5 live Document nodes.

**The change is surgical, almost all of it in `DocuSignController::resolveLibraryTemplate()`
(line ~848):**

- Add a **first** step: `field_import_template` holds a `.docx` → `['method' => 'word']`.
  Checked first as the direction of travel; changes nothing today given zero overlap.
- ⭐ The candidate filter at **line ~398** (`resolveLibraryTemplate() === NULL → continue`) is
  **fixed for free** — it only asks "is this NULL?", which is precisely why a docx-only
  Library is invisible to the generate flow today.
- ⚠ **Line ~425** treats an empty `$docusignTemplateId` as "no template found", so the `word`
  result **must** return a non-empty `id` — use `(string) $file->id()` or the modal fires.
- Add a `word` branch beside the docgen branch at **line ~439**.
- Untouched and shared: primary-document precedence, category match, the manual `library_nid`
  override, and the broken-reference guard (Document nid 535 has a dangling template ref).

### Decisions taken this session

| Decision | Answer |
|---|---|
| Engine discriminator | **File extension** (`.docx` → Python). No marker field. |
| Dynamic table rows | **IN SCOPE** — needs a structured payload from Drupal, not just Python. |
| Merged affidavit storage | **`private://`** — filled affidavits carry party names and addresses. |

### Environment

- ✅ **Python 3.14.7 installed** at `D:\Python3.14.7\python.exe`; `py -3.14` works.
- ✅ venv created: `services/aps-docgen/.venv` (pip 26.2.1).
- ⚠⚠ **The Bash tool's PATH does not see it** — `python` there hits the WindowsApps alias stub
  and reports "Python was not found". Use PowerShell with `py -3.14`, or call the venv
  interpreter by full path. This wasted a cycle; do not re-diagnose it as "not installed".
- ⚠ The venv sits **inside the repo** and will flood `git status`. Decide at commit time
  (a `services/aps-docgen/.gitignore` was proposed and deliberately deferred as premature).

### Planned layout (nothing written yet)

```
services/aps-docgen/
  app.py            FastAPI: POST /render (multipart file + JSON data, X-APS-Key), GET /health
  context.py        the Value(str) class + context builder  ← the hard part, see above
  render.py         docxtpl render, Jinja env, ChainableUndefined
  requirements.txt  fastapi, uvicorn, docxtpl, python-multipart — PINNED
  tests/            fixtures: collision, split-run, missing-token, table rows
```

Bound to `127.0.0.1` (same box as Drupal); the shared key is defence in depth, not the only
wall. Echo an `X-Request-Id` so a failure traces back to a Drupal watchdog entry.

### ⚠ Two pre-existing data defects that will become VISIBLE once we merge

Both live in the legacy replacer, both predate this work, both currently harmless because
nothing renders these values into a document:

1. **Two tokens resolve to the literal string `"Array"`** — `Array to string conversion`
   warnings at `associatedps_pdf_templates.module:350` and `:361`.
2. **`strip_tags()` collapses `<li>` lists with no separator** — so a lettered "documents
   served" list, which is exactly what an affidavit needs, merges as one run-on line.

⚠ Also: `resolveTokensForJob()` dumps the **entire** synthetic marker body to the log at
`notice` level on every call. Noisy in production; worth silencing before this goes live.

### ⚠⚠ On the critical path, and it is a CONTENT job not a code job

**The real affidavit templates have no files.** All 15 production-looking Library items
(`UCPA Affidavit 2016`, `Federal Court - General Affidavit of Service`, `Creditor's Petition`,
…) are `(no file)`. The only three `.docx` templates are `Docx Template` (445),
`Test Affidavit 3` (548), `Test Affidavit 4` (544) — all test items. The merge path will work
and deliver nothing until someone authors the real templates through the M365 Word flow.


### ✅ Phase A COMPLETE — 2026-08-27

The service is built and proven standalone. **Still no Drupal code changed.**

```
services/aps-docgen/
  app.py            FastAPI — POST /render, GET /health
  context.py        Value(str) + build_context()   ← the collision handling
  render.py         docxtpl render, Jinja env, assert_is_docx()
  conftest.py       puts the service root on sys.path for tests
  requirements.txt  pinned, resolved against Python 3.14.7
  tests/            30 tests, all passing
  README.md         run notes + the three Value constraints
```

**Proven against real data, not fixtures.** `87973-a1.docx` (nid 548) — a genuine 230 KB
template authored by staff through the M365 Word-for-the-web flow — merged with **152 live
tokens** from job 0932733: the nested placeholder `{{ Attendance_Notes.Employee_First_Name }}`
was substituted, no `{{` survived, and the document's 3 paragraphs and 2 tables came through
intact. Also smoke-tested over real HTTP under uvicorn (200 + correct MIME + echoed
`X-Request-Id`; 401 on a bad key; 422 with a *file-level* message on junk input).

#### ⭐ Two findings that overturn things written above

1. **The "mandatory" run-merge pass is NOT needed.** Word fragments typed text across XML
   runs, so `{{ Client }}` can be stored as `{{ Cli` + `ent` + ` }}`. §Phase 3 called a custom
   paragraph-level merge pass mandatory. It is not — docxtpl's own `patch_xml` already handles
   it. Two tests pin the finding so a future upgrade that regresses it fails the suite instead
   of a client's affidavit. **Complexity avoided by testing the assumption instead of coding
   around it.**
2. **⚠⚠ `DocxTemplate` does NOT read the file when constructed.** `__init__` only stores the
   handle and sets `self.docx = None`; the parse happens later inside `render()`. So a corrupt
   or renamed file survives construction and fails *during render*, where it is
   indistinguishable from a bad placeholder — the author is told to fix `{% for %}` tags in a
   template that is perfectly fine. Fixed with `assert_is_docx()`, which checks the container
   up front using stdlib `zipfile` (looking for the `word/document.xml` part). That depends on
   the **OOXML format**, not a docxtpl internal, so it cannot drift on upgrade.
   ⚠ Do not "simplify" this by trusting `DocxTemplate` to validate — it does not.

#### ⚠ Template survey — the content gap is worse than it looked

Only three `.docx` templates exist, and **two carry no tokens at all**:

| nid | File | Tokens |
|---|---|---|
| 445 | `ACAT Affidavit of Service.docx` | **none** — a real affidavit form, but filled by hand with `………` rules |
| 544 | `APSStandardInvoiceXero_Final.docx` | **none** — an invoice, and it uses Word MERGEFIELD chevrons (`«InvoiceDueDate»`), not our syntax |
| 548 | `87973-a1.docx` | **1** — `{{ Attendance_Notes.Employee_First_Name }}` |

So nid 544, named in the resume note above as the Phase A target, was the **wrong** file — it
is an invoice with zero placeholders. nid 548 is the only genuinely token-bearing template.

⚠ Note `«…»` MERGEFIELD syntax in 544: if staff author with Word's own mail-merge fields
instead of pasting our tokens, nothing merges and there is **no error** — the document simply
comes out unchanged. Worth detecting and warning about.

#### ⚠ Also learned

`find_placeholders()` returns **root names only** — a template using
`{{ Attendance_Notes.Employee_First_Name }}` reports just `Attendance_Notes`, because the rest
is attribute access, not context resolution. Do not read its output as the full token list.

#### Next step

Phase B — the Drupal side: `WordMergeClient` (Guzzle multipart, `.env` config, matches
`XeroClient`), the `word` branch in `DocuSignController::resolveLibraryTemplate()` (see the
line references above), and merged output into `private://`. Gate: a drush call produces a
correct merged file for a Job.

---

### ✅ Phase B COMPLETE — 2026-08-27

The Drupal half is built and the gate is met: a drush call produces a correct merged affidavit
for a real Job, through the real service chain.

#### ⭐ ARCHITECTURE DECISION (user, 2026-08-27): standalone, ZERO DocuSign edits

I proposed branching inside `DocuSignController::resolveLibraryTemplate()` — the small diff
recorded in §10 above. **The user rejected it**, on the grounds that our in-house template
management should not live inside the controller we are retiring. Correct call.

So `WordTemplateController` is now the entry point for Generate → Affidavit and carries **its
own copy of the document walk**. When the resolved template is not a `.docx` it calls
`DocuSignController::generateAffidavit()` through the class resolver — a public method, so
that class is **completely untouched** (verified: no reference to `WordTemplateController`,
`word_merge`, or `'word'` anywhere in it; `resolveLibraryTemplate()` still returns only
docgen/tabs).

⚠⚠ **KNOWN AND ACCEPTED COST: the precedence rule now exists in TWO places.** Both must agree
on manual-selection → PRIMARY document → first valid, filtered by category. They differ in
exactly one respect, which is the point: "valid" here means a `.docx` on
`field_import_template`; there it means a DocuSign template ID. **A change to the precedence
rule has to be made in both.** This is flagged in the `WordTemplateController` class docblock
so it cannot be discovered by accident.

#### What was built

| File | Role |
|---|---|
| `Service/WordMergeClient.php` | Guzzle multipart → Python. `http_errors => FALSE` so a **422 carries the author-facing message verbatim** rather than becoming a generic exception |
| `Service/WordAffidavitGenerator.php` | Job + Library → merged file in `private://affidavits/YYYY-MM/` |
| `Controller/WordTemplateController.php` | Generate entry point, own walk, DocuSign hand-off, download route |
| `DocuSignTokenMapper::resolveTableRowsForJob()` | NEW — structured rows for `{%tr for %}`. `resolveTokensForJob()` untouched |
| routing / services / `.env` | 3 routes, 2 services, `APS_DOCGEN_URL`/`_KEY`/`_TIMEOUT` |

⭐ `FileExists::Rename`, not `Replace` — regenerating must not destroy an affidavit that may
already have been sent or filed.

#### ⚠ `private://` was NOT configured — now is

`$settings['file_private_path']` was absent, so `private://` did not resolve at all and the
storage decision was unimplementable. Added to `web/sites/default/settings.php` as
`'../private'` → `D:\wamp64\www\private` (the directory already existed, from the DAV spike).
⚠⚠ **Staging needs this line ADDED BY HAND** — settings.php is never copied between
environments, it carries this box's database credentials.

#### ✅ Gate evidence (real data, real services)

- Job **537** (`0932733`) + Library **548** (`Test Affidavit 3`) → `fid 419`,
  `private://affidavits/2026-08/0932733_Test-Affidavit-3_20260828-034125.docx`, 233,693 bytes,
  on disk, **outside the docroot**, **0 unmerged placeholders**, opens as a valid Word document
  with its 3 paragraphs and 2 tables intact.
- ⭐⭐ **DYNAMIC TABLE ROWS PROVEN**: `resolveTableRowsForJob()` built **7 parents**, and
  `Job_Fees` came back as **2 real rows** with cells `Agreed_Fee, Standard_Fee, Total,
  Type_Of_Service, Units` — the same data the flat resolver collapses into the useless
  `"35.00, 34.94"`. This is the capability the DocuSign Doc Gen trial chased and never reached.
- Routing: **3 Libraries claimed** (445, 544, 548 — the .docx ones), **37 deferred**, and
  DocuSign tabs template 490 correctly **not** claimed.

#### ⚠⚠ The Job-page path CANNOT be exercised with current data

**No Job references any of the three `.docx` templates.** Scanned all 25 Jobs: zero hits. Job
537's own primary document has `template=none`. So Generate → Affidavit defers to DocuSign for
**every existing job**, and the gate above had to call the generator directly with an explicit
Job + Library pair.

This is a **data gap, not a code gap** — the same content problem recorded in §10. To exercise
the button end to end, point a Document's `field_affidavit_template` at Library 548.

#### Next

Phase C — front end: point the Job page's Generate → Affidavit at
`/word-template/generate-affidavit/{jobId}`, reuse the existing full-screen loader, and offer
the merged file for download/edit. Then Phase D (config form, permissions, cron retention,
staging deploy).

---

### ✅ Phase C COMPLETE — 2026-08-28

The Job page's Generate → Affidavit button now reaches the in-house merge. Two changes, as
scoped:

1. **`associatedps_forms.module:91`** — the link now points at
   `base://word-template/generate-affidavit/` (and the correspondence equivalent).
   ⭐ This is **not a switchover**: `WordTemplateController` merges only when the job's template
   is a `.docx`, and otherwise hands the request to `DocuSignController`. Every existing
   template behaves exactly as before.
2. **`docusign_generate.js`** — a `word` branch in the success handler. Library bumped
   **1.2 → 1.3**.

⚠⚠ **`window.open()` WOULD HAVE BEEN A BUG HERE.** I wrote it first, then caught it: the call
sits inside an AJAX success handler, so it is no longer tied to the user's click and popup
blockers eat it — the same trap already recorded for the M365 upload flow. The route replies
with `Content-Disposition: attachment`, so the fix is a **synthetic anchor click**, which
downloads the file and leaves the Job page where it is. Do not "tidy" this back into
`window.open()`.

Also corrected: the fallback message said "Unexpected response from DocuSign", which is no
longer true now that one endpoint fronts three generators.

#### ✅ Gate evidence — all four paths driven through the REAL route

Exercised via Drupal's HTTP kernel using the `?library_nid=` parameter the selection modal
already sends, so **no Job or Document content was altered** to make the test work.

| Path | Result |
|---|---|
| `?library_nid=548` (a .docx) | **200**, `method: word`, fid 420, `libraryTitle: Test Affidavit 3`, downloadUrl |
| `?library_nid=490` (DocuSign tabs) | **200**, and a real `envelope_id` came back — it genuinely deferred and DocuSign did its work |
| no template on the job | **200** `no_template` modal response, passed through unchanged |
| job 999999 | **400** `Invalid Job node.` |

Download route: **200**, correct `.docx` content-type and attachment disposition.
⭐ Its guard works — a private file outside `private://affidavits/` returns **404**, so the
route cannot be used to read arbitrary private files by guessing an fid; a missing fid is 404
too.

⚠ Note for future testing: drush runs as **anonymous**, which lacks `access content` here, so
kernel-driven route tests 403 before reaching the controller. Switch to an authenticated
account first (`account_switcher`), or you will be debugging the CLI's session rather than
your route.

Python suite still green: **30 passed**.

#### What is now genuinely usable

Press Generate → Affidavit on a job whose document points at a `.docx` Library template and the
merged affidavit downloads. Nothing else changed behaviour.

#### ⚠ Still outstanding — Phase C did NOT finish these

1. ⚠⚠ **The "edit" half of the original requirement.** Download works; **editing the merged
   affidavit does not**. `M365EditSessionStore` is keyed by **node id**, and a generated file is
   not a node — so this needs either a Document node created to hang the session off, or the
   store extended to key on a file id. That is a design decision, not wiring.
2. ⚠⚠ **The Python service only runs when started by hand.** It has been launched per-test and
   killed after. It must run as a Windows service (NSSM) or it dies with the console —
   **nothing works in production without this.** Phase D.
3. ⚠ **No Job references a `.docx` template** (0 of 25), so on real data the button still
   defers to DocuSign every time. Point a Document's `field_affidavit_template` at Library 548
   to see the new path from the UI.
4. Phase D also owes: the config form with its Test connection button (the `health()` method is
   already written for it), a `word_docgen` permission, cron retention for
   `private://affidavits/`, and the staging deploy.

---

### 🎬 DEMO RUNBOOK — written 2026-08-28, paused mid-step for a system restart

Goal: show a client the template merging with real Job data on demand. Everything needed is
built; what follows is the sequence to make it visible.

✅ **`start.ps1` is now TESTED** (2026-08-28) — launched from an unrelated working directory,
read the key from `.env`, came up healthy, and Drupal merged a real Job against it (fid 421).

Two bugs were found and fixed in it, both worth knowing because they will recur:

1. ⚠⚠ **NEVER PUT A NON-ASCII CHARACTER IN A `.ps1`.** Windows PowerShell 5.1 reads a script
   with no BOM as **ANSI**, so a UTF-8 em dash (`E2 80 94`) decodes to three characters ending
   in `0x94` — a **smart closing quote**, which PowerShell honours as a string delimiter. One em
   dash in a comment broke parsing *twelve lines later* with a misleading "string is missing the
   terminator". The file is now ASCII-only and says so at the top.
2. ⚠ **`Set-Location $here` is load-bearing.** uvicorn imports `app` by name from the current
   directory, which is NOT the script's folder when launched by double-click, a shortcut, or
   `Start-Process`. Without it: `Error loading ASGI app. Could not import module "app"`. It only
   appeared to work earlier because the terminal was already `cd`-ed into the service directory.

Execution policy is fine: `CurrentUser = RemoteSigned`, and a locally authored script carries no
mark-of-the-web, so it runs without any policy change.

#### Step 1 — start the merge service

```
cd D:\wamp64\www\services\aps-docgen
.\start.ps1
```

It reads `APS_DOCGEN_KEY` from the project `.env` so Drupal and the service cannot drift apart
(if they do, every merge fails with a 401 and the Job page only says the document could not be
generated). Leave the window open — closing it stops the service.

Check: <http://127.0.0.1:8099/health> should return `key_configured: true`.

⚠ After a reboot the service is NOT running — nothing supervises it yet. Start it before every
demo.

Manual fallback if `start.ps1` misbehaves:

```
$env:APS_DOCGEN_KEY = "<the APS_DOCGEN_KEY value from .env>"
D:\wamp64\www\services\aps-docgen\.venv\Scripts\python.exe -m uvicorn app:app --host 127.0.0.1 --port 8099
```

#### ⚠⚠ Step 2 — link a Document to the .docx template (REQUIRED, and easy to miss)

**The Generate button will defer to DocuSign until you do this.** No Job currently references a
`.docx` template (0 of 25).

⭐ And the "select a template" modal will NOT help: `listTemplatesByCategory()` filters to
Libraries holding a **DocuSign** id (`DocuSignController.php:582-583`), so the `.docx` items are
deliberately absent from that list. Linking on the Document is both the workaround and the
real production flow.

In the UI: open the Job → **Documents** tab → on the document, set **Affidavit Template** to
**`Test Affidavit 3`** (nid 548 — the only template carrying a real token,
`{{ Attendance_Notes.Employee_First_Name }}`) → tick **Primary Document** → Save.

#### Step 3 — generate

Job page → **Generate ▾ → Generate Affidavit**. The merged `.docx` downloads; a status message
names the template used.

#### What the demo actually shows

- Real Job data merged into a real Word template, by our own service — no DocuSign, no PandaDoc.
- ⭐ Dynamic table rows work (`Job_Fees` resolves to structured rows, not the comma-joined
  string the old resolver produced). Needs a template authored with `{%tr for %}` to show off.
- Templates are authored in Word for the web and stored on our server.

#### ⚠ Be ready for these questions

- **"Can we edit the generated affidavit?"** Not yet — download only. See the outstanding item
  about `M365EditSessionStore` being keyed by node id.
- **"Why is this template blank?"** Only nid 548 carries a token. 445 is a hand-filled form and
  544 is an invoice using Word MERGEFIELD `«chevrons»`, which merge to nothing **with no
  error**.
- **"Is it live?"** No. All of this is local and uncommitted; staging has none of it.

#### Resume point after the restart

1. Run `start.ps1` and confirm `/health` (it has never been executed).
2. Do Step 2 in the UI, then Step 3, and watch the file download.
3. Then either polish for the demo, or pick up Phase D (service supervision first — item 1 of
   the outstanding list, and the only thing that makes any of this survive a reboot).

---

## 11. 2026-09-06 → 2026-09-15 — list tokens, multi-select picker, dates, staging deploy, next flow

### ✅ Built and verified (local)

**1. List tokens — `{{ Documents.Affidavit_Template }}` renders one paragraph per document.**
- Cause: the legacy resolver renders `field_documents` once and gives that same run-on string
  to EVERY child token (`Documents.Document_Title` = all documents glued together), while
  `Documents.Affidavit_Template` arrived EMPTY.
- `DocuSignTokenMapper::resolveListTokensForJob()` derives one-column lists from the rows
  `resolveTableRowsForJob()` already builds. ⚠ `resolveTokensForJob()` is untouched — DocuSign
  depends on its exact output.
- New `lists` payload: `WordAffidavitGenerator` → `WordMergeClient::render(..., $lists)` →
  `app.py` → `render_docx()` → `build_context(tokens, tables, lists)`. Items joined with `\a`,
  which docxtpl's `resolve_listing()` turns into a real `<w:p>` carrying the source paragraph
  style — numbered/bulleted paragraphs number correctly. `\n` would only be a soft break.
- ⚠ Fixed silent data loss: values were spliced into the XML unescaped, so `Smith & Sons <Pty>`
  merged as `Smith  Sons `. Now `_for_xml()` = `escape(html.unescape(text))`.
- Verified live, job 537: `Test Affidavit 5` / `QCAT - Application for Minor Civil Dispute`,
  `List Number` style preserved.

**2. Multi-select token picker (Library form only).**
- `token_picker.js` opt-in `multiSelect` (OFF by default — DocuSign/PandaDoc/email pickers
  unchanged). Only `docusign_library_form.js` passes it.
- Tray: counter, numbered chips (click order = output order), ×, Clear, warning, preview of
  the exact clipboard text.
- 1 field → `{{ Parent.Field }}` (unchanged). 2+ fields on ONE parent →
  `{%p for row in Parent %}` / `{{ line(row.A, row.B) }}` / `{%p endfor %}`. Fields from TWO
  parents → no loop, warning shown. Clipboard written as text AND HTML `<p>` so Word pastes real
  paragraphs (each `{%p` must sit alone in its paragraph).
- ⚠ Fix 1: dropdown closed after every pick — the redraw replaced the clicked `<li>`, so the
  document "outside click" handler saw a detached node. `e.stopPropagation()` in `choose()`.
- ⚠ Fix 2: removing a chip reopened the dropdown — `renderList()` always shows the list.
  `refreshListIfOpen()` now used for chip ×, Clear and toggling. List opens on the search box
  only.
- Verified: generator exercised from the shipped source (11 assertions); real render against
  job 336 attendance notes.

**3. `line()` Jinja global** (`render.py`, `join_line`) — joins fields with ` - `, drops blanks,
so a missing value never leaves `- -`. Registered on the environment, so `find_placeholders()`
does not report it.

**4. Dates localised** — `WordMergeClient::localiseDates()` over tokens, tables and lists:
- `Y-m-d\TH:i:s` (datetime storage, UTC) → site timezone → `d/m/Y h:i a`.
- `Y-m-d` (date-only) → `d/m/Y`, NOT shifted (shifting a bare date can move it a day).
- ⚠⚠ This was a correctness bug: attendance note 336/1 stored `07:23` UTC, Drupal shows
  `17:23` Australia/Sydney — merged affidavits stated times TEN HOURS off.
- Only the Word merge path; DocuSign never goes through `WordMergeClient`.

Python suite: **50 passed**.

### Staging deploy — 2026-09-08

- ⚠⚠ **Missed file:** `web/modules/custom/associatedps_forms/associatedps_forms.module` (builds
  the Generate link, Phase C). Generate still went to DocuSign on staging in front of the
  client. Rule since then: **deploy file lists come from this plan document, never git.**
- Staging Python is **3.12** (no 3.14). All pins declare `>=3.10` or lower; service code uses
  nothing newer than 3.10. Needs `sudo apt install python3.12-venv` first (Ubuntu strips
  ensurepip). ⚠ `pytest` on 3.12 NOT confirmed run.
- systemd unit `/etc/systemd/system/aps-docgen.service`:
  - `ExecStart=<project>/services/aps-docgen/.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8099`
  - ⚠ `python app.py` exits 0 in under a second — `app.py` has no `__main__`; uvicorn is the
    server that serves it.
  - ⚠ `WorkingDirectory` required (uvicorn imports `app` from the current directory).
  - ⚠ `EnvironmentFile=/etc/aps-docgen.env` must be a FILE (`APS_DOCGEN_KEY=...`, chmod 600),
    not a directory. Unit files are world-readable, so the key does not go in `Environment=`.
  - ⚠ Pasting `ExecStart=...` into bash gives `app:app: command not found` (bash treats
    `NAME=value cmd` as an environment prefix).
- "The generated document could not be saved on the server." comes from
  `prepareDirectory('private://affidavits')` failing (`WordAffidavitGenerator.php:144`). Needs
  `$settings['file_private_path']`, the private root to EXIST and be owned by the PHP user, then
  `drush cr`. Check with drush `realpath("private://")`. ⚠ Resolution on staging NOT confirmed.
- ⚠ `.venv` sits under `public_html` on staging — make sure `/services/` is not web-served.
- Known staging state: Library authoring works (user, 2026-09-08). Generate end to end: NOT
  confirmed.
- Runbook: https://claude.ai/code/artifact/9d4e8a0a-8735-4674-91ec-3ebdb859b70d

### Files for staging — §11 items 1–4

| File | For |
|---|---|
| `services/aps-docgen/context.py` | lists, XML escaping |
| `services/aps-docgen/render.py` | lists param, `line()` |
| `services/aps-docgen/app.py` | `lists` payload |
| `services/aps-docgen/tests/test_render.py`, `tests/test_api.py` | tests (optional) |
| `web/modules/custom/associatedps_affidavit_template/src/Service/DocuSignTokenMapper.php` | `resolveListTokensForJob()` |
| `web/modules/custom/associatedps_affidavit_template/src/Service/WordAffidavitGenerator.php` | passes lists |
| `web/modules/custom/associatedps_affidavit_template/src/Service/WordMergeClient.php` | lists, `localiseDates()` |
| `web/modules/custom/associatedps_affidavit_template/assets/js/token_picker.js` | multi-select, tray |
| `web/modules/custom/associatedps_affidavit_template/assets/js/docusign_library_form.js` | `multiSelect: true` |
| `web/modules/custom/associatedps_affidavit_template/associatedps_affidavit_template.libraries.yml` | `token-picker` 1.5, `docusign-library-form` 2.1 |
| `web/modules/custom/associatedps_forms/associatedps_forms.module` | Generate link (Phase C) |

Then `drush cr`, and restart the merge service (`render.py` changed).

### ⏳ NEXT — approved direction, NOT implemented (awaiting "go"), 2026-09-15

User decisions:
1. Only `.docx` may be uploaded; anything else shows a message and is not uploaded. (Closes the
   `.doc` request — no conversion.)
2. Remove the upload-method popup; upload straight to Microsoft 365, then show an
   **Open in Word** button. Why a button: browsers allow a new tab only within **5 seconds** of
   a real click or key press (HTML "transient activation"; Chromium `kActivationLifespan` 5 s,
   Firefox `dom.user_activation.transient.timeout` 5000 ms). A file input's `change` event is
   not a user activation, so opening Word right after picking a file is blocked when choosing
   the file took longer. The old card click was what supplied a fresh click. The button uses
   `window.open()` (not a plain link) so Done still holds the tab handle and closes Word before
   pulling (`docusign_library_form.js:643`).
3. DocuSign/PandaDoc code stays until this plan succeeds, then it is removed. Keep the
   "Update at DocuSign" buttons as they are.
4. Generate → template picker first: every Library template in the category (no DocuSign
   filtering), the job's own template pre-selected (same precedence as `resolveLibrary()`:
   Primary document, then first document), Generate sends `?library_nid=`.
5. Generate still DOWNLOADS the merged `.docx` — no editor tab.

Functions:

| Function | File | Change |
|---|---|---|
| file-change handler | `docusign_library_form.js` | non-`.docx` → message; `.docx` → `uploadToM365()` |
| `uploadToM365()` | same | no blank tab up front; upload, then panel |
| `showM365Actions()` | same | **Open in Word** button, keeps the tab handle; message text |
| `showMethodModal()`, `buildCard()` | same | removed (`uploadFile()`, `reopenEditor()` kept for the later cleanup) |
| `listTemplates($category, $jobId)` | `WordTemplateController.php` | NEW — templates + `defaultNid` |
| `generate()` | same | no DocuSign hand-off; clear errors for no template / non-`.docx` |
| route `/word-template/templates/{category}/{jobId}` | `routing.yml` | NEW, `access content` |
| Generate click handler | `docusign_generate.js` | opens the picker instead of generating |
| `showTemplateSelectionModal()` | same | new route, pre-select default, neutral wording, no Doc Gen badge |
| Generate links | `associatedps_forms.module` | `data-job-id`, `data-category` |
| pins | `libraries.yml` | `docusign-library-form` 2.2, `docusign-generate` 1.4 |

Files for staging when built:
- `web/modules/custom/associatedps_affidavit_template/assets/js/docusign_library_form.js`
- `web/modules/custom/associatedps_affidavit_template/assets/js/docusign_generate.js`
- `web/modules/custom/associatedps_affidavit_template/src/Controller/WordTemplateController.php`
- `web/modules/custom/associatedps_affidavit_template/associatedps_affidavit_template.routing.yml`
- `web/modules/custom/associatedps_affidavit_template/associatedps_affidavit_template.libraries.yml`
- `web/modules/custom/associatedps_forms/associatedps_forms.module`
- then `drush cr`

### Stray file — `assets/js/DocuSignController.php`

An older copy of `src/Controller/DocuSignController.php` (813 vs 872 lines; no
`resolveLibraryTemplate()`, no Doc Gen branch, template list filtered to tab IDs only), dated
2026-09-08 16:08. Not loaded by Drupal (module classes autoload from `src/` only; nothing
references the path), and `web/.htaccess:148` returns 403 for `.php` files in subfolders.
Risk: it is copied along with `assets/js/`. Safe to delete — **pending user instruction.**

### Still outstanding

- Editing a merged affidavit — not built (session store keyed by node id).
- Staging: private path fix and `pytest` on 3.12 unconfirmed.
- `requirements.txt` comment still says "verified against Python 3.14.7".
- Local service still unsupervised (manual start); staging uses systemd.
- A dedicated permission; cron retention for `private://affidavits/`.

---

## 12. 2026-09-28 — DocuSign removed from authoring and generate. ✅ ON STAGING, WORKING.

User direction: the app is new, the DocuSign templates uploaded earlier are not needed and will
be deleted, so no DocuSign option is to be offered anywhere. Functions changed first; the
DocuSign files and dead code are removed as a separate later step.

### ✅ 1. Template upload goes straight to Word for the web

- The upload-method popup is **gone**. Choosing a file on the Library/Template form uploads it
  to Microsoft 365 with no question asked. Both entry points — Library → Template (admin) and
  Contents → Template (other logins) — use the same form, so one change covers both.
- **.docx only.** Anything else is refused in the panel ("Only Word .docx files can be used as
  templates… File → Save As → Word Document (.docx)") and nothing is uploaded.
  ⚠ Not a preference: Word for the web cannot open a legacy .doc, Graph will not convert one and
  the merge service cannot read one.
- ⚠⚠ **FIRST UPLOAD SHOWS AN "OPEN IN WORD" BUTTON; the tab is opened from that click.**
  A browser only allows a new tab within ~5 s of a real click or key press (Chromium
  `kActivationLifespan` 5 s, Firefox `dom.user_activation.transient.timeout` 5000 ms), and a file
  input's `change` event is **not** one of those events. The popup's card click used to supply a
  fresh click, which is what hid this until the popup was removed — the tab was then blocked
  silently, which is exactly what the user observed on first test.
  ⭐ `window.open()` and not a plain link, because Done needs the window handle to close Word
  before fetching the file.
- ⭐ **"Update document" (re-edit) still opens the tab immediately and shows no button** — that
  handler runs from a real button click, so it already has permission.
- Tested by the user: Open in Word works, Done saves back correctly.

### ✅ 2. Generate never touches DocuSign

`WordTemplateController::generate()` no longer hands anything to `DocuSignController`. Two
distinct messages, because the two situations need different action from the user:

| Job state | Response |
|---|---|
| Old DocuSign template assigned | **422** "The template assigned to this job — "<name>" — is an older DocuSign template and can no longer be opened. Please delete this template and re-assign the job with a new template created with Microsoft Word (Microsoft 365)." |
| No template of that category | **422** "No <category> template is assigned to this job. Please assign a template created with Microsoft Word (Microsoft 365) to the job's primary document." |
| Valid .docx | Merges and downloads as before |
| Invalid job id | **400** Invalid Job node. |

`findLegacyTemplate()` is deliberately the same document walk as `resolveLibrary()` with the
.docx test inverted — that is what makes the first message name the offending template.
Verified by driving the real controller: library 490 ("Docusing - affidavit 1", no file) → the
legacy message; job 537 Correspondence → the no-template message.

### ✅ 3. DocuSign fields and buttons off the Template form

- `field_docusign_template_id` and `field_docgen_template_id` hidden with **`#access = FALSE`**.
  ⭐ Deliberately NOT a form-display change: that would be a config export/import on every
  environment, and config drift has cost this project real time. Fields and stored values are
  untouched and go when the fields are deleted.
- Both "Update at DocuSign" buttons **deleted** from the form build.
- Verified against the built form for Library 548: both fields `#access: false`,
  `docusign_update_btn` and `docusign_docgen_update_btn` not built, `m365_update_btn` and
  `m365_token_panel` still built.

### ✅ 4. Generate opens a template picker

Every Generate → Affidavit / Correspondence click now opens the picker **whether or not the job
has a template**:

- The job's own template is pre-selected, badged "This job's template", and scrolled into view —
  so confirming is one click and gives exactly what the button used to give on its own.
- Any other template can be chosen and is used **for that run only**; nothing on the job changes.
- No template assigned → list still shown, nothing pre-selected, Generate disabled until a row
  is picked. The old dead-end is gone.
- Only templates holding a **.docx** are listed, so the list cannot offer something that would
  then fail. Each row shows the title with its file name underneath (several templates share a
  name).
- ⭐ Modelled on `DocuSignController::listTemplatesByCategory()` at the user's direction —
  referred to for the pattern, **left completely untouched and not enabled**.
- The picker reads job id and category from the Generate link's own href, so
  `associatedps_forms.module` did NOT have to change.

New endpoint `GET /word-template/templates/{category}/{jobId}` → `{templates: [{nid, title,
fileName}], defaultNid}`. Verified: Affidavit/537 → `defaultNid=550` with 7 templates;
Affidavit/336 (nothing assigned) → `defaultNid=null` with the same 7; Correspondence/537 → 0
templates. Library 490 correctly absent from every list.

### ✅ 5. Wording

Overlay now says "Merging this job's details into the template…" and "Document ready. Starting
the download…"; the generic AJAX failure no longer blames envelope generation. ⚠ Generate does
NOT upload to M365 or open Word — it merges and downloads; the M365 upload belongs to authoring.

### Files deployed to staging — ✅ all moved and confirmed working 2026-09-28

Under `web/modules/custom/associatedps_affidavit_template/`:

| File | Carries |
|---|---|
| `assets/js/docusign_library_form.js` | no popup, .docx-only, Open in Word |
| `assets/js/docusign_generate.js` | template picker, merge/download wording |
| `src/Controller/WordTemplateController.php` | no DocuSign hand-off, `cannotGenerateMessage()`, `findLegacyTemplate()`, `listTemplates()` |
| `associatedps_affidavit_template.module` | ID fields hidden, both Update at DocuSign buttons removed |
| `associatedps_affidavit_template.routing.yml` | new `/word-template/templates/{category}/{jobId}` route |
| `associatedps_affidavit_template.libraries.yml` | `docusign-library-form` **2.3**, `docusign-generate` **1.5** |

Then `drush cr`. **No `updb` and no config import** — by design.

⚠ Deploying in two passes cost a re-copy: `WordTemplateController.php`, `docusign_generate.js`
and `libraries.yml` were merged once and then changed again for the picker. The JS and the
controller must go together or the picker calls a route the controller does not serve.

### ⏳ Dead code awaiting the removal step (functions first was the instruction)

- `docusign_library_form.js`: `showMethodModal()`, `buildCard()`, `uploadFile()`, and the click
  handlers for `#docusign-update-btn` / `#docusign-docgen-update-btn` (the buttons no longer
  render).
- `docusign_generate.js`: the `envelope_id` branch, `pollStatus()`, and every remaining
  "envelope"/DocuSign string inside them — all unreachable now that the controller never defers.
- `DocuSignController`, `DocuSignDocGen*`, `DocuSignClient`, the DocuSign routes and services,
  `field_docusign_template_id`, `field_docgen_template_id`, `docusign_tokens`,
  `docusign_envelopes`, and PandaDoc alongside them.
- ⚠ `routing.yml` and `services.yml` still reference the `DocuSignDocGen*` classes — deleting
  those class files without editing the YAMLs makes Drupal fatal.
- Stray older copy `assets/js/DocuSignController.php` — not loaded, 403 over HTTP; delete.
- The DocuSign templates themselves are to be deleted from the Library by the user.

### Still outstanding

- Editing a merged affidavit is not built (session store is keyed by node id).
- Local merge service is still started by hand; staging runs it under systemd.
- `requirements.txt` comment still says "verified against Python 3.14.7" (staging runs 3.12).
- A dedicated permission; cron retention for `private://affidavits/`.
