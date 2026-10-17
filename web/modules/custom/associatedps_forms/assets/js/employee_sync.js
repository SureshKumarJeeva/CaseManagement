/**
 * @file
 * Fills the hidden Employee name fields of Attendance Note paragraphs.
 *
 * Each attendance note has hidden helper fields (field_employee_full_name /
 * _first_name / _last_name) read as template tokens. They are filled from the
 * note's own Employee (field_by) autocomplete value:
 * - when the add/edit form is generated (IEF AJAX) — a new note's Employee is
 *   prefilled server-side with the job's SAVED Allocated To
 *   (associatedps_forms_inline_entity_form_entity_form_alter);
 * - whenever the user picks a different Employee.
 *
 * The Employee field itself is never set from the Job Status tab's Allocated
 * To here: only a saved allocation counts, and that default is applied
 * server-side.
 *
 * The Employee value may be "Label (entity_id)" (e.g. "John Smith (5)") or just
 * the label without an ID suffix. Both formats are handled.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // Employee autocomplete inputs inside Attendance Note paragraphs.
  var employeeSelector = 'input[name*="field_attendance_notes"][name*="field_by"][name$="[target_id]"]';

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
   * Fill the hidden name fields next to the given Employee input from the
   * user it currently holds (cleared when it holds no known user).
   */
  function fillEmployeeNames($input) {
    var users = (drupalSettings.associatedps_forms || {}).user_details || {};
    var userData = users[resolveUserId($input.val())];

    // Derive sibling name fields from this input's name prefix.
    var namePrefix = $input.attr('name').replace(
      /\[field_by\]\[\d+\]\[target_id\]$/,
      ''
    );

    $('input[name="' + namePrefix + '[field_employee_full_name][0][value]"]').val(userData ? userData.given_name + ' ' + userData.family_name : '');
    $('input[name="' + namePrefix + '[field_employee_first_name][0][value]"]').val(userData ? userData.given_name : '');
    $('input[name="' + namePrefix + '[field_employee_last_name][0][value]"]').val(userData ? userData.family_name : '');
  }

  Drupal.behaviors.associatedpsEmployeeSync = {
    attach: function (context) {
      // attach() re-runs after every AJAX; once() ensures each Employee input
      // is processed exactly once. Fill on generation (covers the server-side
      // default), then again whenever the Employee changes — autocompleteclose
      // fires after a suggestion is picked, change covers typed/cleared values.
      $(once('employee-name-sync', employeeSelector, context)).each(function () {
        var $input = $(this);
        if ($input.val()) {
          fillEmployeeNames($input);
        }
        $input.on('autocompleteclose change', function () {
          fillEmployeeNames($input);
        });
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
