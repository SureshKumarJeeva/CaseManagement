(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Trigger the generate workflow via AJAX for a given URL.
   */
  function triggerGenerate($btn, generateUrl) {
    var originalText = $btn.text();
    $btn.text('Generating...').addClass('is-disabled').css('pointer-events', 'none');
    var $spinner = $('<span class="ajax-progress ajax-progress--throbber"><span class="throbber">&nbsp;</span></span>');
    $btn.append($spinner);

    function resetButton() {
      $btn.text(originalText).removeClass('is-disabled').css('pointer-events', '');
      $spinner.remove();
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

        if (response.envelope_id && response.status_url) {
          pollStatus($btn, $spinner, originalText, response.status_url, response.editor_url || response.download_url, 0);
        }
        else {
          (new Drupal.Message()).add('Unexpected response from DocuSign.', { type: 'error' });
          resetButton();
        }
      },
      error: function (xhr) {
        var msg = 'Envelope generation request failed.';
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
            (new Drupal.Message()).add('Envelope created. Opening editor...', { type: 'status' });
            resetButton();
            window.location.href = editorUrl;
          }
          else {
            $btn.text('Generating... (' + statusResponse.status + ')');
            $btn.append($spinner);
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
   * Show a modal popup with a scrollable list of available DocuSign templates.
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
      $('<h3></h3>').text('Select a DocuSign Template').css({ margin: '0 0 8px 0', fontSize: '18px' }),
      $('<p></p>').text('This job has no linked documents. Please select a ' + category + ' template to generate the envelope.').css({ margin: 0, color: '#666', fontSize: '14px' })
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
      url: '/docusign/templates-by-category/' + encodeURIComponent(category),
      type: 'GET',
      dataType: 'json',
      success: function (response) {
        $loading.remove();

        if (!response.templates || response.templates.length === 0) {
          $listContainer.append(
            $('<div></div>').text('No ' + category + ' templates available. Please create a template first.').css({ padding: '20px', textAlign: 'center', color: '#888' })
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

          triggerGenerate($btn, generateUrl);
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
