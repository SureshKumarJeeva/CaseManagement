/**
 * @file
 * Show/hide + auto-fill admin date fields via a "set to today" checkbox.
 *
 * - The date field is hidden by default (CSS hides `.form-datetime-wrapper`
 *   until the field wrapper gets the `date-active` class).
 * - Ticking the checkbox reveals the date field and, if it has no value yet,
 *   defaults it to today's date. The user can then change it or leave it as-is.
 * - Unticking hides the date field again and clears the value.
 * - Edit page with an existing value = auto-revealed + ticked, so saved dates
 *   aren't hidden on load.
 *
 * Date-only fields: no time input is handled.
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.associatedpsDateAutofill = {
    attach: function (context) {
      $(once('date-autofill', '.date-autofill-checkbox', context)).each(function () {
        var $checkbox = $(this);
        var fieldName = $checkbox.data('date-field');

        // The field wrapper carries the `date-active` class that reveals the box.
        var $fieldWrapper = $checkbox.closest('.field--widget-datetime-default');

        // Locate the date input directly by its Drupal selector id — robust
        // regardless of the surrounding wrapper structure.
        var base = 'edit-' + fieldName.replace(/_/g, '-');
        var $dateInput = $('input[data-drupal-selector="' + base + '-0-value-date"]');

        function setToday() {
          var now = new Date();
          var year = now.getFullYear();
          var month = String(now.getMonth() + 1).padStart(2, '0');
          var day = String(now.getDate()).padStart(2, '0');
          $dateInput.val(year + '-' + month + '-' + day).trigger('change');
        }

        // Edit page with an existing value = auto-revealed + ticked, so saved
        // dates aren't hidden on load.
        if ($dateInput.val()) {
          $checkbox.prop('checked', true);
          $fieldWrapper.addClass('date-active');
        }

        $checkbox.on('change', function () {
          if ($checkbox.is(':checked')) {
            $fieldWrapper.addClass('date-active');
            // Ticking always defaults to today (re-ticking resets a stale date).
            setToday();
          }
          else {
            $fieldWrapper.removeClass('date-active');
            $dateInput.val('').trigger('change');
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
