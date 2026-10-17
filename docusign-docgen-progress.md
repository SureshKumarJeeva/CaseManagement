# DocuSign Document Generation — progress & findings

**Status: PAUSED 2026-08-17.** Generation half is built and verified. Template *authoring* half is
blocked — and as of 2026-08-17 the blocker is **proven against the live API, not inferred**. See
section 12, which supersedes the reasoning in section 5. Everything is local and **uncommitted**.

This is a **separate new feature**, not DocuSign M3. Do not follow `docusign-progress.md` /
`docusign-plan.md` — those track the parked M3 work and are unrelated.

---

## 1. What was requested

Add a second method alongside the existing template-upload flow:

1. Popup at upload time — option 1 = current template method, option 2 = Document Generation
2. Option 1 unchanged
3. Option 2 uploads the document under DocuSign's **Document Generation** method, authored with the
   **Agreement Template Builder** (ATB) or the **Template Assistant for Word** add-in
4. Capture the resulting template/document ID into the app
5. Later generate from that ID
6. Generate Affidavit → hand off to DocuSign, push app data into the sender fields, let DocuSign
   generate the document, let the user edit **field values only**
7. Download the finished document

Motivation for option 2: **dynamic tables**, for variable-length data.

Credentials: originally specified as a *separate sandbox account*, prompted for when needed.

---

## 2. Template Upload vs Document Generation

Two different DocuSign products on the same REST API. The app now carries both.

| | Template Upload (existing) | Document Generation (new) |
|---|---|---|
| Document | Static; any file type | `.docx` only |
| Authoring | Drag positioned Text/Date **tabs** onto it | **Sender fields** merged into the DOCX |
| At generate | Values written into tabs | DocuSign **re-renders the DOCX** from the data |
| Wording per job | Fixed | Can change (conditional content, dynamic tables) |
| Library field | `field_docusign_template_id` | `field_docgen_template_id` |
| Controller | `DocuSignController` | `DocuSignDocGenController` |
| Account | `DOCUSIGN_*` | `DOCUSIGN_DOCGEN_*` |

---

## 3. What is built

### New files
- `src/Service/DocuSignDocGenClient.php` — extends `DocuSignClient`; 3 overrides only:
  `envPrefix()` → `DOCUSIGN_DOCGEN_`, `tokenRowId()` → row **2** of `docusign_tokens`,
  `loggerChannel()` → `docusign_docgen`. Plus `isConfigured()` / `getConfigurationError()`.
- `src/Controller/DocuSignDocGenController.php` — upload, editor page, editor callback, bounce,
  `generateForJob()`, status, envelope-editor (sender view), sender-view refresh, sender callback,
  download.
- `src/Controller/DocuSignDocGenOAuthController.php` — separate one-time JWT consent.
- `src/Form/DocuSignDocGenConfigForm.php` — `/admin/config/associatedps/docusign-docgen`.
- `assets/js/docusign_docgen_editor.js`, `assets/js/docusign_docgen_envelope_editor.js`.

### Edited files
- `src/Service/DocuSignClient.php`
  - Credential reads moved from `getenv('DOCUSIGN_X')` to `$this->env('X')` so a subclass can
    repoint them. **Behaviour for the live account is unchanged — re-verified, still mints tokens.**
  - New: `createDraftEnvelopeFromTemplate()`, `getDocGenFormFields()`, `updateDocGenFormFields()`,
    `createDraftSenderView()`.
  - `createTemplateEditView()` gained an optional 3rd arg `$viewAccess` (default `''` = omitted).
- `src/Controller/DocuSignController.php` — `resolveLibraryTemplate()` (docgen field first, then
  tabs); `generateViaDocuSign()` hands off to the docgen controller via
  `\Drupal::classResolver()`; `listTemplatesByCategory()` ORs over both ID fields and returns a
  `method` key.
