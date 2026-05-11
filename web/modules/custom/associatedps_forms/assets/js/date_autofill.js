/**
 * @file
 * Auto-fill admin date fields with today's date and time when checkbox is ticked.
 *
 * On edit pages, date fields may already have a value from the DB.
 * - Checking the box sets the field to today's date and current time.
 * - Unchecking restores the original DB value (or clears it on add pages).
 * - The user can always manually pick a different date/time regardless of the
 *   checkbox state.
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.associatedpsDateAutofill = {
    attach: function (context) {
      $(once('date-autofill', '.date-autofill-checkbox', context)).each(function () {
        var $checkbox = $(this);
        var fieldName = $checkbox.data('date-field');

        // Locate the date and time inputs for this field.
        var selector = 'edit-' + fieldName.replace(/_/g, '-');
        var $wrapper = $checkbox.closest('.form-wrapper, .fieldset-wrapper, .field-group-fieldset')
          .find('[data-drupal-selector*="' + selector + '"]');
        var $dateInput = $wrapper.find('input[type="date"]');
        var $timeInput = $wrapper.find('input[type="time"]');

        // Store the original DB values so we can restore them on uncheck.
        $dateInput.each(function () {
          $(this).data('original-value', $(this).val());
        });
        $timeInput.each(function () {
          $(this).data('original-value', $(this).val());
        });

        $checkbox.on('change', function () {
          if ($checkbox.is(':checked')) {
            var now = new Date();
            var year = now.getFullYear();
            var month = String(now.getMonth() + 1).padStart(2, '0');
            var day = String(now.getDate()).padStart(2, '0');
            var hours = String(now.getHours()).padStart(2, '0');
            var minutes = String(now.getMinutes()).padStart(2, '0');

            $dateInput.val(year + '-' + month + '-' + day).trigger('change');
            $timeInput.val(hours + ':' + minutes).trigger('change');
          }
          else {
            // Restore the original values from the DB (empty on add pages).
            $dateInput.each(function () {
              $(this).val($(this).data('original-value') || '').trigger('change');
            });
            $timeInput.each(function () {
              $(this).val($(this).data('original-value') || '').trigger('change');
            });
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
