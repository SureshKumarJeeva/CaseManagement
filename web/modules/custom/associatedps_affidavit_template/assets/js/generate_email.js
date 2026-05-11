(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.generateEmail = {
    attach: function (context) {
      $('.generate-email-btn', context).once('generate-email').each(function () {
        var $btn = $(this);
        var jobId = $btn.data('job-id');

        $btn.on('click', function (e) {
          e.preventDefault();
          showEmailTemplateModal(jobId);
        });
      });
    }
  };

  function showEmailTemplateModal(jobId) {
    // Remove any existing modal.
    $('#email-template-modal-overlay').remove();

    var $overlay = $('<div id="email-template-modal-overlay"></div>').css({
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

    var $modal = $('<div id="email-template-modal"></div>').css({
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
      $('<h3></h3>').text('Select an Email Template').css({ margin: '0 0 8px 0', fontSize: '18px' }),
      $('<p></p>').text('Choose an email template to generate the email with Job data.').css({ margin: 0, color: '#666', fontSize: '14px' })
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

    var $loading = $('<div></div>').text('Loading email templates...').css({ padding: '20px', textAlign: 'center', color: '#888' });
    $listContainer.append($loading);

    var $actions = $('<div></div>').css({ display: 'flex', justifyContent: 'flex-end', gap: '10px' });
    var $cancelBtn = $('<button type="button" class="button">Cancel</button>');
    var $generateBtn = $('<button type="button" class="button button--primary" disabled>Generate Email</button>');

    $actions.append($cancelBtn, $generateBtn);
    $modal.append($header, $listContainer, $actions);
    $overlay.append($modal);
    $('body').append($overlay);

    var selectedNid = null;

    // Close modal.
    $cancelBtn.on('click', function () { $overlay.remove(); });
    $overlay.on('click', function (e) {
      if (e.target === $overlay[0]) { $overlay.remove(); }
    });

    // Fetch email templates.
    $.ajax({
      url: '/pandadoc/email-templates',
      type: 'GET',
      dataType: 'json',
      success: function (response) {
        $loading.remove();

        if (!response.templates || response.templates.length === 0) {
          $listContainer.append(
            $('<div></div>').text('No email templates available. Please create a Library item with category "Email".').css({ padding: '20px', textAlign: 'center', color: '#888' })
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

          // Double-click to select and generate immediately.
          $item.on('dblclick', function () {
            selectedNid = tpl.nid;
            $generateBtn.trigger('click');
          });

          $list.append($item);
        });

        $listContainer.append($list);
      },
      error: function () {
        $loading.text('Failed to load email templates. Please try again.');
        $loading.css({ color: '#c00' });
      }
    });

    // Generate Email button — resolve template and open mailto.
    $generateBtn.on('click', function () {
      if (!selectedNid) { return; }

      $generateBtn.prop('disabled', true).text('Resolving...');

      $.ajax({
        url: '/pandadoc/resolve-email-template/' + selectedNid + '/' + jobId,
        type: 'GET',
        dataType: 'json',
        success: function (response) {
          $overlay.remove();

          if (response.error) {
            (new Drupal.Message()).add(response.error, { type: 'error' });
            return;
          }

          var mailto = 'mailto:' + encodeURIComponent(response.email || '');
          mailto += '?subject=' + encodeURIComponent(response.subject || '');
          if (response.body) {
            mailto += '&body=' + encodeURIComponent(response.body);
          }

          // Open in hidden iframe to avoid browser loading spinner.
          var $iframe = $('<iframe>', {
            src: mailto,
            style: 'display:none;'
          }).appendTo('body');
          setTimeout(function () { $iframe.remove(); }, 2000);
        },
        error: function (xhr) {
          $generateBtn.prop('disabled', false).text('Generate Email');
          var msg = 'Failed to resolve email template.';
          try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.error) { msg = resp.error; }
          }
          catch (e) {}
          (new Drupal.Message()).add(msg, { type: 'error' });
        }
      });
    });
  }

  // Polyfill for .once() if not available.
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

})(jQuery, Drupal);
