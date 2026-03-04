/**
 * @file
 * Syncs Service Addresses from Party to Serve into Attendance Note paragraphs.
 *
 * When a new Attendance Note paragraph is generated:
 * 1. Reads all Service Addresses from the Party to Serve tab.
 * 2. Finds the primary address (first checked, or first address as fallback).
 * 3. Auto-fills the Attendance Note address fields with the primary address.
 * 4. Populates the "Party To Serve Addresses" Select or Other dropdown with
 *    all addresses (primary pre-selected).
 * 5. When the user changes the dropdown, updates address fields accordingly.
 *
 * Works with dynamically generated IEF paragraphs via Drupal behaviors.
 * Service Address subforms are always visible (paragraphs widget edit_mode: open).
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // Address sub-field keys used for reading and writing.
  var addressSubFields = [
    'address_line1',
    'address_line3',
    'locality',
    'administrative_area',
    'postal_code',
    'organization'
  ];

  /**
   * Collect all Service Addresses from the Party to Serve tab.
   *
   * Iterates visible service address paragraph subforms, reads address
   * values and the primary checkbox state for each.
   *
   * @return {Array} Array of address objects with sub-field values and isPrimary flag.
   */
  function collectServiceAddresses() {
    var addresses = [];

    // Find all address_line1 inputs inside service address subforms.
    var $serviceInputs = $('input[name*="field_service_address"][name$="[address][address_line1]"]');
    console.log('[addr_sync] collectServiceAddresses — service address inputs found:', $serviceInputs.length);
    $serviceInputs.each(function () {
      var line1Name = $(this).attr('name');

      // Derive the subform prefix — everything before [field_service_address][0][address].
      // Handles both new paragraphs:  field_service_address[form][N][field_service_address]...
      // and saved entities:           field_service_address[form][inline_entity_form][entities][N][form][field_service_address]...
      var prefixMatch = line1Name.match(/^(.+?)\[field_service_address\]\[\d+\]\[address\]/);
      if (!prefixMatch) {
        return;
      }
      var prefix = prefixMatch[1];

      // Read each address sub-field.
      var addr = {};
      $.each(addressSubFields, function (_i, subField) {
        var sel = '[name="' + prefix + '[field_service_address][0][address][' + subField + ']"]';
        var $el = $(sel);
        addr[subField] = $el.length ? ($el.val() || '') : '';
      });

      // Check primary checkbox.
      var $primaryCb = $('input[name="' + prefix + '[field_primary_address][value]"]');
      addr.isPrimary = $primaryCb.length ? $primaryCb.is(':checked') : false;

      // Only include if at least one address field has a value.
      var hasValue = false;
      $.each(addressSubFields, function (_i, subField) {
        if (addr[subField]) {
          hasValue = true;
          return false; // break
        }
      });

      if (hasValue) {
        addresses.push(addr);
      }
    });

    // Fallback: when saved IEF paragraphs are in table/collapsed mode their
    // form fields are not in the DOM. Use addresses passed from PHP via
    // drupalSettings instead.
    var savedAddrs = (drupalSettings.associatedps_forms || {}).service_addresses || [];
    if (addresses.length === 0 && savedAddrs.length > 0) {
      var saved = savedAddrs;
      console.log('[addr_sync] collectServiceAddresses — using drupalSettings fallback, count:', saved.length);
      for (var i = 0; i < saved.length; i++) {
        addresses.push({
          address_line1: saved[i].address_line1 || '',
          address_line3: saved[i].address_line3 || '',
          locality: saved[i].locality || '',
          administrative_area: saved[i].administrative_area || '',
          postal_code: saved[i].postal_code || '',
          organization: saved[i].organization || '',
          isPrimary: !!saved[i].isPrimary
        });
      }
    }

    return addresses;
  }

  /**
   * Format an address object as a display label for the dropdown.
   *
   * Format: "Street Address 1, 3 - Suburb - State - Postal Code"
   * Empty parts are omitted.
   */
  function formatAddressLabel(addr) {
    var parts = [];

    // Street part: "line1, line3" or just "line1" or just "line3".
    var streetParts = [];
    if (addr.address_line1) {
      streetParts.push(addr.address_line1);
    }
    if (addr.address_line3) {
      streetParts.push(addr.address_line3);
    }
    if (streetParts.length) {
      parts.push(streetParts.join('-'));
    }

    if (addr.locality) {
      parts.push(addr.locality);
    }
    if (addr.administrative_area) {
      parts.push(addr.administrative_area);
    }
    if (addr.postal_code) {
      parts.push(addr.postal_code);
    }

    return parts.join('-');
  }

  /**
   * Find the index of the primary address.
   *
   * Returns the index of the first address with isPrimary=true.
   * If no primary is checked, returns 0 (first address as fallback).
   * If multiple primaries exist, the first one wins.
   *
   * @param {Array} addresses  Array of address objects.
   * @return {number} Index of the primary address.
   */
  function findPrimaryIndex(addresses) {
    for (var i = 0; i < addresses.length; i++) {
      if (addresses[i].isPrimary) {
        return i;
      }
    }
    // No primary checked — fall back to the first address.
    return 0;
  }

  /**
   * Populate address fields in an Attendance Note paragraph.
   *
   * @param {string} notePrefix  The name prefix for the attendance note subform,
   *   e.g. "field_attendance_notes[form][0]". This scopes the field lookup to
   *   a specific paragraph so multiple attendance notes don't interfere.
   * @param {Object} addr  Address object with sub-field values.
   */
  function populateAttendanceAddress(notePrefix, addr) {
    $.each(addressSubFields, function (_i, subField) {
      var sel = '[name="' + notePrefix + '[field_address_attendance][0][address][' + subField + ']"]';
      var $el = $(sel);
      if ($el.length) {
        $el.val(addr[subField] || '');
        // administrative_area is a select — trigger change for Chosen/UI update.
        if (subField === 'administrative_area') {
          $el.trigger('change').trigger('chosen:updated');
        }
      }
    });
  }

  /**
   * Build the Select or Other dropdown options from collected addresses.
   *
   * All addresses (including primary) are listed. The primary address
   * is pre-selected so the user can see which address was auto-filled
   * and can switch back to it after selecting another.
   *
   * @param {jQuery} $select  The select element inside the attendance note.
   * @param {Array} addresses  Array of address objects.
   * @param {number} primaryIndex  Index of the primary address to pre-select.
   */
  function buildAddressDropdown($select, addresses, primaryIndex) {
    // Preserve the "- Other -" label if it exists.
    var otherText = $select.find('option[value="select_or_other"]').text() || '- Other -';

    $select.empty();
    $select.append('<option value="">- None -</option>');

    $.each(addresses, function (i, addr) {
      var label = formatAddressLabel(addr);
      if (label) {
        $select.append(
          $('<option></option>').attr('value', label).text(label)
        );
      }
    });

    $select.append(
      $('<option></option>').attr('value', 'select_or_other').text(otherText)
    );

    // Pre-select the primary address.
    if (addresses.length > 0 && addresses[primaryIndex]) {
      var primaryLabel = formatAddressLabel(addresses[primaryIndex]);
      if (primaryLabel) {
        $select.val(primaryLabel);
      }
    }
  }

  Drupal.behaviors.associatedpsAttendanceAddressSync = {
    attach: function (context) {

      // --- Scenario 1: New attendance note paragraph generated ---
      //
      // Same pattern as party_to_serve_sync.js (addr-para-add-sync).
      // Bind to the IEF "Add new Paragraph" button via mousedown,
      // wait for AJAX to complete, then populate the new paragraph.
      $(once(
        'attendance-addr-add-sync',
        'input[name="ief-field_attendance_notes-form-add"]',
        context
      )).on('mousedown', function () {
        console.log('[addr_sync] Add new Paragraph button clicked');

        $(document).one('ajaxComplete', function () {
          console.log('[addr_sync] ajaxComplete after Add new Paragraph');

          // Wait for address sub-fields to render (Address module may
          // need a secondary AJAX for country-specific fields).
          function trySync(attempts) {
            // Find the newest empty attendance note address field.
            var $allLine1 = $('input[name*="field_attendance_notes"][name*="field_address_attendance"][name$="[address][address_line1]"]');
            console.log('[addr_sync] trySync attempt', attempts, '— address_line1 inputs:', $allLine1.length);

            // Find the last empty, unsynced one (the newly generated paragraph).
            var $target = null;
            $allLine1.each(function () {
              if (!$(this).val() && !$(this).data('attendance-addr-synced')) {
                $target = $(this);
              }
            });

            if (!$target) {
              // Address fields not yet in DOM — retry.
              if (attempts < 10) {
                setTimeout(function () { trySync(attempts + 1); }, 500);
              }
              return;
            }

            console.log('[addr_sync] Found target:', $target.attr('name'));
            $target.data('attendance-addr-synced', true);

            // Derive the note prefix, e.g. "field_attendance_notes[form][0]".
            var nameMatch = $target.attr('name').match(/^(field_attendance_notes\[form\]\[\d+\])/);
            if (!nameMatch) {
              return;
            }
            var notePrefix = nameMatch[1];
            console.log('[addr_sync] notePrefix:', notePrefix);

            // Read all service addresses from the Party to Serve tab.
            var addresses = collectServiceAddresses();
            console.log('[addr_sync] Collected addresses:', addresses.length, addresses);
            if (addresses.length === 0) {
              return;
            }

            var primaryIndex = findPrimaryIndex(addresses);
            console.log('[addr_sync] Primary index:', primaryIndex);

            // Auto-fill address fields with the primary address.
            populateAttendanceAddress(notePrefix, addresses[primaryIndex]);

            // Build the Select or Other dropdown with all addresses.
            var $select = $('select[name="' + notePrefix + '[field_party_to_serve_addresses][select]"]');
            if ($select.length) {
              buildAddressDropdown($select, addresses, primaryIndex);
              $select.data('service-addresses', addresses);
              console.log('[addr_sync] Dropdown built and address populated');
            }
          }

          setTimeout(function () { trySync(0); }, 300);
        });
      });

      // --- Scenario 2: User changes the dropdown ---
      //
      // Delegated event on the form so it works for dynamically added
      // paragraphs without re-binding.
      $(once(
        'attendance-addr-change',
        'form.node-job-form, form.node-job-edit-form',
        context
      )).on('change', 'select[name*="field_attendance_notes"][name*="field_party_to_serve_addresses"]', function () {
        var selectedValue = $(this).val();

        // "- Other -" or "- None -": don't override address fields.
        if (!selectedValue || selectedValue === 'select_or_other') {
          return;
        }

        // Look up the address from stored data.
        var addresses = $(this).data('service-addresses');
        if (!addresses) {
          return;
        }

        var matchedAddr = null;
        for (var i = 0; i < addresses.length; i++) {
          if (formatAddressLabel(addresses[i]) === selectedValue) {
            matchedAddr = addresses[i];
            break;
          }
        }

        if (!matchedAddr) {
          return;
        }

        // Derive the note prefix from the select's name.
        var selectName = $(this).attr('name');
        var notePrefix = selectName.replace(/\[field_party_to_serve_addresses\]\[select\]$/, '');

        populateAttendanceAddress(notePrefix, matchedAddr);
      });

      // --- Scenario 3: Rebuild dropdown for any attendance note (new or edited) ---
      //
      // Drupal.behaviors.attach() re-runs after every AJAX, including when
      // IEF opens an edit form for a saved attendance note. At that point
      // the select dropdown is in the DOM with ALL allowed_values from
      // field storage. We use once() directly on the select element so it
      // fires reliably regardless of how the form was opened.
      //
      // For NEW paragraphs Scenario 1 also fires and populates address
      // fields; this handler only rebuilds the dropdown options.
      $(once(
        'attendance-addr-edit-sync',
        'select[name*="field_attendance_notes"][name*="field_party_to_serve_addresses"][name$="[select]"]',
        context
      )).each(function () {
        var $select = $(this);

        // Read the current saved value before rebuilding.
        var savedValue = $select.val() || '';
        console.log('[addr_sync] Scenario 3 — select found, saved value:', savedValue);

        // Collect current job's service addresses from the Party to Serve tab.
        var addresses = collectServiceAddresses();
        console.log('[addr_sync] Scenario 3 — collected addresses:', addresses.length, addresses);

        if (addresses.length === 0) {
          return;
        }

        var primaryIndex = findPrimaryIndex(addresses);

        // Rebuild dropdown with only the job's addresses.
        buildAddressDropdown($select, addresses, primaryIndex);
        $select.data('service-addresses', addresses);

        // Re-select the saved value instead of the primary.
        if (savedValue && savedValue !== 'select_or_other') {
          var optionExists = $select.find('option').filter(function () {
            return $(this).val() === savedValue;
          }).length > 0;

          if (!optionExists) {
            // Saved value not among current addresses — add it as an option
            // so the user can see what was previously saved.
            $select.find('option[value="select_or_other"]').before(
              $('<option></option>').attr('value', savedValue).text(savedValue)
            );
          }
          $select.val(savedValue);
        }

        console.log('[addr_sync] Scenario 3 — dropdown rebuilt, value:', $select.val());
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
