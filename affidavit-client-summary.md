# Affidavit Template Management — Functionality Delivered

Client-facing summary. Plain language, no internal names — written to support invoicing and to
be shown to the client as-is.

Origin: the 9-item list was written 2026-09-18 (session
`e1750ac9-6c0c-48b5-90d3-3aee3cfd6764`); the detailed descriptions were added 2026-09-21.

⚠ Accurate as at 2026-09-28. All nine items are built and on staging.

⚠ Two further pieces of work were delivered on 2026-09-28 and are NOT yet described below —
add them before this goes to the client if the invoice covers that date: uploading a template
now goes straight to Word with no method to choose (and only Word `.docx` files are accepted),
and pressing Generate now offers the list of available templates with the job's own already
selected, so staff can generate against a different template without editing the job. Both are
recorded in `affidavit-management-plan.md` §12.

---

**1. Microsoft 365 connection**
Secure, automatic link between the system and your Microsoft 365 account. The system signs in
on its own using its own credentials, so no staff member ever has to log in to Microsoft for a
template to be opened or saved. Access is deliberately limited to the single document library
used for templates — the system cannot reach anyone's personal files or mailbox. A status
screen shows at a glance whether the connection is healthy and whether the required permission
is still in place, so a problem is visible before staff hit it. The connection is kept entirely
separate from the existing client-email connection, so template work can never disturb outgoing
email.

**2. Edit session tracking**
Every template edit is tracked from the moment it opens to the moment it is finished. The
system records which online copy belongs to which template, so when the author presses Done it
always retrieves the right file. If the author reloads the page or leaves the tab sitting for an
hour, the Done button comes back on its own instead of disappearing and stranding the work.
Sessions have a four-hour window, and anything left open beyond that is tidied up automatically.
Nothing is left behind in Microsoft 365 once an edit is finished.

**3. Edit templates in Word in the browser**
Staff open a template in real Word in their browser, edit it, and press Done. The edited version
is saved back into the system automatically as a new version of that template, with the previous
version retained. No Microsoft login is needed and no copy of Word has to be installed on the
machine — which matters, because the desktop machines run an old version of Office that cannot
do this. Because Word only writes the final version when the document is closed, Done closes the
Word tab first and then collects the file, so the last few edits are never lost.

**4. Simplified template setup and automatic cleanup**
Adding a new template is a two-step form: give it a name, then upload and edit the document.
When something is not available — the connection is down, or the file is the wrong type — the
screen says so in plain words instead of failing silently. Temporary working copies held in
Microsoft 365 are removed automatically in the background. Word keeps a lock on a document for
several minutes after the last person closes it, so the cleanup waits and retries rather than
reporting a false error.

**5. Upload and authoring screen**
Upload a template, open it in Word, and finish the edit — all from one panel on the template
page, with plain-English status messages at each step. The panel also offers Discard, for
abandoning an edit without saving. An existing template can be reopened for editing at any time
from the same page, with no setup and nothing to remember. The panel keeps the list of available
job fields on screen throughout, since that is what the author is copying into the document in
the other tab.

**6. Token picker with multi-select**
A searchable list of every job field available to a template, labelled the same way they appear
on the Job screen, so staff recognise them without being told. Clicking a field copies it ready
to paste into Word. Selecting more than one field builds the instruction for a repeating list
automatically — for example, each attendance officer beside their own note, one per line — which
previously would have required someone to write that instruction by hand. Chips show which
fields are selected and in what order, and a preview shows exactly what will be pasted. If a
value is empty on a particular job, the separator disappears with it rather than leaving a stray
dash in the finished document.

**7. Job data for templates**
All information held against a job is prepared for merging — client, parties, addresses, court
details, dates, fees, attendance notes and documents. Repeating information is supplied as
proper rows, so a template can show every fee or every attendance in a table that grows to fit
the job. Two long-standing data faults were corrected along the way: document titles were
arriving as a single run-on line with no separation, and the affidavit template name attached to
each document was arriving empty. Both now come through correctly, one per line.

**8. Generate the finished document**
From the Job screen, Generate → Affidavit fills that job's template with that job's data and
hands back the finished Word document. The system chooses the template attached to the job's
primary document. The finished file is stored in private storage that is not reachable from the
internet, and every download is permission-checked, which matters because these documents carry
party names and addresses. Dates and times now appear in correct local time in day/month/year
format — previously they were being written in the stored format and were ten hours out.

**9. Document merge service**
The engine that fills the templates, built and run in-house rather than paid for as a vendor
service. It handles repeating table rows, itemised lists that number correctly, and names
containing characters such as `&` that previously would have been silently dropped from the
finished document. It checks that an uploaded file is a genuine Word document, and when a
template has a mistake in it the author is told what to fix rather than being shown a system
error. It is covered by 50 automated tests that run before any change is released, and on the
server it runs as a managed background service that restarts itself and survives a reboot.
