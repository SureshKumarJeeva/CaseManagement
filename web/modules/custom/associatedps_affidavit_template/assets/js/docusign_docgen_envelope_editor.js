/**
 * DocuSign Document Generation — generated-document review page.
 *
 * Hosts DocuSign's embedded SENDER view, which for a document-generation
 * envelope opens on the document generation form: the sender fields, already
 * carrying the values this app pushed in, with the regenerated document
 * alongside. The user edits values only; the document body belongs to the
 * template.
 *
 * Sender-view URLs are single-use with a ~5 minute life, so the iframe is
 * re-minted on a timer. When DocuSign redirects the iframe out (Send, Save,
 * cancel, session end) it lands on our anonymous sender-callback page, which
 * postMessages this window — see the controller for why that hop exists.
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.docusignDocGenEnvelopeEditor = {
    attach: function (context) {
      var $container = $('#docusign-docgen-envelope-editor-container', context);
      if (!$container.length || $container.data('docgen-initialized')) {
        return;
      }
      $container.data('docgen-initialized', true);

      var senderViewUrl = $container.data('sender-view-url') || '';
      var jobId = $container.data('job-id') || '';
      var downloadUrl = $container.data('download-url') || '';
      var refreshUrl = $container.data('refresh-url') || '';

      var $status = $('#docusign-docgen-envelope-editor-status');
      var $downloadBtn = $('#docusign-docgen-download-btn');
      var $backBtn = $('#docusign-docgen-back-btn');
      var $iframe = null;
      var refreshTimer = null;

      function showStatus(msg, isError) {
        $status.text(msg)
          .removeClass('messages--status messages--error')
          .addClass(isError ? 'messages--error' : 'messages--status')
          .show();
      }

      function loadIframe(url) {
        if (!url) {
          showStatus('Could not load the DocuSign editor. Use Download PDF to fetch the document as generated.', true);
          return;
        }

        if (!$iframe) {
          $iframe = $('<iframe>', {
            src: url,
            width: '100%',
            height: '100%',
            frameborder: '0',
            style: 'width:100%; height:calc(100vh - 160px); min-height:720px; border:1px solid #ccc; border-radius:4px;',
            allow: 'clipboard-read; clipboard-write'
          });
          $container.append($iframe);
        }
        else {
          $iframe.attr('src', url);
        }

        showStatus('Document generated. Adjust any field values, then close the DocuSign panel and click Download PDF.', false);
      }

      function scheduleRefresh() {
        if (refreshTimer) { clearTimeout(refreshTimer); }
        refreshTimer = setTimeout(function () {
          $.ajax({
            url: refreshUrl,
            type: 'POST',
            dataType: 'json',
            data: { job_id: jobId },
            success: function (response) {
              if (response.senderViewUrl) {
                loadIframe(response.senderViewUrl);
                scheduleRefresh();
              }
              else if (response.error) {
                showStatus('Editor refresh failed: ' + response.error, true);
              }
            },
            error: function () {
              showStatus('Editor refresh failed. Reload the page if the panel stops responding.', true);
            }
          });
        }, 4 * 60 * 1000);
      }

      loadIframe(senderViewUrl);
      if (senderViewUrl) {
        scheduleRefresh();
      }

      window.addEventListener('message', function (e) {
        var d = e.data;
        if (!d || typeof d !== 'object' || d.source !== 'aps-docusign-docgen') {
          return;
        }

        if (refreshTimer) { clearTimeout(refreshTimer); }

        // DocuSign's sender view exits with send / save / cancel / sessionEnd.
        // All but an outright cancel mean the field values were committed and
        // the document re-rendered, so the PDF is worth fetching.
        if (d.event === 'cancel') {
          showStatus('Editing cancelled. Download PDF still fetches the document as it was last generated.', false);
        }
        else {
          showStatus('Saved. Opening the PDF in a new tab…', false);
          if (downloadUrl) {
            window.open(downloadUrl, '_blank');
          }
        }

        // The URL is spent either way — drop the iframe rather than leave a
        // dead panel on screen.
        if ($iframe) {
          $iframe.remove();
          $iframe = null;
        }
      });

      $downloadBtn.on('click', function () {
        if (downloadUrl) {
          window.open(downloadUrl, '_blank');
        }
      });

      $backBtn.on('click', function () {
        if (jobId) {
          window.location.href = '/node/' + jobId + '/edit';
        }
        else {
          window.history.back();
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
