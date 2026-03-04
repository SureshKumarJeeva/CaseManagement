/**
 * @file
 * Populates the Standard Fee field when a Type of Service is selected.
 *
 * Works with IEF (Inline Entity Form) paragraph subforms that are
 * generated dynamically via AJAX. Drupal.behaviors.attach() re-runs
 * after every AJAX response, so once() ensures we bind exactly once
 * per autocomplete input — including those created after page load.
 *
 * The fee lookup uses drupalSettings (preloaded from PHP) so no
 * additional server round-trip is needed.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.associatedpsServiceFeeSync = {
    attach: function (context) {
      // Target every Type of Service autocomplete input.
      // IEF name pattern: field_job_fees[form][DELTA][field_type_of_service][0][target_id]
      $(once(
        'service-fee-sync',
        'input[name*="field_type_of_service"][name$="[target_id]"]',
        context
      )).on('autocompleteclose', function () {
        var val = $(this).val() || '';
        // Drupal entity_reference_autocomplete format: "Term Name (123)"
        var match = val.match(/\((\d+)\)$/);
        if (!match) {
          return;
        }

        var termId = match[1];
        var fees = (drupalSettings.associatedps_forms || {}).service_fees || {};
        var stdFee = fees[termId] || '';

        // Derive the sibling standard_fee input name from this input's name.
        // Replace [field_type_of_service][0][target_id]
        //    with [field_standard_fee][0][value]
        var stdFeeName = $(this).attr('name').replace(
          /\[field_type_of_service\]\[\d+\]\[target_id\]$/,
          '[field_standard_fee][0][value]'
        );
        var $stdFeeInput = $('input[name="' + stdFeeName + '"]');

        if ($stdFeeInput.length) {
          $stdFeeInput.val(stdFee);
        }
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
