/**
 * DocuSign Library form behavior — auto-uploads a chosen file to DocuSign
 * and redirects the user into the embedded template editor.
 *
 * Mirrors pandadoc_library_form.js in structure; lives in parallel so the
 * dormant PandaDoc behavior is untouched. Settings come from
 * drupalSettings.docusignLibrary (populated by the module's library
 * form_alter hook).
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.docusignLibraryForm = {
    attach: function (context) {
      $(once('docusign-library-form', 'form.node-library-form, form.node-library-edit-form', context)).each(function () {
        var $form = $(this);
        var settings = drupalSettings.docusignLibrary || {};

        // On return from DocuSign editor: populate template ID from URL.
        var urlParams = new URLSearchParams(window.location.search);
        var templateId = urlParams.get('docusign_template_id');
        if (templateId) {
          var $field = $form.find('[name="field_docusign_template_id[0][value]"]');
          if ($field.length) {
            $field.val(templateId);
          }
        }

        // Auto-upload to DocuSign when user selects a file.
        var $fileInput = $form.find('.field--name-field-import-template input[type="file"]');
        $fileInput.on('change', function () {
          var files = this.files;
          if (!files || !files.length) return;
          uploadFileToDocuSign(files[0], $form, settings);
        });

        // "Update at DocuSign" button — refresh edit view for existing template.
        $form.on('click', '#docusign-update-btn', function (e) {
          e.preventDefault();
          var $btn = $(this);
          var tid = $btn.data('template-id');
          var nid = $btn.data('nid') || '0';
          var category = $form.find('[name="field_category"]').val() || '';

          $btn.text('Opening DocuSign editor…').prop('disabled', true);

          $.ajax({
            url: settings.ajaxSessionUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ templateId: tid, nid: nid, category: category }),
            success: function (response) {
              if (response.editViewUrl) {
                window.location.href = settings.editorPageBaseUrl + '/' + tid + '/' + nid
                  + '?edit_view_url=' + encodeURIComponent(response.editViewUrl)
                  + '&category=' + encodeURIComponent(category);
              } else {
                alert('Failed to open DocuSign editor: ' + (response.error || 'Unknown error'));
                $btn.text('Update at DocuSign').prop('disabled', false);
              }
            },
            error: function (xhr) {
              var msg = 'Failed to connect to DocuSign.';
              try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
              alert(msg);
              $btn.text('Update at DocuSign').prop('disabled', false);
            }
          });
        });
      });

      function uploadFileToDocuSign(file, $form, settings) {
        var nid = settings.nid || '0';
        var category = $form.find('[name="field_category"]').val() || 'Affidavit';

        var $statusMsg = $('<div id="docusign-upload-status" class="messages messages--warning" style="margin-top:10px;">Uploading to DocuSign…</div>');
        $form.find('.field--name-field-import-template').after($statusMsg);

        var formData = new FormData();
        formData.append('file', file);
        formData.append('category', category);
        formData.append('nid', nid);

        $.ajax({
          url: settings.ajaxUploadUrl,
          method: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          timeout: 90000,
          success: function (response) {
            if (response.templateId && response.editViewUrl) {
              $statusMsg.removeClass('messages--warning').addClass('messages--status')
                .text('Upload complete! Redirecting to DocuSign editor…');

              $form.find('[name="field_docusign_template_id[0][value]"]').val(response.templateId);

              window.location.href = settings.editorPageBaseUrl + '/' + response.templateId + '/' + nid
                + '?edit_view_url=' + encodeURIComponent(response.editViewUrl)
                + '&category=' + encodeURIComponent(category);
            } else {
              $statusMsg.removeClass('messages--warning').addClass('messages--error')
                .text('DocuSign upload error: ' + (response.error || 'Unknown error'));
            }
          },
          error: function (xhr) {
            var msg = 'Failed to upload to DocuSign.';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
            $statusMsg.removeClass('messages--warning').addClass('messages--error').text(msg);
          }
        });
      }
    }
  };

})(jQuery, Drupal, drupalSettings, once);
