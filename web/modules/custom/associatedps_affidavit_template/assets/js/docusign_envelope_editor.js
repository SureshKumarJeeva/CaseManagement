(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.docusignEnvelopeEditor = {
    attach: function (context) {
      var $container = $('#docusign-envelope-editor-container', context);
      if (!$container.length || $container.data('docusign-initialized')) {
        return;
      }
      $container.data('docusign-initialized', true);

      var envelopeId = $container.data('envelope-id') || '';
      var senderViewUrl = $container.data('sender-view-url') || '';
      var jobId = $container.data('job-id') || '';
      var downloadUrl = $container.data('download-url') || '';
      var refreshUrl = $container.data('refresh-url') || '';

      var $status = $('#docusign-envelope-editor-status');
      var $downloadBtn = $('#docusign-download-btn');
      var $backBtn = $('#docusign-back-btn');
      var $iframe;

      // Refresh interval: DocuSign sender-view URLs are single-use with ~5 min lifetime.
      // Refresh slightly before that to avoid mid-edit expiry.
      var refreshIntervalMs = 4 * 60 * 1000;
      var refreshTimer = null;

      function showStatus(msg, isError) {
        $status.text(msg)
          .removeClass('messages--status messages--error')
          .addClass(isError ? 'messages--error' : 'messages--status')
          .show();
      }

      function loadIframe(url) {
        if (!url) {
          showStatus('Could not load DocuSign editor. Use the Download button to fetch the PDF.', true);
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

        showStatus('Envelope loaded. Adjust as needed, then click Download PDF.', false);
      }

      function scheduleRefresh() {
        if (refreshTimer) {
          clearTimeout(refreshTimer);
        }
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
              showStatus('Editor refresh failed. Reload the page if the iframe stops responding.', true);
            }
          });
        }, refreshIntervalMs);
      }

      loadIframe(senderViewUrl);
      if (senderViewUrl) {
        scheduleRefresh();
      }

      // Listen for postMessage from the signing-callback page, which
      // DocuSign redirects the iframe to after the user clicks Finish
      // (or cancels). On Finish (signing_complete), the user's edits have
      // been saved to the envelope — open the download in a new tab.
      window.addEventListener('message', function (e) {
        var d = e.data;
        if (!d || typeof d !== 'object' || d.source !== 'aps-docusign') {
          return;
        }
        if (d.event === 'signing_complete' || d.event === 'viewing_complete') {
          if (refreshTimer) { clearTimeout(refreshTimer); }
          showStatus('Saved. Opening PDF download in a new tab…', false);
          if (downloadUrl) {
            window.open(downloadUrl, '_blank');
          }
          // Replace iframe with a "done" message so the user can't keep
          // editing (DocuSign URL is single-use post-finish anyway).
          if ($iframe) {
            $iframe.remove();
            $iframe = null;
          }
        }
        else if (d.event === 'cancel' || d.event === 'decline') {
          showStatus('Editing cancelled. Reload the page to start over, or click Download PDF to grab the values as currently saved.', false);
          if ($iframe) {
            $iframe.remove();
            $iframe = null;
          }
        }
        else if (d.event) {
          showStatus('DocuSign reported event: ' + d.event + '. You can still download the current PDF.', false);
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
