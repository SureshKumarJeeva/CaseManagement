(function ($, Drupal, drupalSettings, once) {
  "use strict";

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
      $(document).ajaxComplete(function(event, xhr, settings) {
        if(typeof settings.extraData != 'undefined' && settings.extraData._triggering_element_name == 'field_documents_document_add_more') {
          $('.field--name-field-documents .paragraph-type--document:last-child').find('a.use-ajax').text('Create New Document');
        }
        if(typeof settings.extraData != 'undefined' && settings.extraData._triggering_element_value == 'Save') {
          var dc_title = $.cookie('Drupal.visitor.document_title');
          var dc_notice = $.cookie('Drupal.visitor.notice_period');
          var dc_court_name = $.cookie('Drupal.visitor.document_court');
          var dc_aff_template = $.cookie('Drupal.visitor.document_affidavit');
          var field_dc = $.cookie('field_dc');
          if(field_dc != '' && dc_title != '') {
            $('#'+field_dc).find('.field--name-field-document-title input').val(dc_title);
            $('#'+field_dc).find('.field--name-field-notice-period input').val(dc_notice);
            $('#'+field_dc).find('.field--name-field-court-name input').val(dc_court_name);
            $('#'+field_dc).find('.field--name-field-affidavit-template input').val(dc_aff_template);
            $.removeCookie('Drupal.visitor.document_title', { path: '/' });
            $.removeCookie('Drupal.visitor.notice_period', { path: '/' });
            $.removeCookie('Drupal.visitor.document_court', { path: '/' });
            $.removeCookie('Drupal.visitor.document_affidavit', { path: '/' });
            $.removeCookie('field_dc', { path: '/' });
          }
        }
      });
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