(function ($, Drupal, drupalSettings) {
  "use strict";
  Drupal.behaviors.associatedps_forms = {
    attach:function (context, settings) {
      $('.paragraph-type--document a.use-ajax').bind('click', function() {
        var dc_title = $(this).parent().find('.field--name-field-document-title input').val();
        var dc_notice = $(this).parent().find('.field--name-field-notice-period input').val();
        var dc_court_name = $(this).parent().find('.field--name-field-court-name input').val();
        var dc_aff_template = $(this).parent().find('.field--name-field-affidavit-template input').val();
        var field_dc = $(this).parent().attr('id');
        setCookie("field_dc", field_dc, 30);
        $(document).ajaxComplete(function(event, xhr, settings) {
          if(settings.url.indexOf('add-document') != -1){
            $('.wrapper-form-add-document').find('.form-item-document-title input').val(dc_title);
            $('.wrapper-form-add-document').find('.form-item-notice-period input').val(dc_notice);
            $('.wrapper-form-add-document').find('.form-item-document-court input').val(dc_court_name);
            $('.wrapper-form-add-document').find('.form-item-document-affidavit input').val(dc_aff_template);
          }
        });
      });
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
})(jQuery, Drupal, drupalSettings);