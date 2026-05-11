(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.pandadocDocumentEditor = {
    attach: function (context) {
      var $container = $('#pandadoc-document-editor-container', context);
      if (!$container.length || $container.data('pandadoc-initialized')) {
        return;
      }
      $container.data('pandadoc-initialized', true);

      var sessionId = $container.data('session-id') || '';
      var documentId = $container.data('document-id') || '';
      var downloadUrl = $container.data('download-url') || '';
      var jobId = $container.data('job-id') || '';
      var $status = $('#pandadoc-document-editor-status');
      var $downloadBtn = $('#pandadoc-download-btn');
      var $backBtn = $('#pandadoc-back-btn');

      // Embed PandaDoc document via iframe using the session ID.
      if (sessionId) {
        var iframeUrl = 'https://app.pandadoc.com/s/' + sessionId;
        var $iframe = $('<iframe>', {
          src: iframeUrl,
          width: '100%',
          height: '100%',
          frameborder: '0',
          style: 'width:100%; height:calc(100vh - 200px); min-height:600px; border:1px solid #ccc; border-radius:4px;',
          allow: 'clipboard-write'
        });
        $container.append($iframe);

        $status.text('Document loaded. You can review and edit it below.')
          .removeClass('messages--error')
          .addClass('messages--status')
          .show();
      }
      else {
        $status.text('Could not create editing session. Use the Download button to get the PDF.')
          .removeClass('messages--status')
          .addClass('messages--error')
          .show();
      }

      // Download PDF button.
      $downloadBtn.on('click', function () {
        if (downloadUrl) {
          window.open(downloadUrl, '_blank');
        }
      });

      // Back to Job button.
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
