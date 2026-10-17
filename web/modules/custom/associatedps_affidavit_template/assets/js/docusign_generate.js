(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Inject the spinner keyframes once (inline styles can't hold @keyframes).
   */
  function ensureLoaderStyles() {
    if (document.getElementById('docusign-loader-styles')) {
      return;
    }
    var style = document.createElement('style');
    style.id = 'docusign-loader-styles';
    style.textContent = '@keyframes docusignSpin{to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
  }

  /**
   * Show (or update) a full-screen blocking overlay with a spinner + message.
   * Makes the generate wait obvious even after the Generate dropdown closes.
   */
  function showLoadingOverlay(message) {
    ensureLoaderStyles();

    var $overlay = $('#docusign-loading-overlay');
    if ($overlay.length) {
      $overlay.find('.docusign-loading-message').text(message);
      return $overlay;
    }

    $overlay = $('<div id="docusign-loading-overlay"></div>').css({
      position: 'fixed',
      top: 0,
      left: 0,
      width: '100%',
      height: '100%',
      backgroundColor: 'rgba(0, 0, 0, 0.6)',
      zIndex: 10001,
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center'
    });

    var $spinner = $('<div></div>').css({
      width: '48px',
      height: '48px',
      border: '5px solid rgba(255, 255, 255, 0.3)',
      borderTopColor: '#fff',
      borderRadius: '50%',
      animation: 'docusignSpin 0.9s linear infinite',
      marginBottom: '18px'
    });

    var $msg = $('<div class="docusign-loading-message"></div>').text(message || 'Generating…').css({
      color: '#fff',
      fontSize: '16px',
      fontFamily: 'sans-serif',
      textAlign: 'center',
      maxWidth: '80vw',
      padding: '0 20px'
    });

    $overlay.append($spinner, $msg);
    $('body').append($overlay);
    return $overlay;
  }

  function updateLoadingOverlay(message) {
    $('#docusign-loading-overlay .docusign-loading-message').text(message);
  }

  function hideLoadingOverlay() {
    $('#docusign-loading-overlay').remove();
  }

  /**
   * Trigger the generate workflow via AJAX for a given URL.
   */
  function triggerGenerate($btn, generateUrl) {
    var originalText = $btn.text();
    $btn.text('Generating...').addClass('is-disabled').css('pointer-events', 'none');
    var $spinner = $('<span class="ajax-progress ajax-progress--throbber"><span class="throbber">&nbsp;</span></span>');
    $btn.append($spinner);

    // Full-screen overlay so the wait is obvious even after the Generate
    // dropdown closes — merging a long template takes a few seconds. Removed on
    // error; kept until the download starts.
    showLoadingOverlay('Merging this job’s details into the template… This can take a few moments.');

    function resetButton() {
      $btn.text(originalText).removeClass('is-disabled').css('pointer-events', '');
      $spinner.remove();
      hideLoadingOverlay();
    }

    $.ajax({
      url: generateUrl,
      type: 'GET',
      dataType: 'json',
      success: function (response) {
        if (response.no_template) {
          resetButton();
          showTemplateSelectionModal($btn, response.category, response.job_id, generateUrl);
          return;
        }

        if (response.error) {
          (new Drupal.Message()).add(response.error, { type: 'error' });
          resetButton();
          return;
        }

        // Merged in-house from a .docx template by our own Python service.
        // There is no envelope and nothing to poll — the document already
        // exists by the time this response arrives.
        if (response.method === 'word') {
          updateLoadingOverlay('Document ready. Starting the download…');
          // ⚠ NOT window.open(): we are inside an async callback, so the call
          // is no longer tied to the user's click and popup blockers eat it.
          // The route replies with Content-Disposition: attachment, so a
          // synthetic anchor click downloads the file and leaves the Job page
          // exactly where it is.
          var link = document.createElement('a');
          link.href = response.downloadUrl;
          link.download = response.fileName || '';
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);

          (new Drupal.Message()).add(
            Drupal.t('Affidavit generated from @template.', { '@template': response.libraryTitle }),
            { type: 'status' }
          );
          resetButton();
          return;
        }

        if (response.envelope_id && response.status_url) {
          // Document generation reports how much of the job it could fill in;
          // the tab flow has no equivalent figure, so the message differs.
          if (response.method === 'docgen') {
            updateLoadingOverlay('Document generated from ' + response.prefilled + ' job field(s). Opening DocuSign for review…');
          }
          else {
            updateLoadingOverlay('Envelope created. Preparing the DocuSign editor…');
          }
          pollStatus($btn, $spinner, originalText, response.status_url, response.editor_url || response.download_url, 0);
        }
        else {
          // The endpoint now fronts three generators (in-house Word merge,
          // DocuSign tabs, DocuSign doc-gen), so this no longer means DocuSign
          // specifically.
          (new Drupal.Message()).add('Unexpected response from the document generator.', { type: 'error' });
          resetButton();
        }
      },
      error: function (xhr) {
        // Default only. The server's own message — a retired template, no
        // template assigned, the merge service being down — is parsed below and
        // is what the user should normally see.
        var msg = 'The document could not be generated. Please try again.';
        try {
          var resp = JSON.parse(xhr.responseText);
          if (resp.error) {
            msg = resp.error;
          }
        }
        catch (e) {}
        (new Drupal.Message()).add(msg, { type: 'error' });
        resetButton();
      }
    });
  }

  function pollStatus($btn, $spinner, originalText, statusUrl, editorUrl, attempt) {
    console.log('Polling DocuSign envelope status... Attempt #' + attempt);
    var maxAttempts = 30;
    var pollInterval = 2000;

    function resetButton() {
      $btn.text(originalText).removeClass('is-disabled').css('pointer-events', '');
      $spinner.remove();
      hideLoadingOverlay();
    }

    if (attempt >= maxAttempts) {
      (new Drupal.Message()).add('Envelope generation timed out. Please check the DocuSign dashboard.', { type: 'warning' });
      resetButton();
      return;
    }

    setTimeout(function () {
      $.ajax({
        url: statusUrl,
        type: 'GET',
        dataType: 'json',
        success: function (statusResponse) {
          if (statusResponse.error) {
            (new Drupal.Message()).add('Status check failed: ' + statusResponse.error, { type: 'error' });
            resetButton();
            return;
          }

          if (statusResponse.download_ready) {
            // Keep the overlay up through the redirect so the page never
            // flashes back to the Job form before the editor loads.
            updateLoadingOverlay('Editor ready. Opening…');
            (new Drupal.Message()).add('Envelope created. Opening editor...', { type: 'status' });
            window.location.href = editorUrl;
          }
          else {
            $btn.text('Generating... (' + statusResponse.status + ')');
            $btn.append($spinner);
            updateLoadingOverlay('Generating… (' + statusResponse.status + ')');
            pollStatus($btn, $spinner, originalText, statusUrl, editorUrl, attempt + 1);
          }
        },
        error: function () {
          pollStatus($btn, $spinner, originalText, statusUrl, editorUrl, attempt + 1);
        }
      });
    }, pollInterval);
  }

  /**
   * Pick the template to merge this job into, then generate.
   *
   * Opened by every Generate click. The job's own template arrives as
   * `defaultNid` and is pre-selected and scrolled into view, so pressing
   * Generate straight away does what the button used to do on its own; picking a
   * different row overrides it for this run only and changes nothing on the job.
   */
  function showTemplateSelectionModal($btn, category, jobId, generateUrl) {
    $('#docusign-template-modal-overlay').remove();

    var $overlay = $('<div id="docusign-template-modal-overlay"></div>').css({
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

    var $modal = $('<div id="docusign-template-modal"></div>').css({
      backgroundColor: '#fff',
      borderRadius: '8px',
      padding: '24px',
      width: '500px',
      maxWidth: '90vw',
      maxHeight: '80vh',
      display: 'flex',
      flexDirection: 'column',
      boxShadow: '0 4px 20px rgba(0, 0, 0, 0.3)'
    });

    var $header = $('<div></div>').css({ marginBottom: '16px' });
    $header.append(
      $('<h3></h3>').text('Select a template').css({ margin: '0 0 8px 0', fontSize: '18px' }),
      $('<p></p>').text('Choose the ' + category.toLowerCase() + ' template to merge this job’s details into, then press Generate.').css({ margin: 0, color: '#666', fontSize: '14px' })
    );

    var $listContainer = $('<div></div>').css({
      flex: '1',
      overflowY: 'auto',
      border: '1px solid #ccc',
      borderRadius: '4px',
      marginBottom: '16px',
      minHeight: '150px',
      maxHeight: '400px'
    });

    var $loading = $('<div></div>').text('Loading templates...').css({ padding: '20px', textAlign: 'center', color: '#888' });
    $listContainer.append($loading);

    var $actions = $('<div></div>').css({ display: 'flex', justifyContent: 'flex-end', gap: '10px' });
    var $cancelBtn = $('<button type="button" class="button">Cancel</button>');
    var $generateBtn = $('<button type="button" class="button button--primary" disabled>Generate</button>');

    $actions.append($cancelBtn, $generateBtn);
    $modal.append($header, $listContainer, $actions);
    $overlay.append($modal);
    $('body').append($overlay);

    var selectedNid = null;

    $cancelBtn.on('click', function () { $overlay.remove(); });
    $overlay.on('click', function (e) {
      if (e.target === $overlay[0]) { $overlay.remove(); }
    });

    $.ajax({
      url: '/word-template/templates/' + encodeURIComponent(category) + '/' + encodeURIComponent(jobId),
      type: 'GET',
      dataType: 'json',
      success: function (response) {
        $loading.remove();

        if (!response.templates || response.templates.length === 0) {
          $listContainer.append(
            $('<div></div>').text('No ' + category.toLowerCase() + ' templates are available yet. Create one on the Template page first — upload a Word document and edit it in Word.').css({ padding: '20px', textAlign: 'center', color: '#888' })
          );
          return;
        }

        var $list = $('<ul></ul>').css({ listStyle: 'none', margin: 0, padding: 0 });

        $.each(response.templates, function (i, tpl) {
          var $item = $('<li></li>').css({
            padding: '12px 16px',
            borderBottom: '1px solid #eee',
            cursor: 'pointer',
            transition: 'background-color 0.15s'
          }).attr('data-nid', tpl.nid).text(tpl.title);

          // The document under the title: two templates can carry the same name,
          // and the file is what the author recognises.
          if (tpl.fileName) {
            $item.append(
              $('<div></div>').text(tpl.fileName).css({
                fontSize: '12px',
                color: '#777',
                marginTop: '2px'
              })
            );
          }

          if (tpl.nid === response.defaultNid) {
            $item.append(
              $('<span></span>').text('This job’s template').css({
                marginLeft: '8px',
                padding: '1px 6px',
                borderRadius: '3px',
                backgroundColor: '#6e7500',
                color: '#fff',
                fontSize: '11px',
                verticalAlign: 'middle'
              })
            );
          }

          $item.on('mouseenter', function () {
            if (selectedNid !== tpl.nid) {
              $(this).css({ backgroundColor: '#f5f5f5' });
            }
          }).on('mouseleave', function () {
            if (selectedNid !== tpl.nid) {
              $(this).css({ backgroundColor: '' });
            }
          });

          $item.on('click', function () {
            $list.find('li').css({ backgroundColor: '', fontWeight: '' });
            $(this).css({ backgroundColor: '#e3f2fd', fontWeight: 'bold' });
            selectedNid = tpl.nid;
            $generateBtn.prop('disabled', false);
          });

          $item.on('dblclick', function () {
            selectedNid = tpl.nid;
            $generateBtn.trigger('click');
          });

          $list.append($item);
        });

        $listContainer.append($list);

        // Pre-select the job's own template so confirming is a single click.
        // Triggering the row's own handler keeps the highlight, the selection and
        // the Generate button in one place rather than repeating that styling.
        if (response.defaultNid) {
          var $default = $list.find('li[data-nid="' + response.defaultNid + '"]');
          if ($default.length) {
            $default.trigger('click');
            $default.get(0).scrollIntoView({ block: 'nearest' });
          }
        }
      },
      error: function () {
        $loading.text('Failed to load templates. Please try again.');
        $loading.css({ color: '#c00' });
      }
    });

    $generateBtn.on('click', function () {
      if (!selectedNid) { return; }

      $overlay.remove();

      var separator = generateUrl.indexOf('?') !== -1 ? '&' : '?';
      var urlWithTemplate = generateUrl + separator + 'library_nid=' + selectedNid;

      triggerGenerate($btn, urlWithTemplate);
    });
  }

  Drupal.behaviors.docusignGenerate = {
    attach: function (context) {
      $('.docusign-generate-btn', context).once('docusign-generate').each(function () {
        var $btn = $(this);

        $btn.on('click', function (e) {
          e.preventDefault();

          var generateUrl = $btn.attr('href');
          if (!generateUrl) {
            return;
          }

          // Always ask which template, even when the job already has one — the
          // job's own is pre-selected, so confirming is one click, and any other
          // template can be chosen without editing the job first.
          //
          // Job id and category come from the link's own href
          // (/word-template/generate-affidavit/537) rather than from a server
          // round trip, so the picker can open immediately.
          var parts = generateUrl.match(/generate-(affidavit|correspondence)\/(\d+)/i);
          if (!parts) {
            // Not a URL we recognise — generate directly rather than block.
            triggerGenerate($btn, generateUrl);
            return;
          }

          var category = parts[1].toLowerCase() === 'correspondence'
            ? 'Correspondence'
            : 'Affidavit';

          showTemplateSelectionModal($btn, category, parts[2], generateUrl);
        });
      });
    }
  };

  // Polyfill for .once() if not available (Drupal 10 uses once() from core/once).
  if (!$.fn.once) {
    $.fn.once = function (id) {
      return this.filter(function () {
        var processed = $(this).data('once-' + id);
        if (!processed) {
          $(this).data('once-' + id, true);
          return true;
        }
        return false;
      });
    };
  }

})(jQuery, Drupal, drupalSettings);