- `src/Service/DocuSignTokenMapper.php` — `resolvedToDocGenFields()` builds the docGenFormFields PUT
  payload; matches exact field name first, then case/punctuation-stripped.
- `assets/js/docusign_library_form.js` (lib **v1.1**) — the two-option popup.
- `assets/js/docusign_generate.js` (lib **v1.2**) — docgen-aware messaging, "Doc Gen" badge.
- `.module` — docgen field readonly/states, `docgen_template_id` query param, second "Update at
  DocuSign (Doc Gen)" button, `drupalSettings.docusignLibrary.docgen`.
- `.install` — updates 8009 / 8010.
- `.routing.yml`, `.libraries.yml`, `.services.yml`, `.links.menu.yml`, `.env`.

### Schema (both applied locally via `drush updb`)
- **8009** — creates `field_docgen_template_id` on Library (string 255) + its component on the
  `node.library.default` form display, weight 10. Done in code, not config export, because
  `config/sync` has drifted from both environments.
- **8010** — adds `docusign_envelopes.method` (`varchar(20)`, default `tabs`).
  ⚠ Originally passed an index to `addField()`; MySQL rejected it — *"needs the 'method' field
  specification in order to normalize the 'method' index"*. Index dropped. Don't re-add it that way.

### Routes (all `/docusign-docgen/…` or `/admin/content/docusign-docgen/…`)
config, oauth start/callback, ajax-upload, ajax-edit-view, editor, editor-callback, status,
envelope-editor, sender-view, download, template-editor-bounce (anon), sender-callback (anon).

There is deliberately **no docgen generate route** — the Job form's single Generate button still
calls `/docusign/generate-affidavit/{jobId}` and routes internally.

---

## 4. What is verified

- Live DocuSign account still mints tokens after the env-prefix refactor (no regression).
- Docgen client resolves, connects, mints into token row 2.
- `field_docgen_template_id` exists and is on the form display.
- Library node form builds with the field and the docgen `drupalSettings`.
- All docgen routes registered.
- `resolveLibraryTemplate()` precedence: docgen → tabs → NULL.
- `generateForJob()` config guard returns a clean 503 when unconfigured.
- `listTemplatesByCategory()` returns both kinds with a `method` key.
- `viewAccess: "template"` is accepted by DocuSign — the returned `slt` token decodes to
  `"ViewAccess":3`.

**Never tested:** the full DocuSign round trip. No docgen template exists yet on the account.

---

## 5. The blocker — ATB cannot be embedded

The current option 2 uploads the `.docx`, then embeds `POST /templates/{id}/views/edit` in an
iframe. **That is the standard template editor, not ATB.** Decoding the returned `slt` token shows
`"a":"prepareTemplateFields"` — it lands on the ordinary tagging screen.

Evidence gathered:

1. **`TemplateViews` has exactly one method** — *"Gets a URL for a template edit view."* There is no
   ATB view, no docgen view, no `startingScreen` that reaches ATB.
2. **DocuSign's own ATB sequence has no embedding step.** From the concepts page, "Sample sequence
   using the Agreement Template Builder":
   1. Use ATB to create a document generation template, add sender fields / tabs / dynamic tables —
      *"This is a one-time setup task."* **Not an API request. Done in the DocuSign web app.**
   2. `Envelopes:create`
   3. `DocumentGeneration:getEnvelopeDocGenFormFields`
   4. `DocumentGeneration:updateEnvelopeDocGenFormFields`
   5. `Envelopes:update` (sends the draft)

   Steps 2–4 are **already implemented and match exactly**. We stop short of 5 on purpose — the
   envelope stays a draft so it can be reviewed and downloaded rather than sent.

### Approach considered and REJECTED — do not retry

Upload via `Templates:create`, then open the minted edit-view URL in a **new tab** instead of an
iframe, on the theory that ATB is reachable in the full console.

