/**
 * @file
 * Syncs the Allocated To field to Employee fields in Attendance Note paragraphs.
 *
 * Handles two scenarios:
 * 1. A new paragraph is generated (IEF AJAX) after Allocated To is already
 *    set — auto-populates the new empty Employee field on attach().
 * 2. Allocated To changes while paragraphs are open — overrides only
 *    new (auto-filled) paragraphs, never saved ones.
 *
 * Saved attendance notes are protected to preserve attendance history
 * when a job is reassigned to a different employee at different stages.
 * A data-employee-autofilled attribute marks inputs that were set by
 * this script, so only those are updated when Allocated To changes.
 *
 * Allocated To is an entity reference autocomplete field. Its value may
 * be "Label (entity_id)" (e.g. "John Smith (5)") or just the label
 * without an ID suffix. Both formats are handled.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // Selector for the Allocated To autocomplete input.
  var allocatedToSelector = 'input[name="field_allocated_to[0][target_id]"]';

  /**
   * Resolve the user ID from a Drupal autocomplete value string.
   *
   * First tries the standard "Label (id)" format. If no ID is found,
   * falls back to matching the raw value against user labels in
   * drupalSettings.
   */
  function resolveUserId(value) {
    if (!value) {
      return null;
    }

    // Try standard "Label (id)" format first.
    var match = value.match(/\((\d+)\)\s*$/);
    if (match) {
      return match[1];
    }

    // Fallback: match by label in drupalSettings.
    var trimmed = value.trim();
    var users = (drupalSettings.associatedps_forms || {}).user_details || {};
    for (var uid in users) {
      if (users.hasOwnProperty(uid)) {
        if (users[uid].label === trimmed) {
          return uid;
        }
        // Also match against "FirstName LastName" format.
        var fullName = users[uid].given_name + ' ' + users[uid].family_name;
        if (fullName === trimmed) {
          return uid;
        }
      }
    }

    return null;
  }

  /**
   * Look up user data from drupalSettings and populate the given
   * Employee autocomplete input and its sibling name fields.
   * Marks the input as auto-filled so it can be updated later.
   */
  function populateEmployee($input, userId) {
    var users = (drupalSettings.associatedps_forms || {}).user_details || {};
    var userData = users[userId];
    if (!userData) {
      return;
    }

    var autocompleteValue = userData.label + ' (' + userId + ')';
    var fullName = userData.given_name + ' ' + userData.family_name;

    $input.val(autocompleteValue);
    $input.attr('data-employee-autofilled', 'true');

    // Derive sibling name fields from this input's name prefix.
    var namePrefix = $input.attr('name').replace(
      /\[field_by\]\[\d+\]\[target_id\]$/,
      ''
    );

    $('input[name="' + namePrefix + '[field_employee_full_name][0][value]"]').val(fullName);
    $('input[name="' + namePrefix + '[field_employee_first_name][0][value]"]').val(userData.given_name);
    $('input[name="' + namePrefix + '[field_employee_last_name][0][value]"]').val(userData.family_name);
  }

  Drupal.behaviors.associatedpsEmployeeSync = {
    attach: function (context) {
      // Scenario 1: Allocated To changes — override only auto-filled
      // Employee fields (new paragraphs), never saved ones.
      // Listen for autocompleteclose (jQuery UI event fired after a
      // suggestion is picked from the dropdown).
      $(once(
        'employee-allocated-sync',
        allocatedToSelector,
        context
      )).on('autocompleteclose', function () {
        var userId = resolveUserId($(this).val());
        if (!userId) {
          return;
        }

        $('input[name*="field_attendance_notes"][name*="field_by"][name$="[target_id]"][data-employee-autofilled="true"]').each(function () {
          populateEmployee($(this), userId);
        });
      });

      // Scenario 2: New paragraph generated via IEF AJAX — auto-populate
      // empty Employee field from current Allocated To value.
      // attach() re-runs after every AJAX; once() ensures each new input
      // is processed exactly once.
      $(once(
        'employee-new-para-sync',
        'input[name*="field_attendance_notes"][name*="field_by"][name$="[target_id]"]',
        context
      )).each(function () {
        if ($(this).val()) {
          return; // Saved paragraph with existing Employee — leave untouched.
        }

        var userId = resolveUserId($(allocatedToSelector).val());
        if (!userId) {
          return;
        }

        populateEmployee($(this), userId);
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
