/**
 * DocuSign Document Generation — template editor iframe wrapper.
 *
 * Same iframe/refresh/bounce mechanics as docusign_editor.js (the DocuSign
 * edit-view URL is single-use and expires in ~5 minutes; the cross-origin
 * redirect home cannot carry our session cookie, so the iframe bounces through
 * an anonymous page that postMessages this window).
 *
 * What differs is what the author does inside the iframe. There are no tabs to
 * drag here: they open the document with the Agreement Template Builder and add
 * SENDER FIELDS, and the name given to each field is the whole contract with
 * this app. The picker above the iframe therefore hands over names to use, the
 * same way the tab editor's picker hands over Data Labels.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.docusignDocGenEditor = {
    attach: function (context) {
      once('docusign-docgen-editor', '#docusign-docgen-editor-iframe', context).forEach(function (iframe) {
        var settings = drupalSettings.docusignDocGenEditor || {};
        var refreshUrl = settings.refreshUrl || '/admin/content/docusign-docgen/ajax-edit-view';

        var pickerContainer = document.getElementById('docusign-docgen-token-picker-container');
        var variables = settings.variables || [];
        if (pickerContainer && variables.length && window.ApsTokenPicker) {
          window.ApsTokenPicker.init(pickerContainer, variables, {
            label: 'Search by where the field sits on the Job form (Tab-Section-Field) and click it. The name is copied to your clipboard — use it as the NAME of the matching sender field in the Agreement Template Builder.',
            idPrefix: 'docusign-docgen-token'
          });
        }

        function showStatus(msg, level) {
          var status = document.getElementById('docusign-docgen-editor-status');
          if (!status) return;
          status.style.display = 'block';
          status.className = 'messages messages--' + (level || 'status');
          status.textContent = msg;
        }

        function refreshEditView() {
          showStatus('Edit session expired — refreshing…', 'warning');
          fetch(refreshUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              templateId: settings.templateId,
              nid: settings.nid
            }),
            credentials: 'same-origin'
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.editViewUrl) {
                iframe.src = data.editViewUrl;
                showStatus('Editor refreshed.', 'status');
                setTimeout(function () {
                  var status = document.getElementById('docusign-docgen-editor-status');
                  if (status) { status.style.display = 'none'; }
                }, 3000);
              } else {
                showStatus('Refresh failed: ' + (data.error || 'unknown error'), 'error');
              }
            })
            .catch(function (err) {
              showStatus('Refresh failed: ' + err.message, 'error');
            });
        }

        // Refresh just before DocuSign's 5-minute expiry, and restart the
        // clock whenever the iframe reloads.
        var refreshTimer = null;
        function scheduleRefresh() {
          if (refreshTimer) { clearTimeout(refreshTimer); }
          refreshTimer = setTimeout(refreshEditView, 4 * 60 * 1000);
        }
        iframe.addEventListener('load', scheduleRefresh);
        scheduleRefresh();

        // The bounce page (anonymous, cookie-free) tells us the editor closed.
        // We then navigate the TOP window to the protected callback, which is
        // same-site and does carry the session cookie, so the node save runs
        // as the logged-in user.
        window.addEventListener('message', function (event) {
          var d = event.data;
          if (!d || typeof d !== 'object' || d.source !== 'aps-docusign-docgen') {
            return;
          }

          if (d.event === 'template_save' || d.event === 'Save') {
            window.location.href = '/admin/content/docusign-docgen/editor-callback'
              + '?nid=' + encodeURIComponent(d.nid || settings.nid || '0')
              + '&docgen_template_id=' + encodeURIComponent(d.templateId || settings.templateId || '')
              + '&category=' + encodeURIComponent(d.category || '');
          }
          else if (d.event === 'cancel' || d.event === 'Cancel') {
            // Backed out — return to the Library form without recording an ID.
            window.location.href = (settings.nid && settings.nid !== '0')
              ? '/node/' + settings.nid + '/edit'
              : '/node/add/library';
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