Rejected because it is unverified guesswork. ATB availability is a property of the **document**
(must be DOCX with sender-field capability), not of the template ID, and nothing in the
documentation says a template created through `Templates:create` is offered to ATB. The reductio
that settles it: if any template ID could be handed to ATB, a PDF-backed template could too, which
is plainly impossible. A "template not found" or simply no-ATB-option outcome is the likely result.

---

## 6. Corrections to earlier assumptions

- ❌ *"ATB cannot create dynamic tables; the Word add-in is required."* **Wrong** — that came from a
  2024 blog post saying support was months away. It shipped. Current docs:
  *"Recommended: Use the Agreement Template Builder to define a dynamic table and insert it into the
  DOCX file"*; the Word add-in is the *"Alternative option."*
- ❌ *"There is an `isDocGenDocument` flag to set on template create."* **Wrong** — no API flag.
  DocuSign detects sender fields in an uploaded DOCX and marks the template docgen-compatible
  itself.

---

## 7. Open decision (this is where to resume)

Option 2's **authoring** step must be reshaped. The documented, zero-guesswork version:

> Staff build the docgen template in the DocuSign web app with ATB, once. The app never uploads a
> file for this method and never embeds an editor. On the Library form they simply choose which
> docgen template the item uses.

That needs one new piece — how they choose:
- **(a) Pick from a list** — call `Templates:list` against the docgen account, show templates that
  have sender fields. No IDs typed by hand.
- **(b) Paste the ID** — copied from the DocuSign URL. Nothing to build.

Everything downstream already works and needs no change: resolution, envelope creation, field
discovery, prefill, sender view, download.

This also settles the ATB question empirically as a side effect — a template built *in* ATB is
unambiguously a docgen template, so if `getEnvelopeDocGenFormFields` returns its fields (including
any `TableRow`), the whole chain is proven against a real template.

**User has not yet chosen (a) or (b).**

---

## 8. Dynamic tables — rules for when they get implemented

Currently **not populated**: `resolvedToDocGenFields()` skips any field with `type: "TableRow"` or a
`rowValues` key and leaves it for the sender. Per the docs, omitting the whole `TableRow` structure
is legal and yields the heading row plus one blank row — not an error.

To implement, per DocuSign:
- `GET docGenFormFields` returns the table definition: `type: "TableRow"`, a `name`, and
  `rowValues.docGenFormFieldList` listing the column fields.
- The PUT sends **one `rowValues.docGenFormFieldList` array per table row**.
- Each row array **must include a name/value pair for every field in the definition** — omitting one
  returns an error. Use `""` for blanks.
- Only **TextBox** sender fields are allowed in table columns.
- Values are never reused across rows, across tables, or between a table and body text — every
  instance needs its own value.
- One sender field per column in both editors; more requires hand-editing the DOCX.

**Unanswered:** which Job field supplies the rows (`field_documents`? Attendance Notes? Job Fees?)
and how its sub-fields map to columns. Needs a decision before any code.

---

## 9. Credentials

`.env` has the `DOCUSIGN_DOCGEN_*` block filled in — but **with the same values as the live
`DOCUSIGN_*` set**: same integration key, user ID, private key and owner email. Both token rows
resolve to account `d03d2177-5827-4663-80fe-2c18564da8a6`.

It connects and is fine for testing, but it is **not** the separate sandbox account originally
specified — docgen templates and envelopes land alongside the live affidavit ones. Swapping in a
real second account needs nothing but new `.env` values plus one-time consent at
`/admin/config/associatedps/docusign-docgen` (consent is per integration key — two accounts means
two consents).

Only `OAUTH_BASE`, `JWT_SCOPES` and `OWNER_NAME` fall back to the live values. Identity variables
deliberately do not — an unset key greys out the Doc Gen popup option with the reason shown rather
than silently borrowing the live account.

---

## 10. Deploy notes

