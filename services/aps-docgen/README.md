# APS Document Generation Service

Merges a Job's field values into a `.docx` affidavit template. Drupal POSTs the
template plus the Job's resolved tokens; this returns the finished document.

Replaces the DocuSign/PandaDoc token merging with something we own, and unlocks
`{%tr for %}` dynamic table rows, which neither of those reached.

---

## Running it

```
py -3.14 -m venv .venv
.venv\Scripts\python.exe -m pip install -r requirements.txt
set APS_DOCGEN_KEY=<shared secret>
.venv\Scripts\uvicorn.exe app:app --host 127.0.0.1 --port 8099
```

⚠ **Bind to `127.0.0.1`.** Drupal and this service share a box; nothing off-host
should reach it. The shared key is defence in depth, not the only wall.

⚠ On this dev box `python` on `PATH` is the Windows Store alias stub and reports
*"Python was not found"*. Use `py -3.14` or the venv interpreter by full path.

| Variable | Purpose |
|---|---|
| `APS_DOCGEN_KEY` | Shared secret. **Required** — the service refuses to render without it |
| `APS_DOCGEN_MAX_BYTES` | Upload ceiling, default 25 MB |
| `APS_DOCGEN_LOG_LEVEL` | Default `INFO` |

Tests: `.venv\Scripts\python.exe -m pytest tests -q`

---

## API

### `POST /render`

`multipart/form-data`, header `X-APS-Key`, optional `X-Request-Id` (echoed back
and written to the log, so a failure traces to a Drupal watchdog entry).

- `file` — the `.docx` template
- `data` — JSON, in either shape:

```json
[{"name": "Client.Mail", "value": "notices@example.test"}]
```

```json
{
  "tokens": [{"name": "Client.Mail", "value": "notices@example.test"}],
  "tables": {"Job_Fees": [{"Description": "Delivery Fee", "Amount": "35.00"}]}
}
```

The bare list is `DocuSignTokenMapper::resolveTokensForJob()` passed straight
through. The object form adds `tables`, which is the **only** way to get
dynamic rows — see below.

Returns the merged `.docx`.

| Status | Meaning |
|---|---|
| 200 | Merged document |
| 400 | Bad `data` JSON, empty upload, or a token name that breaks the contract |
| 401 | Bad or missing `X-APS-Key` |
| 413 | Template over the size ceiling |
| 422 | Template is not a readable `.docx`, or its placeholders are malformed |
| 503 | `APS_DOCGEN_KEY` not configured — fails closed rather than open |

422 is deliberate: an authoring mistake is the caller's to fix, and the message
is written to be shown to the staff member who wrote the template.

### `GET /health`

Reports liveness and whether the key is configured, so a misdeploy is visible
from the Drupal config form without reading server logs. Never returns the key.

---

## ⭐⭐ The one thing to understand before changing `context.py`

**Every parent token name is both a scalar and a namespace.** Drupal emits:

```
{"name": "Client",      "value": "Example Statutory Authority"}
{"name": "Client.Mail", "value": "notices@example.test"}
```

and authors legitimately write both `{{ Client }}` and `{{ Client.Mail }}`. All
14 parents in the live data are like this — `Client`, `Job_Fees`, `Parties`,
`Attendance_Notes`, `Court`, `Documents`, and so on.

A flat dict cannot serve `{{ Client.Mail }}` (Jinja resolves the dot by
attribute lookup, not by key). A nested dict cannot serve `{{ Client }}` (it
renders a dict repr). So `Value` is a **`str` subclass** that also carries a
children map and optional table rows.

Three constraints keep it working. Breaking any one is a silent data bug:

1. **`__getattr__` is only safe because names are capitalised.** It fires only
   when normal attribute lookup fails, so a child named `title` would be
   shadowed by `str.title` and render as a method repr. Drupal's
   `formatVariableName()` Ucfirsts every segment and every `str` method is
   lowercase, so they cannot collide — and `build_context()` asserts it rather
   than trusting it.
2. **`__len__` must stay undefined.** Defining it would make truthiness follow
   the row count, so `{% if Client %}` would be false for a populated client
   that simply has no rows.
3. **`__iter__` yields rows, never characters.** Looping a scalar is an
   authoring error; yielding nothing drops the row, where `str`'s default would
   explode a table into one row per letter.

---

## Dynamic table rows

Templates use docxtpl's row tag:

```
{%tr for fee in Job_Fees %}
{{ fee.Description }}    {{ fee.Amount }}
{%tr endfor %}
```

⚠ **The flat token list cannot express this.** `resolveTokensForJob()` flattens
multi-value fields into parallel comma-joined strings —
`Job_Fees.Description` arrives as `"Delivery Fee, Collection Fee"` and
`Job_Fees.Amount` as `"35.00, 34.94"`. Rows must be built **on the Drupal side**
and sent in `tables`. Python alone does not unlock them.

The parent stays usable as a scalar alongside its rows. Note that
`{{ Job_Fees|length }}` measures the *string*; use `{{ Job_Fees.rows|length }}`
for a row count.

---

## Verified behaviour, and two things that surprised us

Proven against `87973-a1.docx` — a genuine 230 KB template authored by staff
through the M365 Word-for-the-web flow — merged with 152 live tokens from a real
Job: the nested placeholder was substituted, and the document's 3 paragraphs and
2 tables survived intact.

**Word's split runs are already handled.** Word fragments typed text across XML
runs, so `{{ Client }}` can be stored as `{{ Cli` + `ent` + ` }}`. The plan
called a custom run-merge pass mandatory; it is not — docxtpl's own `patch_xml`
covers it. Two tests hold that finding in place, so if a future upgrade regresses
it we find out from the suite rather than from a client's affidavit.

**`DocxTemplate` does not read the file when constructed.** `__init__` only
stores the handle and sets `self.docx = None`; the parse happens later, during
render. So a corrupt or renamed file survives construction and fails *inside*
render, where it is indistinguishable from a bad placeholder — the author gets
told to fix `{% for %}` tags in a template that is fine. `assert_is_docx()`
checks the container up front with stdlib `zipfile` instead, which depends on
the OOXML format rather than a library internal and so cannot drift on upgrade.

---

## Not done yet

- **Nothing on the Drupal side.** No `WordMergeClient`, no generate route, no
  routing change. This service is standalone and unreferenced so far.
- **The real affidavit templates have no files.** All 15 production-looking
  Library items are empty; only three `.docx` exist and two carry no tokens at
  all. Authoring them is a content job on the critical path.
- **Two upstream data defects** will become visible once merging is live: two
  tokens resolve to the literal string `"Array"`
  (`associatedps_pdf_templates.module:350`/`:361`), and `strip_tags()` collapses
  `<li>` lists with no separator — so a lettered "documents served" list merges
  as one run-on line.
