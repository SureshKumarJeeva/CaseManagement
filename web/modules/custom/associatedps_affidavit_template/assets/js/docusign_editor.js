/**
 * DocuSign Template Editor — iframe wrapper.
 *
 * Loads the embedded template edit-view URL in an iframe. DocuSign's
 * edit-view URL is single-use and expires after ~5 minutes; this script
 * watches for expiry signals (postMessage from the iframe, or click on the
 * iframe after long idle) and refreshes the URL via the ajax-edit-view
 * endpoint without forcing the user to leave the page.
 *
 * DocuSign emits a postMessage when the user clicks Save/Cancel inside the
 * iframe (the returnUrl is what the iframe then navigates to). We don't
 * need to intercept Save — DocuSign redirects the iframe to our callback
 * URL, which writes field_docusign_template_id and bounces back to the
 * Library node edit page. The "Done Editing" button is a manual escape.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.docusignEditor = {
    attach: function (context) {
      once('docusign-editor', '#docusign-editor-iframe', context).forEach(function (iframe) {
        var settings = drupalSettings.docusignEditor || {};
        var refreshUrl = settings.refreshUrl || '/admin/content/docusign/ajax-edit-view';

        // Initialize the searchable token picker above the iframe using the
        // shared ApsTokenPicker utility (same one PandaDoc uses). Users type
        // to filter, click a token → copied to clipboard with a "Copied!"
        // flash → paste into the Data Label field of a tab in DocuSign.
        //
        // Entries are {label, value}: the label is the field's readable
        // Tab-Section-Field path from the Job form display, the value is the
        // token actually copied. They differ, hence the wording below.
        var pickerContainer = document.getElementById('docusign-token-picker-container');
        var variables = settings.variables || [];
        if (pickerContainer && variables.length && window.ApsTokenPicker) {
          window.ApsTokenPicker.init(pickerContainer, variables, {
            label: 'Search by where the field sits on the Job form (Tab-Section-Field) and click it. The token itself is copied to your clipboard — paste it into the "Data Label" field of a tab in DocuSign.',
            idPrefix: 'docusign-token'
          });
        }

        // Show a status banner.
        function showStatus(msg, level) {
          var $status = document.getElementById('docusign-editor-status');
          if (!$status) return;
          $status.style.display = 'block';
          $status.className = 'messages messages--' + (level || 'status');
          $status.textContent = msg;
        }

        // Refresh the iframe src with a fresh edit-view URL.
        function refreshEditView() {
          showStatus('Edit session expired — refreshing…', 'warning');
          fetch(refreshUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              templateId: settings.templateId,
              nid: settings.nid,
            }),
            credentials: 'same-origin',
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.editViewUrl) {
                iframe.src = data.editViewUrl;
                showStatus('Editor refreshed.', 'status');
                setTimeout(function () {
                  document.getElementById('docusign-editor-status').style.display = 'none';
                }, 3000);
              } else {
                showStatus('Refresh failed: ' + (data.error || 'unknown error'), 'error');
              }
            })
            .catch(function (err) {
              showStatus('Refresh failed: ' + err.message, 'error');
            });
        }

        // Auto-refresh ~4 minutes after the iframe loads (DocuSign URLs
        // expire at 5 minutes). Reset the timer each time the iframe
        // reloads (e.g. after Save returns the user via the callback).
        var refreshTimer = null;
        function scheduleRefresh() {
          if (refreshTimer) clearTimeout(refreshTimer);
          refreshTimer = setTimeout(refreshEditView, 4 * 60 * 1000);
        }
        iframe.addEventListener('load', scheduleRefresh);
        scheduleRefresh();

        // Listen for postMessage from our /docusign/template-editor-bounce
        // page, which DocuSign's template-editor iframe redirects to on
        // Save/Cancel. The bounce page renders only the postMessage script
        // (the route is anonymous-accessible — required because the iframe's
        // cross-origin redirect to our domain does not carry the Drupal
        // session cookie under SameSite=Lax). When this parent window
        // receives the message, we navigate the TOP window (same-site,
        // top-level) to the protected editor-callback URL, which carries
        // the session cookie and runs the node save as the logged-in admin.
        window.addEventListener('message', function (event) {
          var d = event.data;
          if (!d || typeof d !== 'object' || d.source !== 'aps-docusign') {
            return;
          }
          if (d.event === 'template_save' || d.event === 'TEMPLATE_SAVE' || d.event === 'Save') {
            var url = '/admin/content/docusign/editor-callback'
              + '?nid=' + encodeURIComponent(d.nid || settings.nid || '0')
              + '&docusign_template_id=' + encodeURIComponent(d.templateId || settings.templateId || '')
              + '&category=' + encodeURIComponent(d.category || '');
            window.location.href = url;
          }
          else if (d.event === 'cancel' || d.event === 'Cancel') {
            // User backed out — just go back to the Library form without
            // touching the docusign template id.
            if (settings.nid && settings.nid !== '0') {
              window.location.href = '/node/' + settings.nid + '/edit';
            }
            else {
              window.location.href = '/node/add/library';
            }
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
