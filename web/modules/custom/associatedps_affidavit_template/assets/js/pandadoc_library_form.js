(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.pandadocLibraryForm = {
    attach: function (context) {
      $(once('pandadoc-library-form', 'form.node-library-form, form.node-library-edit-form', context)).each(function () {
        var $form = $(this);
        var settings = drupalSettings.pandadocLibrary || {};

        // On return from PandaDoc editor: populate template ID from URL query param.
        var urlParams = new URLSearchParams(window.location.search);
        var pandadocTemplateId = urlParams.get('pandadoc_template_id');
        if (pandadocTemplateId) {
          var $templateField = $form.find('[name="field_pandadoc_template_id[0][value]"]');
          if ($templateField.length) {
            $templateField.val(pandadocTemplateId);
          }
        }

        // Auto-upload to PandaDoc when user selects a file.
        var $fileInput = $form.find('.field--name-field-import-template input[type="file"]');
        $fileInput.on('change', function () {
          var files = this.files;
          if (!files || !files.length) {
            return;
          }
          uploadFileToPandaDoc(files[0], $form, settings);
        });

        // "Update at PandaDoc" button click — create new session for existing template.
        $form.on('click', '#pandadoc-update-btn', function (e) {
          e.preventDefault();
          var $btn = $(this);
          var templateId = $btn.data('template-id');
          var nid = $btn.data('nid') || '0';

          $btn.text('Opening editor...').prop('disabled', true);

          $.ajax({
            url: settings.ajaxSessionUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ templateId: templateId }),
            success: function (response) {
              if (response.sessionToken) {
                window.location.href = settings.editorPageBaseUrl + '/' + templateId + '/' + nid + '?session_id=' + response.sessionToken;
              } else {
                alert('Failed to create editing session: ' + (response.error || 'Unknown error'));
                $btn.text('Update at PandaDoc').prop('disabled', false);
              }
            },
            error: function (xhr) {
              var msg = 'Failed to connect to PandaDoc.';
              try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
              alert(msg);
              $btn.text('Update at PandaDoc').prop('disabled', false);
            }
          });
        });

        // Category-based field visibility (supplement #states).
        var $categoryField = $form.find('[name="field_category"]');
        if ($categoryField.length) {
          $categoryField.on('change', function () {
            handleCategoryChange($(this).val(), $form);
          });
          handleCategoryChange($categoryField.val(), $form);
        }
      });

      function uploadFileToPandaDoc(file, $form, settings) {
        var nid = settings.nid || '0';
        var category = $form.find('[name="field_category"]').val() || 'Affidavit';

        // Show loading state.
        var $statusMsg = $('<div id="pandadoc-upload-status" class="messages messages--warning" style="margin-top:10px;">Uploading to PandaDoc... This may take up to 30 seconds.</div>');
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
            if (response.templateId && response.sessionToken) {
              $statusMsg.removeClass('messages--warning').addClass('messages--status')
                .text('Upload complete! Redirecting to PandaDoc editor...');

              // Populate the template ID field before redirect.
              $form.find('[name="field_pandadoc_template_id[0][value]"]').val(response.templateId);

              // Redirect to editor page (pass category for return trip).
              window.location.href = settings.editorPageBaseUrl + '/' + response.templateId + '/' + nid + '?session_id=' + response.sessionToken + '&category=' + encodeURIComponent(category);
            } else {
              $statusMsg.removeClass('messages--warning').addClass('messages--error')
                .text('PandaDoc upload error: ' + (response.error || 'Unknown error'));
            }
          },
          error: function (xhr) {
            var msg = 'Failed to upload to PandaDoc.';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
            $statusMsg.removeClass('messages--warning').addClass('messages--error')
              .text(msg);
          }
        });
      }

      function handleCategoryChange(category, $form) {
        var $emailSubject = $form.find('.field--name-field-email-subject');
        var $body = $form.find('.field--name-body');
        var $import = $form.find('.field--name-field-import-template');
        var $tokens = $form.find('.field--name-field-available-tokens');
        var $instructionTokens = $form.find('.field--name-field-instruction-tokens');
        var $pandadocId = $form.find('.field--name-field-pandadoc-template-id');
        var $updateBtn = $form.find('#pandadoc-update-btn');

        switch (category) {
          case 'Email':
            $emailSubject.show();
            $body.show();
            $import.hide();
            $tokens.show();
            $instructionTokens.show();
            $pandadocId.hide();
            $updateBtn.hide();
            initEmailTokenPicker($form, $tokens);
            break;

          case 'Affidavit':
          case 'Correspondence':
            $emailSubject.hide();
            $body.hide();
            $import.show();
            $tokens.hide();
            $instructionTokens.hide();
            $pandadocId.show();
            $updateBtn.show();
            removeEmailTokenPicker($tokens);
            break;

          default:
            $emailSubject.show();
            $body.show();
            $import.show();
            $tokens.hide();
            $instructionTokens.hide();
            $pandadocId.hide();
            $updateBtn.hide();
            removeEmailTokenPicker($tokens);
            break;
        }
      }

      /**
       * Initialize the email token picker: hide the textarea and show
       * a searchable dropdown using the shared ApsTokenPicker utility.
       * Fetches token list from the server on first init.
       */
      function initEmailTokenPicker($form, $tokensWrapper) {
        // Hide the textarea widget — the token picker replaces it visually.
        $tokensWrapper.find('textarea').hide();

        // Don't re-init if the picker panel is already present.
        if ($tokensWrapper.find('[class*="email-token-panel"]').length) {
          return;
        }

        // Fetch tokens from the server, then build the picker.
        $.ajax({
          url: '/pandadoc/email-token-list',
          type: 'GET',
          dataType: 'json',
          success: function (response) {
            if (!response.tokens || !response.tokens.length) {
              $tokensWrapper.append('<div class="email-token-panel" style="padding:10px; color:#888;">No tokens available.</div>');
              return;
            }

            // Wrap each token name with {{}} delimiters for the email template format.
            var displayTokens = response.tokens.map(function (name) {
              return '{{' + name + '}}';
            });

            // Use the shared token picker utility.
            window.ApsTokenPicker.init($tokensWrapper[0], displayTokens, {
              label: 'Search & click to copy token, then paste into Subject or Body',
              idPrefix: 'email-token'
            });
          },
          error: function () {
            $tokensWrapper.append('<div class="email-token-panel" style="padding:10px; color:#c00;">Failed to load tokens.</div>');
          }
        });
      }

      /**
       * Remove the token picker and restore the textarea
       * when switching away from Email category.
       */
      function removeEmailTokenPicker($tokensWrapper) {
        $tokensWrapper.find('[class*="email-token"]').remove();
        $tokensWrapper.find('textarea').show();
      }
    }
  };

})(jQuery, Drupal, drupalSettings, once);