Manual file copy as always (never `drush cex/cim`, never `git pull`, never copy `settings.php`),
**plus `drush updb`** for updates 8009 and 8010, **plus `drush cr`**. Library versions are already
bumped (`docusign-library-form` 1.1, `docusign-generate` 1.2) — they must be, or browsers hold the
old JS through a cache rebuild.

Nothing from this feature is on staging. Nothing is committed.

---

## 11. Useful technique — reading DocuSign's docs

`developers.docusign.com` is a Gatsby SPA; fetching the HTML gives an 8 KB shell with no content,
which is why several early attempts came back empty and one conclusion was drawn from a stale blog
post instead. The real content is at:

```
https://developers.docusign.com/page-data/<path>/page-data.json
```

e.g. `…/page-data/docs/esign-rest-api/esign101/concepts/documents/document-generation/page-data.json`

It is Contentful rich-text JSON. Body blocks live under
`result.pageContext.data.body.data.body[N].body.raw`; inline API-method names are
`embedded-entry-inline` nodes that must be resolved against `result.pageContext.data.references.data`
(keyed `<id>en-US`, label in `displayText`). Walk both or the API steps come out as empty bullets.

---

## 12. Session 2026-08-17 — ATB blocker PROVEN, plus three real bugs fixed

Prompted by a DocuSign support agent telling the client that a new release / new dev-console app
would make the Agreement Template Builder appear in the embedded editor. It does not. Everything
below was measured against the live API, not read from docs.

### 12.1 ATB cannot be reached through the API — proven by enumeration

`settings.startingScreen` on `TemplateViews:createEdit` is strictly validated. Probing it:

```
Prepare                   ACCEPTED   ss=1   <- this is already DocuSign's default
Tagger                    ACCEPTED   ss=2
Document                  REJECTED   INPUT_VALIDATION_ERROR "Invalid startingScreen value"
DocumentGeneration        REJECTED   (same)
AgreementTemplateBuilder  REJECTED   (same)
```

Every accepted variant returns a view token encoding the **same** action:

```
"Source":"POSTTemplateEditView","ViewAccess":3
"a":"prepareTemplateFields"
```

`TemplateViews` still has exactly one method and `DocumentGeneration` still has only
get/updateEnvelopeDocGenFormFields (re-checked today; the createEditView reference page was
regenerated 2026-08-10 and gained `settings` + made `viewAccess`/`returnUrl` **Required**, but no
docgen screen). So no credential, account or setting can make the API return ATB — the ordinary
template editor is the only thing it produces. **A new integration key changes nothing here.**

⚠ This supersedes the "considered and rejected" reasoning in section 5, which was an argument from
documentation. This is an argument from the API's own validator.

**Account IS entitled**: `GET /accounts/{id}/settings` reports `allowDocGenDocuments = 'true'`
(also `enableDocumentTemplateLibrary=true`, `enableGenerationWith3PIntegration=false`, plan
`DEVCENTER_DEMO_RESTRICTED_JUNE2025`). So the entitlement is not what is missing. If ATB is
reachable at all it must be a document-level action *inside* that same editor — a UI rollout, not an
integration change.

**Next step for the client, not for code**: ask the support agent to name the exact API request that
returns an ATB view. If they cannot, ATB is web-app-only and the section 7 open decision stands.

### 12.2 Cached tokens outlived their credentials — FIXED (update 8011)

The symptom that started the session: the client pasted a deliberately wrong user ID into
`DOCUSIGN_DOCGEN_*` and **got no error**. Cause — `getValidAccessToken()` only re-minted on expiry,
so a valid cached token kept the app talking to the OLD account for up to an hour. A credential
mistake was silent, which is the worst possible feedback when repointing accounts.

Fix: `docusign_tokens.credential_fingerprint` (update **8011**) = sha256 of integration key + user +
oauth base + hash of the private key. `getValidAccessToken()` treats a mismatch as no token.
Verified both directions: changed credentials re-mint immediately with a log line naming old vs new
user; unchanged credentials still reuse the cached token.

