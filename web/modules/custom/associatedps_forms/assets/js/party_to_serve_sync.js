/**
 * @file
 * Syncs Side Two party names into the Name to Serve and Person Delivered To dropdowns.
 *
 * On the job add/edit form, reads first/last name values from
 * field_party_2 (Side Two Parties) paragraph widgets and dynamically
 * populates the field_served_party (Name to Serve) select dropdown
 * and the field_person_delivered_to (Person Delivered To) select dropdown
 * so the user can pick a party without relying on stale allowed values.
 *
 * When the job status (field_case_status) changes, the Person Delivered To
 * field is automatically set to the currently selected Name to Serve value.
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Collect full names from Side Two party paragraphs (field_party_2).
   *
   * @return {string[]}
   *   Array of "FirstName LastName" strings.
   */
  function collectSideTwoNames() {
    var names = [];
    $('input[name^="field_party_2"]').each(function () {
      var inputName = $(this).attr('name') || '';
      // Match only the first-name field, not field_full_name.
      if (inputName.match(/\[subform\]\[field_name\]\[0\]\[value\]$/)) {
        var firstName = ($(this).val() || '').trim();
        var lastNameField = inputName.replace(
          '[field_name][0][value]',
          '[field_last_name][0][value]'
        );
        var lastName = ($('input[name="' + lastNameField + '"]').val() || '').trim();
        if (firstName || lastName) {
          names.push((firstName + ' ' + lastName).trim());
        }
      }
    });
    return names;
  }

  /**
   * Rebuild the Name to Serve select with Side Two party names.
   */
  function updateNameToServeDropdown() {
    var $select = $('select[name="field_served_party[select]"]');
    if (!$select.length) {
      return;
    }

    var names = collectSideTwoNames();
    if (names.length === 0) {
      return;
    }

    var currentValue = $select.val();
    var otherText =
      $select.find('option[value="select_or_other"]').text() || '- Other -';

    // Rebuild: empty option + unique party names + "- Other -".
    $select.empty();
    $select.append('<option value="">- None -</option>');

    var seen = {};
    $.each(names, function (_i, fullName) {
      if (fullName && !seen[fullName]) {
        seen[fullName] = true;
        $select.append(
          $('<option></option>').attr('value', fullName).text(fullName)
        );
      }
    });

    $select.append(
      $('<option></option>')
        .attr('value', 'select_or_other')
        .text(otherText)
    );

    // Keep previous selection if still valid, otherwise pick the first name.
    var stillValid =
      currentValue &&
      $select.find('option').filter(function () {
        return $(this).val() === currentValue;
      }).length > 0;

    if (stillValid) {
      $select.val(currentValue);
    } else {
      $select.val(names[0]);
    }
  }

  /**
   * Rebuild the Person Delivered To select with the same party names
   * so it stays in sync with the Name to Serve dropdown.
   */
  function updatePersonDeliveredToDropdown() {
    var $select = $('select[name="field_person_delivered_to"]');
    if (!$select.length) {
      return;
    }

    var names = collectSideTwoNames();
    if (names.length === 0) {
      return;
    }

    var currentValue = $select.val();

    // Rebuild: "- None -" + unique party names.
    $select.empty();
    $select.append('<option value="_none">- None -</option>');

    var seen = {};
    $.each(names, function (_i, fullName) {
      if (fullName && !seen[fullName]) {
        seen[fullName] = true;
        $select.append(
          $('<option></option>').attr('value', fullName).text(fullName)
        );
      }
    });

    // Keep previous selection if still valid.
    if (currentValue && currentValue !== '_none') {
      var stillValid = $select.find('option').filter(function () {
        return $(this).val() === currentValue;
      }).length > 0;
      if (stillValid) {
        $select.val(currentValue);
      }
    }
  }

  /**
   * Auto-set Person Delivered To from the currently selected Name to Serve.
   *
   * Called when the job status changes so the user doesn't have to manually
   * pick the same person again.
   */
  function syncPersonDeliveredFromNameToServe() {
    var $nameToServe = $('select[name="field_served_party[select]"]');
    var $personDelivered = $('select[name="field_person_delivered_to"]');
    if (!$nameToServe.length || !$personDelivered.length) {
      return;
    }

    var nameToServeValue = $nameToServe.val();
    if (!nameToServeValue || nameToServeValue === '' || nameToServeValue === 'select_or_other') {
      return;
    }

    // Ensure the option exists in Person Delivered To before setting it.
    var optionExists = $personDelivered.find('option').filter(function () {
      return $(this).val() === nameToServeValue;
    }).length > 0;
    if (!optionExists) {
      $personDelivered.append(
        $('<option></option>').attr('value', nameToServeValue).text(nameToServeValue)
      );
    }
    $personDelivered.val(nameToServeValue);
  }

  // ---- Address Served sync ------------------------------------------------
  //
  // Use name-attribute selectors (contains + ends-with) instead of IDs.
  // Drupal appends --2, --3 suffixes to HTML IDs after AJAX rebuilds
  // (paragraphs add/remove, address country change) which makes ID selectors
  // unreliable.  Name attributes are stable and map directly to the form API.

  /**
   * Find the first address sub-field element within a paragraph field.
   *
   * @param {string} paraName  The paragraph field machine name
   *   (e.g. 'field_service_address' or 'field_para_address').
   * @param {string} subField  The address sub-field key
   *   (e.g. 'address_line1', 'locality', 'administrative_area', 'postal_code').
   * @returns {jQuery} The matched element (or empty jQuery set).
   */
  function $addrField(paraName, subField) {
    return $('[name*="' + paraName + '"][name$="[address][' + subField + ']"]').first();
  }

  /**
   * Read the first Service Address from the Party to Serve tab.
   */
  function readFirstServiceAddress() {
    console.log('Reading first Service Address address_line1'+ $addrField('field_service_address', 'address_line1').val());
    var line1  = $addrField('field_service_address', 'address_line1').val() || '';
    var line3  = $addrField('field_service_address', 'address_line3').val() || '';
    var suburb = $addrField('field_service_address', 'locality').val()      || '';
    var state  = $addrField('field_service_address', 'administrative_area').val() || '';
    var postal = $addrField('field_service_address', 'postal_code').val()   || '';
    console.log('Read Service Address:', { line1, suburb, state, postal });
    if (!line1 && !suburb && !state && !postal) {
      return null;
    }
    return {
      address_line1: line1,
      address_line3: line3,
      locality: suburb,
      administrative_area: state,
      postal_code: postal
    };
  }

  /**
   * Populate the Address Served fields with values from the first
   * Service Address.  Only fills in when all destination fields are empty.
   */
  function syncAddressServed() {
    var addr = readFirstServiceAddress();
    console.log('Syncing Address Served with:', addr);
    if (!addr) {
      return;
    }
    console.log('Syncing Address Served 2:');
    var $line1  = $addrField('field_para_address', 'address_line1');
    var $line3  = $addrField('field_para_address', 'address_line3');
    var $suburb = $addrField('field_para_address', 'locality');
    var $state  = $addrField('field_para_address', 'administrative_area');
    var $postal = $addrField('field_para_address', 'postal_code');
    console.log('Syncing Address Served 3:');
      $line1.val(addr.address_line1);
      $line3.val(addr.address_line3);
      $suburb.val(addr.locality);
      $postal.val(addr.postal_code);
      // State is a Chosen-enhanced select — trigger change so Chosen updates.
      $state.val(addr.administrative_area).trigger('change').trigger('chosen:updated');
  }

  // ---- Behavior -----------------------------------------------------------

  Drupal.behaviors.associatedpsPartyToServeSync = {
    attach: function (context) {
      $(
        once(
          'party-serve-sync',
          'form.node-job-form, form.node-job-edit-form',
          context
        )
      ).each(function () {
        var $form = $(this);

        // Update when any horizontal tab is clicked.
        $form.find('.horizontal-tab-button a').on('click', function () {
          setTimeout(function () {
            updateNameToServeDropdown();
            updatePersonDeliveredToDropdown();
            // syncAddressServed();
          }, 100);
        });

        // Update Name to Serve and Person Delivered To on blur of Side Two party name fields.
        $form.on(
          'blur',
          'input[name*="field_party_2"][name$="[field_name][0][value]"], ' +
            'input[name*="field_party_2"][name$="[field_last_name][0][value]"]',
          function () {
            updateNameToServeDropdown();
            updatePersonDeliveredToDropdown();
          }
        );

        // Update Address Served on blur of Service Address text fields.
        $form.on(
          'blur',
            'input[name*="field_service_address"][name$="[address][address_line1]"], ' +
            'input[name*="field_service_address"][name$="[address][address_line3]"], ' +
            'input[name*="field_service_address"][name$="[address][locality]"], ' +
            'input[name*="field_service_address"][name$="[address][postal_code]"]',
          function () {
            // console.log('Service Address field blurred, syncing Address Served...');
            // syncAddressServed();
          }
        );

        // State is a select — listen for change instead of blur.
        $form.on(
          'change',
          '[name*="field_service_address"][name$="[address][administrative_area]"]',
          function () {
            // console.log('Service Address state changed, syncing Address Served...');
            // syncAddressServed();
          }
        );
        // When case status changes, auto-set Person Delivered To from Name to Serve.
        $form.on(
          'change',
          'select[name="field_case_status"]',
          function () {
            syncPersonDeliveredFromNameToServe();
          }
        );

      });

      // Bind directly on the Add Paragraph button (outside the main once block).
      // Drupal's AJAX stops event propagation so delegated handlers don't fire.
      // Using a separate once() ensures we bind exactly once per button instance,
      // and since attach() re-runs after every AJAX, it catches newly rendered buttons.
      $(once(
        'addr-para-add-sync',
        'input[name="ief-field_para_address-form-add"]',
        context
      )).on('mousedown', function () {
        console.log('Add Paragraph clicked, waiting for AJAX...');
        // Wait for the Add Paragraph AJAX to complete (Job Status form opens).
        $(document).one('ajaxComplete', function () {
          setTimeout(function () {
            console.log('AJAX complete after Add Paragraph.');
            // Now check the Party to Serve tab for the Service Address.
            var $serviceEditBtn = $('input[name="ief-field_service_address-form-entity-edit-0"]');
            if ($serviceEditBtn.length) {
              // Edit button exists — address is collapsed, trigger edit to open it.
              console.log('Service Address Edit button found, triggering...');
              $serviceEditBtn.trigger('mousedown');
              $(document).one('ajaxComplete', function () {
                setTimeout(function () {
                  console.log('AJAX complete after Service Address Edit, syncing...');
                  syncAddressServed();
                }, 300);
              });
            } else {
              // No edit button — address fields are already open, sync directly.
              console.log('Service Address fields already open, syncing...');
              syncAddressServed();
            }
          }, 300);
        });
      });
    }
  };
})(jQuery, Drupal, once);
