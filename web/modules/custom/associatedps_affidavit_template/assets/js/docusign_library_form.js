/**
 * Library form behaviour — template authoring in Word for the web.
 *
 * 2026-09-28: there is no upload-method popup any more. Choosing a file uploads
 * it to Microsoft 365 and the author edits it in Word for the web. Every
 * template is authored this way and merged by our own docxtpl service.
 *
 * ⚠ .docx only. Word for the web cannot open a legacy .doc, Graph will not
 * convert one, and the merge service cannot read one — verified, not assumed.
 * A non-.docx file is refused with a message rather than uploaded into an
 * editor that cannot open it.
 *
 * ⚠ The DocuSign upload path — showMethodModal(), buildCard(), uploadFile() —
 * is now unreachable and awaits the code-removal step.
 *
 * Settings come from drupalSettings.docusignLibrary (see the library form_alter
 * in associatedps_affidavit_template.module).
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.docusignLibraryForm = {
    attach: function (context) {
      $(once('docusign-library-form', 'form.node-library-form, form.node-library-edit-form', context)).each(function () {
        var $form = $(this);
        var settings = drupalSettings.docusignLibrary || {};
        var docgen = settings.docgen || {};

        // On return from either editor: populate the matching template ID
        // field from the URL. Which param is present tells us which flow the
        // user just came back from.
        var urlParams = new URLSearchParams(window.location.search);
        setFieldFromParam(urlParams.get('docusign_template_id'), 'field_docusign_template_id');
        setFieldFromParam(urlParams.get('docgen_template_id'), 'field_docgen_template_id');

        function setFieldFromParam(value, fieldName) {
          if (!value) return;
          var $field = $form.find('[name="' + fieldName + '[0][value]"]');
          if ($field.length) {
            $field.val(value);
          }
        }

        // Token panel, above the form actions. These are the tokens the author
        // pastes INTO the Word document while editing in the other tab, so it
        // has to live on this page for the whole session rather than on an
        // editor page of its own.
        buildTokenPanel($form, settings);

        // Rebuild the Done panel if an editing session is already open on this
        // node. Without this the panel existed only in the browser, so any
        // reload of this tab took the Done button away for good — and a tab
        // left idle while someone edits in Word is exactly what browsers
        // discard and silently reload. The document stayed safe in SharePoint
        // but became unreachable.
        restoreM365Panel($form, settings);

        // File chosen → straight to Microsoft 365. No popup, no choice to make.
        //
        // ⚠ DELEGATED FROM THE FORM, not bound to the input directly.
        // An existing template has no file input on load — it shows the
        // attached file with a Remove button. Pressing Remove runs a Drupal
        // AJAX rebuild that inserts a BRAND NEW file input, and a handler
        // bound to the original element would not be on it. Combined with
        // once() preventing this block from re-running, choosing a
        // replacement document did nothing at all: no upload, no M365 tab.
        // Delegation resolves the selector when the event fires, so it works
        // for every input the widget creates.
        $form.on('change', '.field--name-field-import-template input[type="file"]', function () {
          var files = this.files;
          if (!files || !files.length) return;

          var file = files[0];
          var m365 = settings.m365 || {};

          // Refuse anything Word for the web cannot open, before it is sent
          // anywhere. The reason is shown in the same panel the upload would
          // have used, so the author sees it where they are already looking.
          if (!/\.docx$/i.test(file.name)) {
            m365Error(
              renderM365Panel($form),
              'Only Word .docx files can be used as templates. "' + file.name
                + '" was not uploaded. Open it in Word, choose File → Save As → '
                + 'Word Document (.docx), and upload that file instead.'
            );
            return;
          }

          if (!m365.enabled) {
            m365Error(
              renderM365Panel($form),
              m365.configError || 'Microsoft 365 is not connected, so the document cannot be opened for editing.'
            );
            return;
          }

          uploadToM365(file, $form, m365, settings.nid || '0');
        });

        // "Update at DocuSign" — reopen the tab editor for an existing template.
        $form.on('click', '#docusign-update-btn', function (e) {
          e.preventDefault();
          reopenEditor($(this), $form, {
            sessionUrl: settings.ajaxSessionUrl,
            editorBaseUrl: settings.editorPageBaseUrl,
            label: 'Update at DocuSign'
          });
        });

        // "Update at DocuSign (Doc Gen)" — reopen the Agreement Template
        // Builder for an existing document-generation template.
        $form.on('click', '#docusign-docgen-update-btn', function (e) {
          e.preventDefault();
          reopenEditor($(this), $form, {
            sessionUrl: docgen.ajaxSessionUrl,
            editorBaseUrl: docgen.editorPageBaseUrl,
            label: 'Update at DocuSign (Doc Gen)'
          });
        });

        // "Edit … in Word (Microsoft 365)" — push the document currently on the
        // node back up to SharePoint and open it again. No stored id is needed:
        // the server copy is the authoritative one, so re-editing is just
        // another upload of it.
        $form.on('click', '#m365-update-btn', function (e) {
          e.preventDefault();

          var $btn = $(this);
          var nid = $btn.data('nid') || '0';
          var label = $btn.text();

          // Opened inside the click for the same popup-blocker reason as the
          // first-upload path — see uploadToM365().
          var editorTab = window.open('', '_blank');

          var $status = renderM365Panel($form);
          $status.find('.m365-message').text('Opening the current document in Microsoft 365…');
          $btn.prop('disabled', true).text('Opening…');

          $.ajax({
            url: (settings.m365 || {}).reopenBaseUrl + '/' + nid,
            method: 'POST'
          })
            .done(function (r) {
              $btn.prop('disabled', false).text(label);
              if (!r.editUrl) {
                if (editorTab) { editorTab.close(); }
                m365Error($status, r.error || 'Microsoft 365 did not return an editor address.');
                return;
              }
              if (editorTab) { editorTab.location.href = r.editUrl; }
              showM365Actions($status, r, editorTab);
            })
            .fail(function (xhr) {
              $btn.prop('disabled', false).text(label);
              if (editorTab) { editorTab.close(); }
              m365Error($status, readError(xhr, 'Could not open the document in Microsoft 365.'));
            });
        });
      });

      /**
       * Reopen an editor for a template that already exists at DocuSign.
       *
       * Edit-view URLs are single-use and expire in ~5 minutes, so the button
       * always mints a fresh one rather than storing a link.
       */
      function reopenEditor($btn, $form, opts) {
        var tid = $btn.data('template-id');
        var nid = $btn.data('nid') || '0';
        var category = $form.find('[name="field_category"]').val() || '';

        $btn.text('Opening DocuSign editor…').prop('disabled', true);

        $.ajax({
          url: opts.sessionUrl,
          method: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({ templateId: tid, nid: nid, category: category }),
          success: function (response) {
            if (response.editViewUrl) {
              window.location.href = opts.editorBaseUrl + '/' + tid + '/' + nid
                + '?edit_view_url=' + encodeURIComponent(response.editViewUrl)
                + '&category=' + encodeURIComponent(category);
            } else {
              window.alert('Failed to open DocuSign editor: ' + (response.error || 'Unknown error'));
              $btn.text(opts.label).prop('disabled', false);
            }
          },
          error: function (xhr) {
            window.alert(readError(xhr, 'Failed to connect to DocuSign.'));
            $btn.text(opts.label).prop('disabled', false);
          }
        });
      }

      /**
       * The method popup: two cards, one per DocuSign mechanism.
       *
       * Document Generation is offered only for .docx. That is a hard DocuSign
       * constraint rather than a UI preference — generation works by merging
       * data into the Word document and re-rendering it, which it cannot do to
       * a PDF — so the card is disabled with the reason shown instead of
       * failing after the upload.
       */
      function showMethodModal(file, $form, settings, docgen) {
        $('#docusign-method-modal-overlay').remove();

        var isDocx = /\.docx$/i.test(file.name);
        var docgenReady = !!docgen.configured;

        var $overlay = $('<div id="docusign-method-modal-overlay"></div>').css({
          position: 'fixed',
          top: 0,
          left: 0,
          width: '100%',
          height: '100%',
          backgroundColor: 'rgba(0, 0, 0, 0.6)',
          zIndex: 10000,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center'
        });

        var $modal = $('<div id="docusign-method-modal"></div>').css({
          backgroundColor: '#fff',
          borderRadius: '8px',
          padding: '24px',
          width: '720px',
          maxWidth: '92vw',
          maxHeight: '88vh',
          overflowY: 'auto',
          boxShadow: '0 4px 20px rgba(0, 0, 0, 0.3)'
        });

        $modal.append(
          $('<h3></h3>').text('How should this document be set up at DocuSign?')
            .css({ margin: '0 0 6px 0', fontSize: '18px' }),
          $('<p></p>').text('File: ' + file.name)
            .css({ margin: '0 0 18px 0', color: '#666', fontSize: '14px' })
        );

        var $cards = $('<div></div>').css({ display: 'flex', gap: '16px', flexWrap: 'wrap' });

        var $templateCard = buildCard({
          title: '1 · Template Upload',
          body: 'The document stays exactly as it is. You place Text and Date tabs on it in the DocuSign editor, and job data is written into those tabs when an affidavit is generated.',
          note: 'Any file type. Best when the wording never changes.',
          enabled: true
        });

        var docgenNote = 'Requires a .docx. Best when clauses or tables need to change from job to job.';
        var docgenEnabled = true;
        if (!isDocx) {
          docgenEnabled = false;
          docgenNote = 'Unavailable for this file — Document Generation merges data into a Word document and re-renders it, so it needs a .docx.';
        } else if (!docgenReady) {
          docgenEnabled = false;
          docgenNote = docgen.configError || 'Unavailable — the Document Generation sandbox account is not configured yet.';
        }

        var $docgenCard = buildCard({
          title: '2 · Document Generation',
          body: 'DocuSign merges job data into the Word document itself and regenerates it. Add sender fields with the Agreement Template Builder here, or bring a .docx already prepared with the DocuSign Template Assistant add-in for Word.',
          note: docgenNote,
          enabled: docgenEnabled
        });

        // Third path: author the .docx ourselves in Word for the web, with
        // token merging done later by our own Python service instead of by a
        // vendor. Nothing about cards 1 and 2 changes — this is a sibling
        // branch, and it simply never calls uploadFile(), which is where the
        // DocuSign redirect lives.
        var m365 = settings.m365 || {};
        var m365Note = 'Requires a .docx. Edited in real Word in your browser; tokens are merged by us, not by a vendor.';
        var m365Enabled = true;
        if (!isDocx) {
          m365Enabled = false;
          m365Note = 'Unavailable for this file — Word for the web edits .docx documents.';
        } else if (!m365.enabled) {
          m365Enabled = false;
          m365Note = m365.configError || 'Unavailable — Microsoft 365 is not connected yet.';
        }

        var $m365Card = buildCard({
          title: '3 · Edit in Word (Microsoft 365)',
          body: 'Opens the document in Word for the web in a new tab. Edit it there — it saves as you type — then come back to this page and press “Done — save to server”.',
          note: m365Note,
          enabled: m365Enabled
        });

        $cards.append($templateCard, $docgenCard, $m365Card);

        var $cancel = $('<button type="button" class="button">Cancel</button>');
        var $actions = $('<div></div>')
          .css({ display: 'flex', justifyContent: 'flex-end', marginTop: '18px' })
          .append($cancel);

        $modal.append($cards, $actions);
        $overlay.append($modal);
        $('body').append($overlay);

        $templateCard.on('click', function () {
          $overlay.remove();
          uploadFile(file, $form, {
            uploadUrl: settings.ajaxUploadUrl,
            editorBaseUrl: settings.editorPageBaseUrl,
            templateIdField: 'field_docusign_template_id',
            nid: settings.nid || '0',
            label: 'DocuSign'
          });
        });

        if (docgenEnabled) {
          $docgenCard.on('click', function () {
            $overlay.remove();
            uploadFile(file, $form, {
              uploadUrl: docgen.ajaxUploadUrl,
              editorBaseUrl: docgen.editorPageBaseUrl,
              templateIdField: 'field_docgen_template_id',
              nid: settings.nid || '0',
              label: 'DocuSign Document Generation'
            });
          });
        }

        if (m365Enabled) {
          $m365Card.on('click', function () {
            $overlay.remove();
            uploadToM365(file, $form, m365, settings.nid || '0');
          });
        }

        // Cancelling leaves the file attached to the Library item but uploads
        // nothing — the author can save the node and decide later.
        $cancel.on('click', function () { $overlay.remove(); });
        $overlay.on('click', function (e) {
          if (e.target === $overlay[0]) { $overlay.remove(); }
        });
      }

      function buildCard(opts) {
        var $card = $('<div></div>').css({
          flex: '1 1 300px',
          border: '1px solid ' + (opts.enabled ? '#ccc' : '#e5e5e5'),
          borderRadius: '6px',
          padding: '16px',
          cursor: opts.enabled ? 'pointer' : 'not-allowed',
          opacity: opts.enabled ? 1 : 0.55,
          transition: 'border-color 0.15s, background-color 0.15s'
        });

        $card.append(
          $('<div></div>').text(opts.title).css({ fontWeight: 'bold', fontSize: '15px', marginBottom: '8px' }),
          $('<div></div>').text(opts.body).css({ fontSize: '13px', color: '#444', lineHeight: '1.5' }),
          $('<div></div>').text(opts.note).css({ fontSize: '12px', color: '#777', marginTop: '10px', fontStyle: 'italic' })
        );

        if (opts.enabled) {
          $card.on('mouseenter', function () {
            $(this).css({ borderColor: '#6e7500', backgroundColor: '#f7f8ee' });
          }).on('mouseleave', function () {
            $(this).css({ borderColor: '#ccc', backgroundColor: '' });
          });
        }

        return $card;
      }

      /**
       * POST the file to whichever upload endpoint the chosen method uses and
       * follow DocuSign's editor URL back out of the app.
       */
      function uploadFile(file, $form, opts) {
        var category = $form.find('[name="field_category"]').val() || 'Affidavit';

        $('#docusign-upload-status').remove();
        var $status = $('<div id="docusign-upload-status" class="messages messages--warning" style="margin-top:10px;"></div>')
          .text('Uploading to ' + opts.label + '…');
        $form.find('.field--name-field-import-template').after($status);

        var formData = new FormData();
        formData.append('file', file);
        formData.append('category', category);
        formData.append('nid', opts.nid);

        $.ajax({
          url: opts.uploadUrl,
          method: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          timeout: 90000,
          success: function (response) {
            if (response.templateId && response.editViewUrl) {
              $status.removeClass('messages--warning').addClass('messages--status')
                .text('Upload complete! Redirecting to the ' + opts.label + ' editor…');

              $form.find('[name="' + opts.templateIdField + '[0][value]"]').val(response.templateId);

              window.location.href = opts.editorBaseUrl + '/' + response.templateId + '/' + opts.nid
                + '?edit_view_url=' + encodeURIComponent(response.editViewUrl)
                + '&category=' + encodeURIComponent(category);
            } else {
              $status.removeClass('messages--warning').addClass('messages--error')
                .text('Upload error: ' + (response.error || 'Unknown error'));
            }
          },
          error: function (xhr) {
            $status.removeClass('messages--warning').addClass('messages--error')
              .text(readError(xhr, 'Failed to upload to ' + opts.label + '.'));
          }
        });
      }

      /**
       * Render the searchable click-to-copy token list above the form actions.
       *
       * Reuses ApsTokenPicker, the same component the DocuSign editor page and
       * the email token picker use, fed from the same
       * DocuSignTokenMapper::getJobVariableOptions() source — so the labels an
       * author sees here cannot drift from the ones they see elsewhere.
       *
       * This is the ONE caller that turns multiSelect on. These templates are
       * merged by our own docxtpl service, so a Jinja2 "{%p for %}" block is
       * meaningful here and nowhere else — DocuSign matches a tab's bare Data
       * Label and would ignore one silently.
       */
      function buildTokenPanel($form, settings) {
        var container = $form.find('#m365-token-panel').get(0);
        if (!container || !window.ApsTokenPicker) {
          return;
        }

        var tokens = settings.tokens || [];
        if (!tokens.length) {
          return;
        }

        // Drupal's #states hides the wrapper for categories that do not use a
        // document template, so nothing extra is needed to scope this.
        $(container).append(
          $('<h3></h3>').text('Available tokens')
            .css({ margin: '18px 0 4px 0', fontSize: '15px' }),
          $('<p></p>').text('Search, click to copy, then paste into the Word document where the value should appear.')
            .css({ margin: '0 0 8px 0', color: '#666', fontSize: '13px' }),
          $('<p></p>').text('Click a second field to put both on one line — the panel builds the repeating block for you, so an attendance note lists each employee beside their own note.')
            .css({ margin: '0 0 8px 0', color: '#666', fontSize: '13px' })
        );

        var host = document.createElement('div');
        container.appendChild(host);

        window.ApsTokenPicker.init(host, tokens, {
          label: 'Search & click to copy token',
          idPrefix: 'm365-token',
          multiSelect: true
        });

        buildInvoiceTokenPanel($form, settings);
      }

      /**
       * Invoice category only: the Invoice.* / Invoice_Lines.* tokens, read
       * from Xero when the invoice document is generated. Its wrapper is shown
       * by Drupal's #states for Category = Invoice only.
       */
      function buildInvoiceTokenPanel($form, settings) {
        var container = $form.find('#m365-invoice-token-panel').get(0);
        var tokens = settings.invoiceTokens || [];
        if (!container || !tokens.length) {
          return;
        }

        $(container).append(
          $('<h3></h3>').text('Invoice tokens (from Xero)')
            .css({ margin: '18px 0 4px 0', fontSize: '15px' }),
          $('<p></p>').text('Filled from the invoice as it stands in Xero when the invoice document is downloaded from the Job. Click two or more Invoice Lines fields to build the repeating row for every invoice line.')
            .css({ margin: '0 0 8px 0', color: '#666', fontSize: '13px' })
        );

        // Stacking: the Job token panel sits directly above this one, and its
        // open dropdown list has to cover this panel rather than slide under
        // it. Each panel gets its own stacking context, the upper one higher.
        $form.find('#m365-token-panel').css({ position: 'relative', zIndex: 3 });
        $(container).css({ position: 'relative', zIndex: 2 });

        var host = document.createElement('div');
        container.appendChild(host);

        window.ApsTokenPicker.init(host, tokens, {
          label: 'Search & click to copy invoice token',
          idPrefix: 'm365-invoice-token',
          multiSelect: true
        });
      }

      /**
       * Push the .docx to our SharePoint library, then offer Open in Word.
       *
       * This does NOT navigate away. The Done button lives on this page, so the
       * page has to stay put; the editor gets a tab of its own.
       *
       * ⚠⚠ NO TAB IS OPENED HERE, deliberately — this is the FIRST-UPLOAD path.
       * A browser only allows a new tab within a few seconds of a real click or
       * key press, and a file input's `change` event does not count as one. The
       * only clock running is the click that opened the file dialog, so an
       * author who spent longer than that choosing a file had the tab blocked
       * with nothing to show for it. The method popup used to supply a fresh
       * click of its own, which is what hid this until the popup was removed.
       *
       * So showM365Actions() offers an Open in Word button instead, and the tab
       * opens from that click — which can never be blocked, and still hands back
       * the window handle Done needs to close Word before fetching the file.
       *
       * ⭐ The "Update document" re-edit path is different and keeps opening the
       * tab immediately: that handler runs from a real button click, so it has
       * permission already and needs no button of its own.
       */
      function uploadToM365(file, $form, m365, nid) {
        var $status = renderM365Panel($form);
        $status.find('.m365-message').text('Uploading ' + file.name + ' to Microsoft 365…');

        var formData = new FormData();
        formData.append('file', file);
        formData.append('nid', nid);

        $.ajax({
          url: m365.ajaxUploadUrl,
          method: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          timeout: 120000,
          success: function (response) {
            if (!response.editUrl) {
              m365Error($status, response.error || 'Microsoft 365 did not return an editor address.');
              return;
            }

            // The upload endpoint refuses anything it cannot track, so reaching
            // here means the session is recorded and Done will work. Passing
            // null is what makes the panel show Open in Word.
            showM365Actions($status, response, null);
          },
          error: function (xhr) {
            m365Error($status, readError(xhr, 'Failed to upload to Microsoft 365.'));
          }
        });
      }

      /**
       * Rebuild the Done panel from a session the server says is still open.
       *
       * The upload response is gone after a reload, so the panel is rebuilt
       * from `m365.openSession` instead. Done and Discard are all that is
       * needed — and all that is SAFE; see showM365Actions() on why Reopen is
       * withheld here.
       */
      function restoreM365Panel($form, settings) {
        var m365 = settings.m365 || {};
        var session = m365.openSession;
        if (!session || !session.pullUrl) {
          return;
        }

        var minutesLeft = null;
        if (typeof session.maxEditMinutes === 'number'
            && typeof session.startedMinutesAgo === 'number') {
          minutesLeft = Math.max(0, session.maxEditMinutes - session.startedMinutesAgo);
        }

        showM365Actions(renderM365Panel($form), {
          restored: true,
          fileName: session.fileName || 'The document',
          pullUrl: session.pullUrl,
          discardUrl: session.discardUrl,
          minutesLeft: minutesLeft
          // No editUrl on purpose: the live link died with the old page.
        }, null);
      }

      /**
       * The panel that replaces the DocuSign status line for this path.
       */
      function renderM365Panel($form) {
        $('#m365-panel').remove();
        var $panel = $('<div id="m365-panel" class="messages messages--warning" style="margin-top:10px;"></div>')
          .append($('<div class="m365-message"></div>'))
          .append($('<div class="m365-actions" style="margin-top:10px;"></div>'));
        $form.find('.field--name-field-import-template').after($panel);
        return $panel;
      }

      function m365Error($panel, message) {
        $panel.removeClass('messages--warning messages--status').addClass('messages--error');
        $panel.find('.m365-message').text(message);
        $panel.find('.m365-actions').empty();
      }

      function m365Warn($panel, message) {
        $panel.removeClass('messages--status messages--error').addClass('messages--warning');
        $panel.find('.m365-message').text(message);
        $panel.find('.m365-actions').empty();
      }

      /**
       * Done / Discard, shown once the document is open for editing.
       */
      function showM365Actions($panel, response, editorTab) {
        $panel.removeClass('messages--warning messages--error').addClass('messages--status');

        // The Word tab we are allowed to close on Done. Normally the one
        // uploadToM365() opened; null when the browser blocked it, in which case
        // the Open in Word button below fills it in from a real click.
        var tab = editorTab;

        var message;

        if (response.restored) {
          // Rebuilt from the server-side session after the page reloaded, so
          // there is no editor tab of ours to refer to and no fresh edit link.
          // ⚠ We have no handle on the author's Word tab after a reload, so we
          // cannot close it for them — and closing it is what makes Word
          // commit its final save. Hence the explicit instruction; the server
          // still polls for the commit (waitForCommit), but closing first
          // makes it arrive promptly rather than at Word's own pace.
          message = response.fileName + ' is still open for editing in Word for the web. '
            + 'Finish your edits, CLOSE the Word tab, then press Done here to save it.';
          if (response.minutesLeft !== null && response.minutesLeft !== undefined) {
            message += ' About ' + response.minutesLeft + ' minutes of the 4-hour editing '
              + 'window remain.';
          }
        }
        else if (tab) {
          message = response.fileName + ' is open in Word for the web in another tab. '
            + 'It saves as you type. When you have finished, come back here and press Done.';

          // The server could not mint a sharing link, so the editor tab will
          // ask for a Microsoft sign-in. Saying so here beats leaving the
          // author staring at a login screen and assuming it is broken.
          if (response.anonymousLink === false) {
            message += ' Microsoft 365 asked for a sign-in because a sharing link '
              + 'could not be created for this document.';
          }
        }
        else {
          // The normal FIRST-UPLOAD state: uploaded, session recorded, and no
          // tab yet because a file input's change event carries no permission
          // to open one (see uploadToM365()). One click supplies it.
          message = response.fileName + ' has been uploaded and is ready to edit. '
            + 'Click Open in Word — it saves as you type. When you have finished, '
            + 'come back here and press Done.';

          if (response.anonymousLink === false) {
            message += ' Microsoft 365 will ask for a sign-in because a sharing link '
              + 'could not be created for this document.';
          }
        }

        // Cron abandons an editing session once it is four hours old, after
        // which Done can no longer retrieve the document. Say so up front
        // rather than let someone discover it by losing an afternoon's work.
        message += ' Please finish and press Done within 4 hours — after that the '
          + 'editing session expires and the document can no longer be saved back.';

        $panel.find('.m365-message').text(message);

        var $done = $('<button type="button" class="button button--primary">Done — save to server</button>');
        var $discard = $('<button type="button" class="button" style="margin-left:8px;">Discard</button>');

        $panel.find('.m365-actions').empty().append($done, $discard);

        // Reopen is offered ONLY with a live edit link from this session's
        // upload, where it simply reopens the same SharePoint document.
        //
        // ⚠⚠ It is deliberately absent from a panel rebuilt after a reload.
        // We have no fresh link then, and the only alternative — the reopen
        // endpoint — re-uploads the node's CURRENT file, which would replace
        // the copy being edited and destroy the author's work. The restored
        // message points them back to the Word tab instead.
        if (response.editUrl && tab) {
          $panel.find('.m365-actions').append(
            $('<a class="button" style="margin-left:8px;" target="_blank" rel="noopener noreferrer">Reopen editor</a>')
              .attr('href', response.editUrl)
          );
        }
        else if (response.editUrl) {
          // First upload: this button IS how the editor opens.
          // ⚠ window.open() and NOT a plain link: a link opened with
          // rel="noopener" hands back no window handle, and Done needs that
          // handle to close Word before fetching the file — Word only writes its
          // final version when the editing session ends.
          var $open = $('<button type="button" class="button button--primary" style="margin-left:8px;">Open in Word</button>');
          $panel.find('.m365-actions').append($open);

          $open.on('click', function () {
            var opened = window.open(response.editUrl, '_blank');
            if (opened) {
              // Redraw with the live handle, which restores the normal message
              // and the Reopen link in one place rather than two.
              showM365Actions($panel, response, opened);
              return;
            }

            // Blocked even from a real click (a blanket popup block). Hand over
            // a plain link; Done can no longer close Word for them, so say so.
            $open.replaceWith(
              $('<a class="button button--primary" style="margin-left:8px;" target="_blank" rel="noopener noreferrer">Open in Word</a>')
                .attr('href', response.editUrl)
            );
            $panel.find('.m365-message').text(
              response.fileName + ' is ready to edit, but this browser is blocking new tabs. '
                + 'Open it in Word, and CLOSE the Word tab yourself before pressing Done here, '
                + 'or the last few edits may not be saved. Done still works within 4 hours.'
            );
          });
        }

        $done.on('click', function () {
          $done.prop('disabled', true).text('Closing Word…');
          $discard.prop('disabled', true);

          // ⚠⚠ CLOSE THE EDITOR TAB *BEFORE* PULLING, not after.
          //
          // Word for the web holds edits in its co-authoring session and only
          // writes them to the drive item periodically and when the session
          // ends. While that tab is open the server can read a version from
          // before the author typed anything — which is exactly what happened
          // on 2026-08-28: a token typed, Done pressed 22 seconds later, and a
          // stale file written back over the author's work while the message
          // claimed nothing had changed.
          //
          // Closing the tab ends the session and triggers Word's final save.
          // The server then polls for the new version (see waitForCommit()),
          // so the short delay here is a nudge, not the guarantee.
          if (tab && !tab.closed) { tab.close(); }

          window.setTimeout(function () {
            $done.text('Fetching from Microsoft 365…');
            pullFromM365();
          }, 1200);

          function pullFromM365() {
          // Send the category along with the pull.
          //
          // On the ADD flow the node was created from step one with only a
          // Title, so the category the author picked lives ONLY in this
          // unsubmitted form. Saving it server-side with the file is what
          // stops the reload below from coming back with an empty category,
          // which would hide the file element (a #states rule) and make the
          // document they just authored look lost.
          // Scoped to the panel's own form rather than the document: $form
          // from the outer .each() is not in scope here, and a page-wide
          // selector could pick up a category field belonging to something
          // else entirely.
          var $ownerForm = $panel.closest('form');
          var $categoryEl = ($ownerForm.length ? $ownerForm : $(document))
            .find('[name="field_category"]');
          $.ajax({
            url: response.pullUrl,
            method: 'POST',
            data: { category: $categoryEl.val() || '' }
          })
            .done(function (r) {
              $panel.find('.m365-message').text(r.message || 'Saved.');
              $panel.find('.m365-actions').empty();

              // The node was saved server-side, so this form is now showing a
              // stale file reference. Pressing Save on it would write the OLD
              // file back over the one just authored, so reload rather than
              // leave that trap open.
              window.setTimeout(function () { window.location.reload(); }, 1500);
            })
            .fail(function (xhr) {
              m365Error($panel, readError(xhr, 'Could not fetch the document back from Microsoft 365.'));
            });
          }
        });

        $discard.on('click', function () {
          if (!window.confirm('Discard the online copy? Any edits made in Word for the web will be lost.')) {
            return;
          }
          $discard.prop('disabled', true);
          $.ajax({ url: response.discardUrl, method: 'POST' })
            .always(function () {
              if (tab && !tab.closed) { tab.close(); }
              m365Warn($panel, 'The online copy was discarded. This Library item is unchanged.');
            });
        });
      }

      function readError(xhr, fallback) {
        try {
          return JSON.parse(xhr.responseText).error || fallback;
        } catch (e) {
          return fallback;
        }
      }
    }
  };

})(jQuery, Drupal, drupalSettings, once);
