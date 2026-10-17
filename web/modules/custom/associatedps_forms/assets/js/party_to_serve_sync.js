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
(function ($, Drupal, once, drupalSettings) {
  'use strict';

  /**
   * Collect "FirstName LastName" values from a party paragraph field.
   *
   * @param {string} fieldPrefix  The party field machine name
   *   (e.g. 'field_parties' for Side One or 'field_party_2' for Side Two).
   * @return {string[]}
   */
  function collectPartyNames(fieldPrefix) {
    var names = [];
    $('input[name^="' + fieldPrefix + '"]').each(function () {
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
   * Side Two party names only (used by Name to Serve = the served party).
   *
   * @return {string[]}
   */
  function collectSideTwoNames() {
    return collectPartyNames('field_party_2');
  }

  /**
   * All party names — Side One (field_parties) + Side Two (field_party_2) +
   * Other Parties (field_other_parties, whose name is also in field_name).
   * Person Delivered To can be any party, so it lists all of them (matching the
   * server-side option list built in associatedps_forms.module).
   *
   * @return {string[]}
   */
  function collectAllPartyNames() {
    return collectPartyNames('field_parties')
      .concat(collectPartyNames('field_party_2'))
      .concat(collectPartyNames('field_other_parties'));
  }

  /**
   * Rebuild the Name to Serve select with Side Two party names.
   */
  function updateNameToServeDropdown() {
    var $select = $('select[name="field_served_party[select]"]');
    if (!$select.length) {
      return;
    }

    // Name to Serve lists ALL parties (Side One + Side Two + Other Parties).
    var names = collectAllPartyNames();
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
      // Default to the first Side Two party (the party being served) if any,
      // otherwise the first available name.
      var sideTwoDefault = collectSideTwoNames();
      $select.val(sideTwoDefault.length ? sideTwoDefault[0] : names[0]);
    }
  }

  /**
   * Rebuild a "Person Delivered To" select with the same party names so it
   * stays in sync with the Name to Serve dropdown.
   *
   * Used for both the Job Status field (field_person_delivered_to) and its
   * Attendance Note copy (field_atten_person_delivered_to) so the two offer an
   * identical option set — the prerequisite for the server-side value sync.
   *
   * @param {string} [selectName]  The select's name attribute. Defaults to the
   *   Job Status field for backward compatibility.
   */
  function updatePersonDeliveredToDropdown(selectName) {
    var $select = $('select[name="' + (selectName || 'field_person_delivered_to') + '"]');
    if (!$select.length) {
      return;
    }

    // Person Delivered To lists BOTH Side One and Side Two parties.
    var names = collectAllPartyNames();
    if (names.length === 0) {
      return;
    }

    var currentValue = $select.val();

    var nameSet = {};
    $.each(names, function (_i, n) {
      if (n) {
        nameSet[n] = true;
      }
    });

    // Capture accumulated custom ("Other") options (server-provided, not party
    // names and not the special markers) so the rebuild keeps them reusable.
    var customOptions = [];
    $select.find('option').each(function () {
      var v = $(this).val();
      if (v && v !== '_none' && v !== '_other' && !nameSet[v]) {
        customOptions.push({ value: v, text: $(this).text() });
      }
    });

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

    // Re-add the accumulated custom values (incl. the current one) so they stay
    // selectable.
    $.each(customOptions, function (_i, opt) {
      if (!seen[opt.value]) {
        seen[opt.value] = true;
        $select.append($('<option></option>').attr('value', opt.value).text(opt.text));
      }
    });
    if (currentValue && currentValue !== '_none' && currentValue !== '_other' && !seen[currentValue]) {
      $select.append($('<option></option>').attr('value', currentValue).text(currentValue));
    }

    // The "- Other -" trigger is always last.
    $select.append($('<option></option>').attr('value', '_other').text('- Other -'));

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
      // Job Status Service Details popup — fill the Service Address fields from
      // the selected "Party to Serve Addresses" option. The option value encodes
      // the address as "line1-locality-administrativeArea-postalCode" (the last
      // three '-' segments are postcode/state/suburb; the rest is the street
      // line, which may itself contain hyphens). The job address paragraph is
      // field_para_address; the Attendance tab has its own sync.
      function fillPartyServeAddress($select) {
        var name = $select.attr('name') || '';
        var val = $select.val();
        if (!val || val === '_none' || val === 'select_or_other') {
          return;
        }
        var parts = val.split('-');
        if (parts.length < 4) {
          return;
        }
        var postal = parts.pop();
        var state = parts.pop();
        var suburb = parts.pop();
        var line1 = parts.join('-');
        var base = name.replace(/\[field_party_to_serve_addresses\]\[select\]$/, '') + '[field_address][0][address]';
        function set(sub, value, isSelect) {
          var $el = $('[name="' + base + '[' + sub + ']"]');
          if (!$el.length) {
            return;
          }
          $el.val(value);
          if (isSelect) {
            $el.trigger('change').trigger('chosen:updated');
          }
        }
        set('address_line1', line1, false);
        // The second street line is address_line3 (addressLine2 is hidden on
        // these address fields). It is not encoded in the option value; the
        // module passes it in drupalSettings keyed by option value. Blank when
        // the address has none.
        var line3Map = (drupalSettings && drupalSettings.apsPartyServeAddressLine3) || {};
        set('address_line3', line3Map[val] || '', false);
        set('locality', suburb, false);
        set('administrative_area', state, true);
        set('postal_code', postal, false);
      }

      // Fill on change (switching between multiple addresses). Bound on document
      // because the popup is a dialog whose select may sit outside the matched
      // form element; a single auto-selected option fires no change, so the
      // on-open handler below covers that case.
      // Both the Job Status (field_para_address) and Attendance (field_attendance_
      // address_served) Service Details popups use an 'address' paragraph with
      // field_party_to_serve_addresses + field_address, so the same handler
      // serves both. The attendance NOTE paragraphs (field_attendance_notes,
      // field_address_attendance) are handled by attendance_address_sync.js and
      // are intentionally NOT matched here.
      var partyServeSelects =
        'select[name*="field_para_address"][name*="field_party_to_serve_addresses"][name$="[select]"], ' +
        'select[name*="field_attendance_address_served"][name*="field_party_to_serve_addresses"][name$="[select]"]';

      $(once('party-addr-change', 'html', context)).each(function () {
        $(document).on('change', partyServeSelects, function () {
          fillPartyServeAddress($(this));
        });
      });

      // "- Other -" on Person Delivered To: selecting it reveals an inline text
      // box; the typed name is appended as a selected option (Chosen-friendly,
      // avoids the select_or_other widget which regresses here). Covers the Job
      // Status (field_person_delivered_to) and Attendance
      // (field_atten_person_delivered_to) fields.
      var personDeliveredSelects =
        'select[name="field_person_delivered_to"], select[name="field_atten_person_delivered_to"]';
      $(once('pdt-other', 'html', context)).each(function () {
        // Toggle the inline text box on (de)selecting "- Other -".
        $(document).on('change', personDeliveredSelects, function () {
          var $select = $(this);
          var $wrapper = $select.closest('.form-item');
          var $box = $wrapper.find('.pdt-other-box');
          if ($select.val() === '_other') {
            if (!$box.length) {
              $box = $('<input type="text" class="pdt-other-box form-text form-element" placeholder="Enter name" autocomplete="off" />');
              $wrapper.append($box);
            }
            $box.val('').show().trigger('focus');
          }
          else if ($box.length) {
            $box.hide();
          }
        });

        // Commit the typed value (Enter or blur): append as a selected option.
        $(document).on('blur keydown', '.pdt-other-box', function (e) {
          if (e.type === 'keydown' && e.which !== 13) {
            return;
          }
          if (e.type === 'keydown') {
            e.preventDefault();
          }
          var $box = $(this);
          var val = ($box.val() || '').trim();
          var $select = $box.closest('.form-item')
            .find('select[name="field_person_delivered_to"], select[name="field_atten_person_delivered_to"]').first();
          if (!$select.length) {
            return;
          }
          if (!val) {
            $select.val('_none');
          }
          else {
            var exists = $select.find('option').filter(function () {
              return $(this).val() === val;
            }).length > 0;
            if (!exists) {
              $select.find('option[value="_other"]').before(
                $('<option></option>').attr('value', val).text(val)
              );
            }
            $select.val(val);
          }
          var el = $select.get(0);
          if (el) {
            el.dispatchEvent(new Event('chosen:updated', { bubbles: true }));
          }
          $box.hide();
        });
      });

      // Fill on open, only when the address fields are still empty (don't
      // clobber manual edits). If the dropdown defaulted to "- Other -" / none
      // (a new paragraph has no preset value), pick the first real address
      // first, then fill from it.
      $(once('party-addr-fill', partyServeSelects, context)).each(function () {
        var $s = $(this);
        var fillBase = ($s.attr('name') || '').replace(/\[field_party_to_serve_addresses\]\[select\]$/, '') + '[field_address][0][address]';
        var $line1 = $('[name="' + fillBase + '[address_line1]"]');
        if (!$line1.length || $line1.val()) {
          return;
        }
        var val = $s.val();
        if (!val || val === 'select_or_other' || val === '_none') {
          var firstVal = null;
          $s.find('option').each(function () {
            var v = $(this).val();
            if (firstVal === null && v && v !== 'select_or_other' && v !== '_none') {
              firstVal = v;
            }
          });
          if (firstVal) {
            $s.val(firstVal);
            var el = $s.get(0);
            if (el) {
              el.dispatchEvent(new Event('chosen:updated', { bubbles: true }));
            }
          }
        }
        fillPartyServeAddress($s);
      });

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
            // Keep the Attendance Note copy in lockstep with the Job Status field.
            updatePersonDeliveredToDropdown('field_atten_person_delivered_to');
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
            updatePersonDeliveredToDropdown('field_atten_person_delivered_to');
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
})(jQuery, Drupal, once, drupalSettings);
