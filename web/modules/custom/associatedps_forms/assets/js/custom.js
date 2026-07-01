(function ($, Drupal, drupalSettings, once) {
  "use strict";

  /**
   * Select a (brand-new) document in a paragraph's Document <select>.
   *
   * Used by the per-paragraph "Create New Document" modal. The Chosen library on
   * this site is the vanilla-JS build, which listens for "chosen:updated" via
   * native addEventListener; jQuery's .trigger() does NOT reach native custom
   * event listeners, so we dispatch native Events here. Same for "change" (drives
   * the notice-period #ajax). Appends only the one new option (guarded), never
   * regenerates the existing options.
   */
  $.fn.apsSelectDocument = function (nid, label) {
    return this.each(function () {
      var select = this;
      nid = String(nid);
      if (!select.querySelector('option[value="' + nid + '"]')) {
        var opt = document.createElement('option');
        opt.value = nid;
        opt.textContent = label;
        opt.selected = true;
        select.appendChild(opt);
      }
      select.value = nid;
      select.dispatchEvent(new Event('chosen:updated', { bubbles: true }));
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
  };

  /*
   * --- mailto popup DISABLED — automated emails now send silently via
   *     Microsoft 365 Graph (no OS mail client opens). PRESERVED for recovery:
   *     to restore, re-enable this behavior plus the mailto_data stash in
   *     EmailTemplateController and the reader in associatedps_forms.module. ---
   *
   * Drupal.behaviors.associatedpsMailtoTrigger = {
   *   attach: function (context) {
   *     $(once('mailto-trigger', 'body', context)).each(function () {
   *       var mailtoData = (drupalSettings.associatedps_forms || {}).mailto_trigger;
   *       if (mailtoData && mailtoData.to) {
   *         var mailto = 'mailto:' + encodeURIComponent(mailtoData.to)
   *           + '?subject=' + encodeURIComponent(mailtoData.subject || '');
   *         if (mailtoData.body) {
   *           mailto += '&body=' + encodeURIComponent(mailtoData.body);
   *         }
   *         delete drupalSettings.associatedps_forms.mailto_trigger;
   *         var $iframe = $('<iframe>', {
   *           src: mailto,
   *           style: 'display:none;'
   *         }).appendTo('body');
   *         setTimeout(function () { $iframe.remove(); }, 2000);
   *       }
   *     });
   *   }
   * };
   */

  // Generate dropdown toggle.
  Drupal.behaviors.generateDropdown = {
    attach: function (context) {
      $(once('generate-dropdown', '.generate-dropdown__toggle', context)).each(function () {
        var $toggle = $(this);
        var $dropdown = $toggle.closest('.generate-dropdown');

        $toggle.on('click', function (e) {
          e.preventDefault();
          $dropdown.toggleClass('open');
        });

        // Close when clicking outside or on a menu item.
        $(document).on('click', function (e) {
          if (!$(e.target).closest('.generate-dropdown').length) {
            $dropdown.removeClass('open');
          }
        });

        $dropdown.on('click', '.generate-dropdown__item', function () {
          $dropdown.removeClass('open');
        });
      });
    }
  };

  Drupal.behaviors.associatedps_forms = {
    attach:function (context, settings) {
      // NOTE: the legacy $(document).ajaxComplete() handler that used the
      // jQuery Cookie plugin ($.cookie) to back-fill a newly created document's
      // fields was removed. It belonged to the old cookie-based "Create New
      // Document" flow (now replaced by the per-paragraph button that fills the
      // Document select directly via AJAX). It also threw "TypeError: $.cookie
      // is not a function" on every Save AJAX because the plugin isn't loaded.
      function setCookie(cname,cvalue,exdays) {
        var d = new Date();
        d.setTime(d.getTime() + (exdays*24*60*60*1000));
        var expires = "expires=" + d.toGMTString();
        document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
      }
      $('#edit-field-client-target-id').autocomplete({
        select: function( event, ui ) {
          if(typeof ui.item.value != 'undefined') {
            var result = ui.item.value.split(' (');
            var nid = result[result.length - 1].match(/\d+/)[0];
            var endpoint =  Drupal.url('get-node-content/'+nid);
            //var html = Drupal.ajax({ url: endpoint }).execute();
            $.ajax({
              url: endpoint,
              type: "GET",
              dataType: "html",
              success: function(data) {
                var result = $('<div />').append(data).find('.client-info').html();
                $('#edit-field-client-wrapper').append(result);
              }
            });
          }
        }
      });
    }
  }
})(jQuery, Drupal, drupalSettings, once);