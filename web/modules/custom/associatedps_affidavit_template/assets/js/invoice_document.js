/**
 * @file
 * Invoice document download icon (Job Status → Admin Status, beside
 * "Invoice Drafted").
 *
 * Click → /invoice-document/{job}/status:
 *   - the [invoice_number].docx already exists → download it, nothing else;
 *   - it does not → pick an Invoice template (skipped when there is only one)
 *     → /generate merges it from Xero's CURRENT invoice → download.
 *
 * Downloads use a synthetic <a> click, not window.open(): these run in async
 * callbacks, no longer tied to the user's click, and popup blockers eat
 * window.open() there. The route answers Content-Disposition: attachment, so
 * the Job page stays exactly where it is.
 */
(function ($, Drupal, once) {
  'use strict';

  function message(text, type) {
    (new Drupal.Message()).add(text, { type: type || 'error' });
  }

  function errorFrom(xhr, fallback) {
    try {
      var resp = JSON.parse(xhr.responseText);
      if (resp.error) {
        return resp.error;
      }
    }
    catch (e) {}
    return fallback;
  }

  function download(url, fileName) {
    var link = document.createElement('a');
    link.href = url;
    link.download = fileName || '';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  /**
   * Full-screen overlay for the merge wait (Xero read + merge takes seconds).
   */
  function showOverlay(text) {
    if (!document.getElementById('aps-invoice-doc-spin')) {
      var style = document.createElement('style');
      style.id = 'aps-invoice-doc-spin';
      style.textContent = '@keyframes apsInvoiceDocSpin{to{transform:rotate(360deg)}}';
      document.head.appendChild(style);
    }
    hideOverlay();
    var $overlay = $('<div id="aps-invoice-doc-overlay"></div>').css({
      position: 'fixed', top: 0, left: 0, width: '100%', height: '100%',
      backgroundColor: 'rgba(0, 0, 0, 0.6)', zIndex: 10001,
      display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center'
    });
    $overlay.append(
      $('<div></div>').css({
        width: '48px', height: '48px', border: '5px solid rgba(255, 255, 255, 0.3)',
        borderTopColor: '#fff', borderRadius: '50%',
        animation: 'apsInvoiceDocSpin 0.9s linear infinite', marginBottom: '18px'
      }),
      $('<div></div>').text(text).css({
        color: '#fff', fontSize: '16px', fontFamily: 'sans-serif',
        textAlign: 'center', maxWidth: '80vw', padding: '0 20px'
      })
    );
    $('body').append($overlay);
  }

  function hideOverlay() {
    $('#aps-invoice-doc-overlay').remove();
  }

  function generate(base, libraryNid) {
    showOverlay(Drupal.t('Reading the invoice from Xero and merging it into the template… This can take a few moments.'));
    $.ajax({
      url: base + '/generate?library_nid=' + encodeURIComponent(libraryNid),
      type: 'GET',
      dataType: 'json',
      success: function (resp) {
        hideOverlay();
        if (resp.error) {
          message(resp.error);
          return;
        }
        download(resp.downloadUrl, resp.fileName);
        message(Drupal.t('Invoice document @file generated from @template.', {
          '@file': resp.fileName,
          '@template': resp.libraryTitle
        }), 'status');
      },
      error: function (xhr) {
        hideOverlay();
        message(errorFrom(xhr, Drupal.t('The invoice document could not be generated. Please try again.')));
      }
    });
  }

  /**
   * Template picker — same look as the Generate Affidavit picker, with the
   * most recently updated Invoice template pre-selected.
   */
  function pickTemplate(base, templates, defaultNid) {
    $('#aps-invoice-tpl-overlay').remove();
    var selected = null;

    var $overlay = $('<div id="aps-invoice-tpl-overlay"></div>').css({
      position: 'fixed', top: 0, left: 0, width: '100%', height: '100%',
      backgroundColor: 'rgba(0, 0, 0, 0.6)', zIndex: 10000,
      display: 'flex', alignItems: 'center', justifyContent: 'center'
    });
    var $modal = $('<div></div>').css({
      backgroundColor: '#fff', borderRadius: '8px', padding: '24px',
      width: '500px', maxWidth: '90vw', maxHeight: '80vh',
      display: 'flex', flexDirection: 'column', boxShadow: '0 4px 20px rgba(0, 0, 0, 0.3)'
    });
    var $list = $('<ul></ul>').css({ listStyle: 'none', margin: 0, padding: 0 });
    var $listBox = $('<div></div>').css({
      flex: '1', overflowY: 'auto', border: '1px solid #ccc', borderRadius: '4px',
      marginBottom: '16px', minHeight: '150px', maxHeight: '400px'
    }).append($list);
    var $cancel = $('<button type="button" class="button"></button>').text(Drupal.t('Cancel'));
    var $go = $('<button type="button" class="button button--primary" disabled></button>').text(Drupal.t('Generate'));

    $modal.append(
      $('<div></div>').css({ marginBottom: '16px' }).append(
        $('<h3></h3>').text(Drupal.t('Select an invoice template')).css({ margin: '0 0 8px 0', fontSize: '18px' }),
        $('<p></p>').text(Drupal.t('Choose the template to merge this invoice into, then press Generate.')).css({ margin: 0, color: '#666', fontSize: '14px' })
      ),
      $listBox,
      $('<div></div>').css({ display: 'flex', justifyContent: 'flex-end', gap: '10px' }).append($cancel, $go)
    );
    $overlay.append($modal);
    $('body').append($overlay);

    $.each(templates, function (i, tpl) {
      var $item = $('<li></li>').attr('data-nid', tpl.nid).text(tpl.title).css({
        padding: '12px 16px', borderBottom: '1px solid #eee', cursor: 'pointer'
      });
      if (tpl.fileName) {
        $item.append($('<div></div>').text(tpl.fileName).css({ fontSize: '12px', color: '#777', marginTop: '2px' }));
      }
      $item.on('click', function () {
        $list.find('li').css({ backgroundColor: '', fontWeight: '' });
        $(this).css({ backgroundColor: '#e3f2fd', fontWeight: 'bold' });
        selected = tpl.nid;
        $go.prop('disabled', false);
      }).on('dblclick', function () {
        selected = tpl.nid;
        $go.trigger('click');
      });
      $list.append($item);
    });

    if (defaultNid) {
      $list.find('li[data-nid="' + defaultNid + '"]').trigger('click');
    }

    $cancel.on('click', function () { $overlay.remove(); });
    $overlay.on('click', function (e) {
      if (e.target === $overlay[0]) { $overlay.remove(); }
    });
    $go.on('click', function () {
      if (!selected) { return; }
      $overlay.remove();
      generate(base, selected);
    });
  }

  Drupal.behaviors.apsInvoiceDocument = {
    attach: function (context) {
      once('aps-invoice-doc', '.aps-invoice-doc-btn', context).forEach(function (btn) {
        $(btn).on('click', function (e) {
          e.preventDefault();
          var $btn = $(this);
          var saved = String($btn.data('invoice-number') || '');

          // The server only trusts the SAVED number. Generate Invoice writes a
          // new one into the open form; until Save it is not the Job's.
          var $field = $('[name="field_invoice_number[0][value]"]');
          var current = $field.length ? $.trim($field.val()) : saved;
          if (current !== saved) {
            message(Drupal.t('The invoice number has changed on this form. Save the job first, then download the invoice document.'), 'warning');
            return;
          }
          if (!saved) {
            message(Drupal.t('This job has no saved invoice number. Generate the invoice in Xero, then save the job, before downloading the invoice document.'), 'warning');
            return;
          }

          var base = '/invoice-document/' + encodeURIComponent($btn.data('job-id'));

          $.ajax({
            url: base + '/status',
            type: 'GET',
            dataType: 'json',
            success: function (status) {
              if (status.error) {
                message(status.error);
                return;
              }
              // Already generated → just download it.
              if (status.exists) {
                download(status.downloadUrl, status.invoiceNumber + '.docx');
                return;
              }
              $.ajax({
                url: base + '/templates',
                type: 'GET',
                dataType: 'json',
                success: function (resp) {
                  var templates = resp.templates || [];
                  if (!templates.length) {
                    message(Drupal.t('No Invoice template is available. Create one on the Template page (Category: Invoice) — upload a Word document and edit it in Word.'));
                    return;
                  }
                  if (templates.length === 1) {
                    generate(base, templates[0].nid);
                    return;
                  }
                  pickTemplate(base, templates, resp.defaultNid);
                },
                error: function (xhr) {
                  message(errorFrom(xhr, Drupal.t('Failed to load the invoice templates. Please try again.')));
                }
              });
            },
            error: function (xhr) {
              message(errorFrom(xhr, Drupal.t('The invoice document could not be checked. Please try again.')));
            }
          });
        });
      });
    }
  };

})(jQuery, Drupal, once);
