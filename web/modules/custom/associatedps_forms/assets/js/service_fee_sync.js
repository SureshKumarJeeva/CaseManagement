/**
 * @file
 * Populates the Standard Fee field when a Type of Service is selected
 * and auto-calculates the Total field.
 *
 * Total = (Agreed Fee || Standard Fee) x Units
 * If the client has an agreed discount (%), it is applied to the total.
 * The client discount is read from the Job form's field_agreed_discount
 * (id: change-client-discount), which is populated from the associated client.
 *
 * Works with IEF (Inline Entity Form) paragraph subforms generated
 * dynamically via AJAX. Uses DOM traversal to find sibling fields
 * within the same IEF form container.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Get the client discount percentage.
   * Reads from drupalSettings (set on form load and updated via AJAX
   * when the client changes).
   */
  function getClientDiscount() {
    var discount = parseFloat((drupalSettings.associatedps_forms || {}).client_discount);
    return isNaN(discount) ? 0 : discount;
  }

  /**
   * Find the IEF form container that holds all fee fields for one row.
   * Walks up the DOM from the given element to find the common ancestor.
   */
  function getFormContainer($el) {
    // Try IEF form wrapper first.
    var $container = $el.closest('.ief-form');
    if ($container.length) {
      return $container;
    }
    // Try inline entity form container.
    $container = $el.closest('.inline-entity-form');
    if ($container.length) {
      return $container;
    }
    // Try any form wrapper that contains field_type_of_service.
    $container = $el.closest('td').parent('tr');
    if ($container.length && $container.find('select[name*="field_type_of_service"]').length) {
      return $container;
    }
    // Fallback: walk up until we find a container with both type_of_service and field_total.
    $container = $el.closest('.field--name-field-job-fees');
    return $container;
  }

  /**
   * Find a sibling fee field input within the same container.
   */
  function findField($container, fieldName) {
    return $container.find('input[name*="' + fieldName + '"][name$="[value]"]').first();
  }

  /**
   * Calculate and set the Total for a given IEF row.
   */
  function calculateTotal($container) {
    var $stdFee = findField($container, 'field_standard_fee');
    var $agreedFee = findField($container, 'field_agreed_fee');
    var $units = findField($container, 'field_units');
    var $total = findField($container, 'field_total');

    if (!$total.length) {
      return;
    }

    var stdFee = parseFloat($stdFee.val()) || 0;
    var agreedFee = parseFloat($agreedFee.val()) || 0;
    var units = parseFloat($units.val()) || 0;
    var discount = getClientDiscount();

    // Agreed Fee overrides Standard Fee.
    var baseFee = agreedFee > 0 ? agreedFee : stdFee;
    var total = baseFee * units;

    // Apply client discount.
    if (discount > 0) {
      total = total * (1 - discount / 100);
    }

    // Round to 2 decimal places.
    total = Math.round(total * 100) / 100;
    $total.val(total);
  }

  Drupal.behaviors.associatedpsServiceFeeSync = {
    attach: function (context) {
      // --- Type of Service select: populate Standard Fee + recalculate Total ---
      $(once(
        'service-fee-sync',
        'select[name*="field_type_of_service"]',
        context
      )).on('change', function () {
        var $select = $(this);
        var termId = $select.val();
        if (!termId) {
          return;
        }

        var $container = getFormContainer($select);
        var fees = (drupalSettings.associatedps_forms || {}).service_fees || {};
        var stdFee = fees[termId] || '';

        // Set the standard fee value.
        var $stdFeeInput = findField($container, 'field_standard_fee');
        if ($stdFeeInput.length) {
          $stdFeeInput.val(stdFee);
        }

        calculateTotal($container);
      });

      // --- Standard Fee, Agreed Fee, Units: recalculate Total on change ---
      $(once(
        'fee-total-calc',
        [
          'input[name*="field_standard_fee"][name$="[value]"]',
          'input[name*="field_agreed_fee"][name$="[value]"]',
          'input[name*="field_units"][name$="[value]"]'
        ].join(','),
        context
      )).on('change keyup', function () {
        var $container = getFormContainer($(this));
        calculateTotal($container);
      });

      // --- Client discount change: recalculate all existing fee rows ---
      $(once('discount-sync', '#change-client-discount', context)).on('change keyup', function () {
        // Find all IEF forms that have a total field and recalculate each.
        $('input[name*="field_total"][name$="[value]"]').each(function () {
          var $container = getFormContainer($(this));
          calculateTotal($container);
        });
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
