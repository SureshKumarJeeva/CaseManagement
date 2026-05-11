import { Editor } from 'pandadoc-editor';

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  function showStatus(message, isError) {
    $('#pandadoc-editor-status')
      .text(message)
      .toggleClass('messages--error', isError)
      .toggleClass('messages--status', !isError)
      .show();
  }

  Drupal.behaviors.pandadocEditor = {
    attach: function (context) {
      $(once('pandadoc-editor', '#pandadoc-editor-container', context)).each(function () {
        var settings = drupalSettings.pandadocEditor || {};
        var token = settings.sessionId || '';
        var callbackUrl = settings.callbackUrl || '';

        if (!token) {
          showStatus('Error: No editing session token available. Template may have been created — check PandaDoc dashboard.', true);
          return;
        }

        var editor = new Editor(
          'pandadoc-editor-container',
          { token: token, debugMode: true },
          { region: 'com' }
        );

        editor.on('content.rendered', function () {
          showStatus('Editor loaded. You can now edit the template.', false);
        });

        var loadingErrorHandler = function () {
          showStatus('An error occurred loading content in the PandaDoc editor.', true);
        };
        editor.on('document.loading.error', loadingErrorHandler);
        editor.on('template.loading.error', loadingErrorHandler);

        editor.open().catch(function (err) {
          console.error('[PandaDoc Editor] open() failed:', err);
          showStatus('Failed to open the PandaDoc editor. Please try again.', true);
        });

        Drupal.pandadocEditorInstance = editor;

        // "Done Editing" button as manual fallback.
        var $doneBtn = $('<button type="button" class="button button--primary" style="margin-top:10px;">Done Editing — Return to Drupal</button>');
        $doneBtn.on('click', function () {
          if (Drupal.pandadocEditorInstance) {
            Drupal.pandadocEditorInstance.destroy();
            Drupal.pandadocEditorInstance = null;
          }
          window.location.href = callbackUrl;
        });
        $('#pandadoc-editor-container').after($doneBtn);
      });
    },

    detach: function (context, settings, trigger) {
      if (trigger === 'unload' && Drupal.pandadocEditorInstance) {
        Drupal.pandadocEditorInstance.destroy();
        Drupal.pandadocEditorInstance = null;
      }
    }
  };

})(jQuery, Drupal, drupalSettings, once);
