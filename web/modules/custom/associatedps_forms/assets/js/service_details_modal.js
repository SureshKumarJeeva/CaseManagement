/**
 * @file
 * Service Details popup for the Job form.
 *
 * The Service Details fields (Served By, Address Served, Method of Service,
 * Person Delivered To, etc.) live in the #service-details fieldset, which is
 * part of the Job form. They are only relevant once the Case Status is set to
 * "Completed – Served".
 *
 * Rather than expanding the fieldset inline, this behaviour relocates that
 * same fieldset into a modal dialog. The dialog is appended to the Job form
 * (appendTo) so its inputs remain inside the <form> and still submit normally
 * on Save / Save & Next — there is no separate save button in the popup.
 *
 * UX:
 * - Selecting "Completed – Served" in Case Status opens the popup so the user
 *   can fill in the service details.
 * - For an existing already-served job, the popup is NOT auto-opened on load;
 *   instead a compact read-only summary is shown on the Job Status tab with an
 *   "Edit Service Details" button that opens the popup with values prefilled.
 * - The popup's "Done" button just closes the dialog; values are persisted
 *   with the normal Job form submission.
 *
 * Because the dialog reuses the live form fields, all related field-sync
 * behaviours (served-by autofill, party-to-serve sync, the address paragraph,
 * etc.) keep working unchanged.
 */
(function ($, Drupal, once) {
  'use strict';

  // Case Status value (note the en-dash, U+2013) that reveals the section.
  var SERVED = 'Completed – Served';

  /**
   * Text of the selected option, treating "- None -" as empty.
   */
  function optText(sel) {
    var $s = $(sel);
    if (!$s.length) {
      return '';
    }
    var t = $s.find('option:selected').text();
    return (t && t !== '- None -') ? t : '';
  }

  /**
   * Display value for a control, whether it is a select, input or textarea.
   */
  function displayVal(sel) {
    var $e = $(sel);
    if (!$e.length) {
      return '';
    }
    return $e.is('select') ? optText(sel) : ($e.val() || '').toString();
  }

  /**
   * Business Name uses a select-or-other widget; resolve the effective value.
   */
  function businessName() {
    if ($('#edit-field-business-name-select').length) {
      var sel = optText('#edit-field-business-name-select');
      if (sel === 'Other' || sel === '- Other -') {
        return $('#edit-field-business-name-other').val() || '';
      }
      return sel;
    }
    return displayVal('#edit-field-business-name');
  }

  /**
   * Compose the Address Served from the paragraph address subform inputs.
   */
  function addressServed() {
    // The "address" paragraph bundle stores the address columns in field_address.
    var base = 'field_para_address[0][subform][field_address][0][address]';
    var parts = ['address_line1', 'locality', 'administrative_area', 'postal_code'].map(function (p) {
      return ($('[name="' + base + '[' + p + ']"]').val() || '').trim();
    });
    return parts.filter(Boolean).join(', ');
  }

  // Fields shown in the read-only summary, in display order.
  var summaryRows = [
    { label: 'Served By', get: function () { return optText('#edit-field-served-by'); } },
    { label: 'Address Served', get: addressServed },
    { label: 'Method of Service', get: function () { return displayVal('#edit-field-method-of-service'); } },
    { label: 'Person Delivered To', get: function () { return optText('#edit-field-person-delivered-to'); } },
    { label: 'Position', get: function () { return displayVal('#edit-field-service-details-position-0-value'); } },
    { label: 'Business Name', get: businessName },
    { label: 'Date of Service', get: function () { return displayVal('#edit-field-date-service-0-value'); } },
    { label: 'Notes', get: function () { return displayVal('#edit-field-notes2-0-value'); } }
  ];

  Drupal.behaviors.associatedpsServiceDetailsModal = {
    attach: function (context) {
      once('service-details-modal', '#edit-field-case-status', context).forEach(function (statusEl) {
        var $status = $(statusEl);
        var $section = $('#service-details');
        if (!$section.length) {
          return;
        }

        var $form = $section.closest('form');
        var serviceDialog = null;

        // Read-only summary block, placed where the section sat on the tab.
        var $summary = $('<div id="service-details-summary" class="service-details-summary"></div>');
        $summary.insertBefore($section);

        function renderSummary() {
          var rows = '';
          summaryRows.forEach(function (row) {
            var val = (row.get() || '').toString().trim();
            if (val) {
              rows += '<dt>' + Drupal.checkPlain(row.label) + '</dt>'
                + '<dd>' + Drupal.checkPlain(val) + '</dd>';
            }
          });

          var html = '<div class="service-details-summary__title">Service Details</div>';
          html += rows
            ? '<dl class="service-details-summary__list">' + rows + '</dl>'
            : '<p class="service-details-summary__empty">No service details entered yet.</p>';
          html += '<a href="#" class="button service-details-edit">Edit Service Details</a>';
          $summary.html(html);
        }

        // Always close through Drupal's dialog API. showModal() applies a body
        // scroll lock that is ONLY released by Drupal's dialog.close() — jQuery
        // UI's native close (the title X, Esc) does not clear it, which would
        // leave the page unscrollable after the dialog closes.
        function closeDialog() {
          if (serviceDialog) {
            serviceDialog.close();
          }
        }

        function openDialog() {
          $section.show();
          if (!serviceDialog) {
            serviceDialog = Drupal.dialog($section.get(0), {
              title: 'Service Details',
              width: 900,
              dialogClass: 'service-details-dialog',
              // Esc is disabled so closing can't bypass Drupal's scroll-lock
              // cleanup; the title X is re-routed below.
              closeOnEscape: false,
              // Don't auto-reposition the dialog on window scroll/focus — that
              // re-centring is what makes the dialog jump when a field near the
              // bottom is focused. Position is pinned via CSS (position: fixed).
              autoResize: false,
              draggable: false,
              resizable: false,
              // Keep the dialog (and its inputs) inside the Job form so the
              // fields still submit on Save / Save & Next.
              appendTo: $form,
              buttons: [{
                text: 'Done',
                class: 'button button--primary',
                click: closeDialog
              }],
              close: function () {
                renderSummary();
              }
            });
          }
          serviceDialog.showModal();
          // Guarantee the styling hook is on the widget (dialogClass is
          // deprecated in jQuery UI 1.13 and may not apply reliably).
          var $uiDialog = $section.closest('.ui-dialog').addClass('service-details-dialog');
          // Re-route the title-bar X through Drupal's close so the body scroll
          // lock is released. Re-applied on each open in case jQuery UI rebuilds
          // the titlebar button.
          $uiDialog.find('.ui-dialog-titlebar-close')
            .off('click')
            .on('click', function (e) {
              e.preventDefault();
              closeDialog();
            });
          // Hide the primary-address toggle inside the address subform.
          $('#edit-field-para-address-0-subform-field-primary-address-wrapper').hide();
        }

        // Hide the inline fieldset; it is only ever shown inside the dialog.
        $section.hide();

        if ($status.val() === SERVED) {
          // Already-served job: show the summary, do NOT auto-open the popup.
          renderSummary();
          $summary.show();
        }
        else {
          $summary.hide();
        }

        // "Edit Service Details" opens the popup with values prefilled.
        $summary.on('click', '.service-details-edit', function (e) {
          e.preventDefault();
          openDialog();
        });

        // Case Status change: a fresh "Completed – Served" selection opens the
        // popup; switching away hides the summary and closes the dialog.
        $status.on('change', function () {
          if (this.value === SERVED) {
            $summary.show();
            openDialog();
          }
          else {
            $summary.hide();
            if (serviceDialog) {
              serviceDialog.close();
            }
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
