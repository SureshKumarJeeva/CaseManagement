/**
 * @file
 * Type-ahead suggestions for the search box on the app's list pages.
 *
 * As the user types (2+ characters), a dropdown lists matching values from
 * that page's own entities — served by ListSearchAutocompleteController, which
 * runs the page's view with the typed text. Picking a value only FILLS the box;
 * the user still presses the page's Apply/Search button (user decision).
 *
 * jQuery UI autocomplete is driven directly rather than through Drupal's
 * #autocomplete_route_name: core's version splits the box on commas (it is
 * built for several values at once), which would mangle an address or a name
 * containing a comma.
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.apsListSearchAutocomplete = {
    attach: function (context) {
      once('aps-list-search', 'input.aps-list-search', context).forEach(function (input) {
        var url = input.getAttribute('data-aps-list-search-url');
        if (!url || !$.fn.autocomplete) {
          return;
        }
        var pending = null;

        $(input).autocomplete({
          minLength: 2,
          delay: 250,
          source: function (request, response) {
            // Only the latest keystroke's answer matters.
            if (pending) {
              pending.abort();
            }
            pending = $.ajax({
              url: url,
              data: { q: request.term },
              dataType: 'json',
              success: function (data) {
                response(Array.isArray(data) ? data : []);
              },
              error: function () {
                response([]);
              },
              complete: function () {
                pending = null;
              }
            });
          },
          // Keep the typed text while moving through the list with the arrow
          // keys; only an actual pick replaces it.
          focus: function () {
            return false;
          },
          select: function (event, ui) {
            input.value = ui.item.value;
            return false;
          }
        });
      });
    }
  };

})(jQuery, Drupal, once);
