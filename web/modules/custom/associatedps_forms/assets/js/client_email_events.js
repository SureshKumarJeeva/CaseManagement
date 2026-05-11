/**
 * @file
 * Manages Mail Events + Email Template paired dropdowns on the Client form.
 *
 * - Shows/hides the pairs and "Add email update" button based on
 *   "Send Update Emails" checkbox.
 * - "Add email update" reveals the next pair of dropdowns.
 * - Hides the button when all available mail events are assigned.
 * - On edit pages, shows pairs that already have saved values.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.associatedpsClientEmailEvents = {
    attach: function (context) {
      $(once('client-email-events', '.email-event-pairs-wrapper', context)).each(function () {
        var $wrapper = $(this);
        var $form = $wrapper.closest('form');
        var $checkbox = $form.find('[name="field_send_auto_email[value]"]');
        var $addBtn = $form.find('.email-update-add-btn');
        var $addBtnWrapper = $addBtn.closest('.email-update-add-wrapper');
        var $pairs = $wrapper.find('.email-event-pair');
        var maxEvents = (drupalSettings.associatedps_forms || {}).max_mail_events || 0;
        var savedCount = (drupalSettings.associatedps_forms || {}).saved_event_count || 0;

        // Start with saved count visible, or 0 if none saved.
        var visibleCount = savedCount;

        /**
         * Update which pairs are visible and toggle the add button.
         */
        function updatePairs() {
          $pairs.each(function (i) {
            $(this).toggle(i < visibleCount);
          });
          // Hide add button when max reached.
          if (visibleCount >= maxEvents || visibleCount >= $pairs.length) {
            $addBtnWrapper.hide();
          }
          else {
            $addBtnWrapper.show();
          }
        }

        /**
         * Toggle entire section based on checkbox state.
         */
        function toggleSection() {
          var checked = $checkbox.is(':checked');
          if (checked) {
            $wrapper.show();
            $addBtnWrapper.show();
            // Show at least one pair when enabling.
            if (visibleCount === 0) {
              visibleCount = 1;
            }
            updatePairs();
          }
          else {
            $wrapper.hide();
            $addBtnWrapper.hide();
          }
        }

        // Bind checkbox.
        $checkbox.on('change', toggleSection);

        // Bind add button.
        $addBtn.on('click', function (e) {
          e.preventDefault();
          if (visibleCount < maxEvents && visibleCount < $pairs.length) {
            visibleCount++;
            updatePairs();
          }
        });

        // Initial state.
        toggleSection();
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
