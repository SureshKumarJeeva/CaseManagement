/**
 * @file
 * Attendance Service Details popup for the Job form (Attendance Note tab).
 *
 * This is the mirror of service_details_modal.js. The Attendance Note tab has
 * its own Case Status (field_attendance_case_status) and its own copy of the
 * Service Details fields (field_attendance_served_by, field_attendance_address_
 * served, field_attendance_method_of_servi, field_atten_person_delivered_to,
 * field_atten_service_details_pos, field_atten_business_name,
 * field_attendance_notes2, field_attendance_date_service). Those fields live in
 * the "Attendance Service Details" field-group fieldset on the Attendance Note
 * tab. They are only relevant once the (attendance) Case Status is set to
 * "Completed – Served".
 *
 * The behaviour is identical to the Job Status popup: the same fieldset is
 * relocated into a modal dialog appended to the Job form so its inputs still
 * submit on Save / Save & Next. The popup reuses the SAME dialog class
 * (service-details-dialog) so the look matches the Job Status popup exactly.
 *
 * Note on the two tabs: the Job Status and Attendance Service Details field
 * sets are reconciled server-side on Save (associatedps_forms_node_presave) —
 * Attendance edits take precedence on a conflict. This file only drives the
 * Attendance popup UI; it does NOT live-sync to the Job Status tab.
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
   * Compose a datetime widget's value from its two inputs.
   *
   * A datetime field (datetime_default widget) renders as separate
   * <base>-date and <base>-time inputs — there is no single <base> input, which
   * is why reading the plain id returned blank. Pass the base id, e.g.
   * '#edit-field-attendance-date-service-0-value'.
   */
  function datetimeVal(base) {
    var d = ($(base + '-date').val() || '').trim();
    var t = ($(base + '-time').val() || '').trim();
    if (!d) {
      return '';
    }
    // Display as dd/mm/yyyy hh:mm (input values are ISO yyyy-mm-dd / hh:mm[:ss]).
    var ymd = d.split('-');
    var out = (ymd.length === 3) ? (ymd[2] + '/' + ymd[1] + '/' + ymd[0]) : d;
    return t ? (out + ' ' + t.slice(0, 5)) : out;
  }

  /**
   * Compose the Address Served from the paragraph address subform inputs.
   *
   * field_attendance_address_served references the "address" paragraph bundle,
   * whose address columns live in the field_address (address-type) subfield.
   */
  function addressServed() {
    var base = 'field_attendance_address_served[0][subform][field_address][0][address]';
    var parts = ['address_line1', 'locality', 'administrative_area', 'postal_code'].map(function (p) {
      return ($('[name="' + base + '[' + p + ']"]').val() || '').trim();
    });
    return parts.filter(Boolean).join(', ');
  }

  // Fields shown in the read-only summary, in display order — mirrors the Job
  // Status summary so the two tabs read identically.
  var summaryRows = [
    { label: 'Served By', get: function () { return optText('#edit-field-attendance-served-by'); } },
    { label: 'Address Served', get: addressServed },
    { label: 'Method of Service', get: function () { return displayVal('#edit-field-attendance-method-of-servi'); } },
    { label: 'Person Delivered To', get: function () { return optText('#edit-field-atten-person-delivered-to'); } },
    { label: 'Position', get: function () { return displayVal('#edit-field-atten-service-details-pos-0-value'); } },
    { label: 'Business Name', get: function () { return optText('#edit-field-atten-business-name'); } },
    { label: 'Date Served', get: function () { return datetimeVal('#edit-field-attendance-date-service-0-value'); } },
    { label: 'Notes', get: function () { return displayVal('#edit-field-attendance-notes2-0-value'); } }
  ];

  Drupal.behaviors.associatedpsAttendanceServiceDetailsModal = {
    attach: function (context) {
      once('attendance-service-details-modal', '#edit-field-attendance-case-status', context).forEach(function (statusEl) {
        var $status = $(statusEl);

        // The Attendance Service Details field-group fieldset has no fixed HTML
        // id (unlike #service-details). Anchor on a known plain child field and
        // walk up to its fieldset; "Position" is a top-level textfield in the
        // group (not nested in the address subform), so it is a safe anchor.
        var $section = $('#edit-field-atten-service-details-pos-0-value').closest('fieldset');
        if (!$section.length) {
          $section = $('#edit-field-attendance-served-by').closest('fieldset');
        }
        if (!$section.length) {
          return;
        }
        // Marker class so the dialog CSS (legend hide / reset) can target it the
        // same way it targets #service-details for the Job Status popup.
        $section.addClass('attendance-service-details-section');

        var $form = $section.closest('form');
        var serviceDialog = null;

        // Read-only summary block, placed where the section sat on the tab.
        var $summary = $('<div id="attendance-service-details-summary" class="service-details-summary"></div>');
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
