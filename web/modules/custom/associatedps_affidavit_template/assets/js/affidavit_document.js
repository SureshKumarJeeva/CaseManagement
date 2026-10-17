/**
 * @file
 * Affidavit download icon (Job Status → Admin Status, beside
 * "Affidavit Drafted").
 *
 * Click → /affidavit-document/{job}/status:
 *   - private://affidavits/[job number].docx exists → download it, nothing else;
 *   - it does not → the same template picker as Generate Affidavit (the job's
 *     own template pre-selected) → /word-template/generate-affidavit/{job},
 *     which merges, saves [job number].docx (replacing any earlier one) and
 *     answers with its download URL → download.
 *
 * ⚠ Deliberately a separate file from invoice_document.js, which is verified
 * on staging and left untouched (user decision 2026-10-15). The overlay and
 * picker below mirror that file's look.
 *
 * Downloads use a synthetic <a> click, not window.open(): these run in async
 * callbacks, no longer tied to the user's click, and popup blockers eat
 * window.open() there. The routes answer Content-Disposition: attachment, so
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

  function showOverlay(text) {
    if (!document.getElementById('aps-affidavit-doc-spin')) {
      var style = document.createElement('style');
      style.id = 'aps-affidavit-doc-spin';
      style.textContent = '@keyframes apsAffidavitDocSpin{to{transform:rotate(360deg)}}';
      document.head.appendChild(style);
    }
    hideOverlay();
    var $overlay = $('<div id="aps-affidavit-doc-overlay"></div>').css({
      position: 'fixed', top: 0, left: 0, width: '100%', height: '100%',
      backgroundColor: 'rgba(0, 0, 0, 0.6)', zIndex: 10001,
      display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center'
    });
    $overlay.append(
      $('<div></div>').css({
        width: '48px', height: '48px', border: '5px solid rgba(255, 255, 255, 0.3)',
        borderTopColor: '#fff', borderRadius: '50%',
        animation: 'apsAffidavitDocSpin 0.9s linear infinite', marginBottom: '18px'
      }),
      $('<div></div>').text(text).css({
        color: '#fff', fontSize: '16px', fontFamily: 'sans-serif',
        textAlign: 'center', maxWidth: '80vw', padding: '0 20px'
      })
    );
    $('body').append($overlay);
  }

  function hideOverlay() {
    $('#aps-affidavit-doc-overlay').remove();
  }

  function generate(jobId, libraryNid) {
    showOverlay(Drupal.t('Merging this job’s details into the template… This can take a few moments.'));
    $.ajax({
      url: '/word-template/generate-affidavit/' + encodeURIComponent(jobId) + '?library_nid=' + encodeURIComponent(libraryNid),
      type: 'GET',
      dataType: 'json',
      success: function (resp) {
        hideOverlay();
        if (resp.error) {
          message(resp.error);
          return;
        }
        download(resp.downloadUrl, resp.fileName);
        message(Drupal.t('Affidavit @file generated from @template.', {
          '@file': resp.fileName,
          '@template': resp.libraryTitle
        }), 'status');
      },
      error: function (xhr) {
        hideOverlay();
        message(errorFrom(xhr, Drupal.t('The affidavit could not be generated. Please try again.')));
      }
    });
  }

  /**
   * Template picker — same as Generate Affidavit: always shown, with the
   * job's own template pre-selected so confirming is one click.
   */
  function pickTemplate(jobId, templates, defaultNid) {
    $('#aps-affidavit-tpl-overlay').remove();
    var selected = null;

    var $overlay = $('<div id="aps-affidavit-tpl-overlay"></div>').css({
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
        $('<h3></h3>').text(Drupal.t('Select a template')).css({ margin: '0 0 8px 0', fontSize: '18px' }),
        $('<p></p>').text(Drupal.t('Choose the affidavit template to merge this job’s details into, then press Generate.')).css({ margin: 0, color: '#666', fontSize: '14px' })
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
      if (tpl.nid === defaultNid) {
        $item.append($('<span></span>').text(Drupal.t('This job’s template')).css({
          marginLeft: '8px', padding: '1px 6px', borderRadius: '3px',
          backgroundColor: '#6e7500', color: '#fff', fontSize: '11px', verticalAlign: 'middle'
        }));
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
      var $default = $list.find('li[data-nid="' + defaultNid + '"]');
      if ($default.length) {
        $default.trigger('click');
        $default.get(0).scrollIntoView({ block: 'nearest' });
      }
    }

    $cancel.on('click', function () { $overlay.remove(); });
    $overlay.on('click', function (e) {
      if (e.target === $overlay[0]) { $overlay.remove(); }
    });
    $go.on('click', function () {
      if (!selected) { return; }
      $overlay.remove();
      generate(jobId, selected);
    });
  }

  Drupal.behaviors.apsAffidavitDocument = {
    attach: function (context) {
      once('aps-affidavit-doc', '.aps-affidavit-doc-btn', context).forEach(function (btn) {
        $(btn).on('click', function (e) {
          e.preventDefault();
          var jobId = $(this).attr('data-job-id');

          $.ajax({
            url: '/affidavit-document/' + encodeURIComponent(jobId) + '/status',
            type: 'GET',
            dataType: 'json',
            success: function (status) {
              if (status.error) {
                message(status.error);
                return;
              }
              // Already generated → just download it.
              if (status.exists) {
                download(status.downloadUrl, status.fileName || '');
                return;
              }
              $.ajax({
                url: '/word-template/templates/Affidavit/' + encodeURIComponent(jobId),
                type: 'GET',
                dataType: 'json',
                success: function (resp) {
                  var templates = resp.templates || [];
                  if (!templates.length) {
                    message(Drupal.t('No affidavit templates are available yet. Create one on the Template page — upload a Word document and edit it in Word.'));
                    return;
                  }
                  pickTemplate(jobId, templates, resp.defaultNid);
                },
                error: function (xhr) {
                  message(errorFrom(xhr, Drupal.t('Failed to load the affidavit templates. Please try again.')));
                }
              });
            },
            error: function (xhr) {
              message(errorFrom(xhr, Drupal.t('The affidavit could not be checked. Please try again.')));
            }
          });
        });
      });
    }
  };

})(jQuery, Drupal, once);