### 12.3 OAUTH_BASE vs account base URI — FIXED + guarded

`DOCUSIGN_DOCGEN_OAUTH_BASE` was set to `https://demo.docusign.net`, giving a bare IIS
`404` on `POST /oauth/token`. Apps and Keys shows two hosts and they are easy to swap:

| Shown as | Demo value | Used for |
|---|---|---|
| Account Base URI | `demo.docusign.net` | REST API (`API_BASE`; auto-discovered if blank) |
| Authentication | `account-d.docusign.com` | `/oauth/auth`, `/oauth/token`, `/oauth/userinfo`, JWT `aud` |

`.env` corrected, plus `DocuSignClient::normalizeOauthBase()` now translates any `*.docusign.net`
host to the matching auth host and logs a warning so the `.env` still gets fixed.

⚠⚠ **OUTSTANDING**: the **live** `DOCUSIGN_OAUTH_BASE` (line 28) is *also* `demo.docusign.net`. The
live affidavit flow is currently only working because of that guard. Client was asked; not yet
changed.

### 12.4 Which GUID is which

The dev-console URL `apps.docusign.com/dev-console/integrations/<GUID>` carries the **Client ID /
Integration Key**, not a User ID. The OAuth errors tell them apart precisely:

```
iss=90e0d8c8… , sub=live user   -> no_valid_keys_or_signatures   (app EXISTS, RSA key mismatch)
iss=live key  , sub=90e0d8c8…   -> user_not_found
iss=f868d317… , sub=8f50af0b…   -> issuer_not_found              (the OLD docgen app is GONE)
```

So the previously-configured separate sandbox app no longer exists. To move docgen onto app
`90e0d8c8-d8a2-4c62-892e-e0195f422da4`, three things must change together — integration key, a file
holding **that app's** RSA private key (shown once at generation), and the API Username from Apps
and Keys → My Account Information — then one-time consent at
`/admin/config/associatedps/docusign-docgen`. Until then `DOCUSIGN_DOCGEN_*` points at the working
account so the flow stays testable. ⚠ Still not the isolated sandbox originally specified (section 9).

Also noted: `DOCUSIGN_DOCGEN_PRIVATE_KEY` holds a GUID, not an RSA key. Inert because
`PRIVATE_KEY_PATH` wins in `loadPrivateKey()`, but it becomes a confusing signing failure if that
file ever moves.

### 12.5 Probe artifacts left on the account

- Template `6fb218fd-f2c1-4892-8d79-60a92a7e6cfe` — "ZZ DocGen probe (safe to delete)", a minimal
  DOCX built by `scratchpad/makedocx.php` carrying `{{Client_Name}}`-style syntax.
- `GET docGenFormFields` on an envelope from it returned **`[]`** — 200, no error, no fields. So the
  hand-written `{{…}}` syntax guess did **not** register. Do not treat that as proof either way; the
  real syntax was never confirmed (support.docusign.com is also an SPA and would not yield its
  "Document Generation Syntax" article to a fetch). A genuinely ATB-authored template is still the
  only trustworthy test of the chain.

### 12.6 Files changed this session

- `src/Service/DocuSignClient.php` — `normalizeOauthBase()`, `credentialFingerprint()`, stale-token
  discard in `getValidAccessToken()`, fingerprint written in `storeTokens()`,
  `createTemplateEditView()` gained a 4th arg `array $settings`.
- `src/Controller/DocuSignDocGenController.php` — `VIEW_SETTINGS` constant, deliberately `[]`, with
  the enumeration finding recorded; both `createTemplateEditView()` call sites pass it.
- `associatedps_affidavit_template.install` — update **8011**.
- `.env` — docgen OAuth base and user corrected; client-ID/user-ID distinction documented inline.

Still uncommitted, still not on staging. Deploy now needs updates 8009, 8010 **and 8011**.
