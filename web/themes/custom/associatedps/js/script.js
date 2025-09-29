(function ($, Drupal, once) {

  Drupal.behaviors.Default = {
    attach: function (context, settings) {
      once('my-unique-id', 'body', context).forEach(function () {

        // Stop the throbbing.

        $(document).ready(function () {
          $('body').css('visibility', 'visible');
          $('html').css('background-image', 'none');
        });

        // Job/Client page edit/add functions.

        if ($('.page-node-type-job, .page-node-type-client').not('.page-node-type-node-view, .page-node-type-client-view').length > 0) {

          // Prefix the Client ID field value with the first three
          // letters of the Client Name field value.

            if ($('#node-job-edit-form').length > 0 || $('#node-job-form').length > 0) {

              if ($('.step-label.active').text() !== 'Job Detail') {
                $('#edit-group-job-detail').hide();
              }
              var form_step = getCookie("current_step");
              if (($('.step-label.active').text() === 'Job Detail') && (form_step != null)) {
                $('#edit-next').click();
              }

              var create_doc_for_job = getCookie("create_doc_for_job");
              if (($('.step-label.active').text() === 'Job Detail') && (create_doc_for_job != null)) {
                $('#edit-next').click();
              }
              else if (($('.step-label.active').text() === 'Court Detail') && (create_doc_for_job != null)) {
                $('#edit-next').click();
              }
              else if (($('.step-label.active').text() === 'Party') && (create_doc_for_job != null)) {
                $('#edit-next').click();
              }
              else {
                delete_cookie('create_doc_for_job');
              }

              function delete_cookie(name) {
                document.cookie = name +'=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;';
              }

              function getCookie(name) {
                const value = `; ${document.cookie}`;
                const parts = value.split(`; ${name}=`);
                if (parts.length === 2) return parts.pop().split(';').shift();
              }

              var user_role = $('.logged-in-user-role').val();
              if (user_role === 'agent') {
                $('#job-details').hide();
                $('#court-details').hide();
                $('#parties-panel').hide();
                $('#documents-panel').hide();
                $('#party-served-details').hide();
                $('#job-fees-panel').hide();
                if (($('.step-label.active').text() === 'Job Detail') || ($('.step-label.active').text() === 'Court Detail') || ($('.step-label.active').text() === 'Party') || ($('.step-label.active').text() === 'Documents') || ($('.step-label.active').text() === 'Party To Serve') || ($('.step-label.active').text() === 'Job Fees')){
                  $('#edit-next').click();
                }
              }

            /* to clear all value on click type_of_service field. */
              $(document).on('click', "[id*='_subform_field_type_of_service']", function(){
                var ele = $(this);
                var current_id = ele.attr("id");
                var service = $("#"+current_id+" .search-choice span").text();
                var append_id = (current_id.split("_"))[4];
                if (service == '') {
                  $("input[name='field_job_fees["+append_id+"][subform][field_standard_fee][0][value]']").val('');
                  $("input[name='field_job_fees["+append_id+"][subform][field_agreed_fee][0][value]']").val('');
                  $("input[name='field_job_fees["+append_id+"][subform][field_units][0][value]']").val('');
                  $("input[name='field_job_fees["+append_id+"][subform][field_total][0][value]']").val('');
                }
              });

              /* Auto calculate agreed_fees based on click standar_fees and discount. */
              $(document).on('keyup click', "input[name*='[subform][field_standard_fee][0][value]']", function(){
                var ele = $(this);
                var current_id = ele.attr("id");
                var append_id = (current_id.split("-"))[4];
                var agree_fee = '';
                var std_fee = ele.val();
                var dis_val = $('#change-client-discount').val();
                if (std_fee.length > 0 && dis_val.length >0) {
                  var agree_fee = (std_fee - (std_fee * dis_val / 100));
                  $("input[name='field_job_fees["+append_id+"][subform][field_agreed_fee][0][value]']").val(agree_fee);
                }
              });

              /* Auto calculate agreed_fees based on click calculate button and discount. */
              $(document).on('click', "input[data-drupal-selector*='-subform-calculate-button']", function(){
                var ele = $(this);
                var current_id = ele.attr("data-drupal-selector");
                var append_id = (current_id.split("-"))[4];
                var agree_fee = '';
                var std_fee = $("input[name='field_job_fees["+append_id+"][subform][field_standard_fee][0][value]']").val();
                var dis_val = $('#change-client-discount').val();
                if (std_fee.length > 0 && dis_val.length >0) {
                  var agree_fee = (std_fee - (std_fee * dis_val / 100));
                  $("input[name='field_job_fees["+append_id+"][subform][field_agreed_fee][0][value]']").val(agree_fee);
                }
              });

              /* Auto calculate total based on click/keyup agreed_fee and units. */
              $(document).on('keyup click', "input[name*='[subform][field_agreed_fee][0][value]']", function(){
                var ele = $(this);
                var current_id = ele.attr("id");
                var append_id = (current_id.split("-"))[4];
                var total = '';
                var agree_fee = ele.val();
                var unit = $("input[name='field_job_fees["+append_id+"][subform][field_units][0][value]']").val();
                if (agree_fee.length > 0 && unit.length >0) {
                  var total = (agree_fee * unit);
                  $("input[name='field_job_fees["+append_id+"][subform][field_total][0][value]']").val(total);
                }
              });

              /* Auto calculate totls based on click/keyup units and agreed_fee. */
              $(document).on('keyup click', "input[name*='[subform][field_units][0][value]']", function(){
                var ele = $(this);
                var current_id = ele.attr("id");
                var append_id = (current_id.split("-"))[4];
                var total = '';
                var unit = ele.val();
                var agree_fee = $("input[name='field_job_fees["+append_id+"][subform][field_agreed_fee][0][value]']").val();
                if (unit.length > 0 && agree_fee.length >0) {
                  var total = (unit * agree_fee);
                  $("input[name='field_job_fees["+append_id+"][subform][field_total][0][value]']").val(total);
                }
              });

              /* Attendance note Employee First Name and Last Name */
              $(document).on('mouseout', "div[id*='_subform_field_by']", function(){
                var ele = $(this);
                var current_id = ele.attr("id");
                var append_id = (current_id.split("_"))[4];
                var served_by = $("div[id*='edit_field_attendance_notes_"+append_id+"_subform_field_by'] a span").text();
                var served_user = served_by.split(" ");
                $("input[name='field_attendance_notes["+append_id+"][subform][field_employee_first_name][0][value]']").val(served_user[0]);
                $("input[name='field_attendance_notes["+append_id+"][subform][field_employee_last_name][0][value]']").val(served_user[1]);
                $("input[name='field_attendance_notes["+append_id+"][subform][field_employee_full_name][0][value]']").val(served_by);
              });

              /* Postal Code as per Primary Address. */
              $(document).on('click', "input[id*='-subform-field-primary-address-value']", function(){
                var ele = $(this);
                var current_id = ele.attr("id");
                var append_id = (current_id.split("-"))[4];
                if ($(this).is(':checked')) {
                  var postal_code = $("input[name='field_service_address["+append_id+"][subform][field_service_address][0][address][postal_code]']").val();
                  if (postal_code !== '') {
                    $("input[name='field_primary_postal_code[0][value]']").val(postal_code);
                  }
                }
                else {
                  $("input[name='field_primary_postal_code[0][value]']").val('');
                }
              });

              if (($('.step-label.active').text() === 'Job Status') || $('.step-label.active').text() === 'Attendance Note') {
                document.body.addEventListener('mouseover', removeselectoption, true);
              }
              function removeselectoption(){
                $("select[name*='[subform][field_party_to_serve_addresses]'] option[value='select_or_other']").remove();
                $("select[name*='[subform][field_party_to_serve_addresses]'] option:contains('- None -')").text('Other');
              }
            }

            /* Address in Service Detail Section and Attendance Note Section */
            $(document).on('change', "select[name*='[subform][field_party_to_serve_addresses]']", function(){

              var ele = $(this);
              var current_id = ele.attr("data-drupal-selector");
              var append_id = (current_id.split("-"))[4];
              var address = ele.val();
              var address = (address.split("-"));

              if (current_id === 'edit-field-para-address-0-subform-field-party-to-serve-addresses-select') {

                $('[data-drupal-selector="edit-field-para-address-0-subform-field-address-0-address-address-line1"]').val(address[0]);
                // $('[data-drupal-selector="edit-field-para-address-0-subform-field-address-0-address-address-line2"]').val($addressLine2);
                $('[data-drupal-selector="edit-field-para-address-0-subform-field-address-0-address-locality"]').val(address[1]);
                $('[data-drupal-selector="edit-field-para-address-0-subform-field-address-0-address-administrative-area"]').val(address[2]);
                // $('[data-drupal-selector="edit-field-para-address-0-subform-field-address-0-address-organization"]').val($addressCompany);
                $('[data-drupal-selector="edit-field-para-address-0-subform-field-address-0-address-postal-code"]').val(address[3]);
              }
              else {

                $('[data-drupal-selector="edit-field-attendance-notes-' + append_id + '-subform-field-address-attendance-0-address-address-line1"]').val(address[0]);
                // $('[data-drupal-selector="edit-field-attendance-notes-' + append_id + '-subform-field-address-attendance-0-address-address-line2"]').val($addressLine2);
                $('[data-drupal-selector="edit-field-attendance-notes-' + append_id + '-subform-field-address-attendance-0-address-locality"]').val(address[1]);
                $('[data-drupal-selector="edit-field-attendance-notes-' + append_id + '-subform-field-address-attendance-0-address-administrative-area"]').val(address[2]);
                // $('[data-drupal-selector="edit-field-attendance-notes-' + append_id + '-subform-field-address-attendance-0-address-organization"]').val($addressCompany);
                $('[data-drupal-selector="edit-field-attendance-notes-' + append_id + '-subform-field-address-attendance-0-address-postal-code"]').val(address[3]);
              }
            });

          if ($('#node-client-edit-form').length > 0 || $('#node-client-form').length > 0) {
            var $icid = $('#edit-field-client-id-0-value').val();
            $('#edit-title-0-value').on('keydown', '', function (e) {
              if ($icid.length > 0 && e.keyCode != '9') {
                var $cid = '';
                $('#edit-field-client-id-0-value').val($cid);
              }
              e.stopPropagation();
            });
            $('#edit-title-0-value').on('keyup', '', function (e) {
              if ($icid.length > 0 && e.keyCode != '9') {
                var $cid = $icid.replace(/[^0-9]/g, '');
                var $client = $('#edit-title-0-value').val().toUpperCase().replace(/ /g, '').substring(0, 3);
                $('#edit-field-client-id-0-value').val($client + $cid);
              }
              e.stopPropagation();
            });
            $('#edit-title-0-value').on('ready', '', function (e) {
              if ($icid.length > 0 && e.keyCode != '9') {
                var $cid = $icid.replace(/[^0-9]/g, '');
                var $client = $('#edit-title-0-value').val().toUpperCase().replace(/ /g, '').substring(0, 3);
                $('#edit-field-client-id-0-value').val($client + $cid);
              }
            });
          }
        }

        // Job page edit/add functions.

        if ($('.page-node-type-job').not('.page-node-type-node-view').length > 0) {

          // Remove the Existing address field on Party to Serve addresses.

          $('.field--name-field-service-address .field--name-field-party-to-serve-addresses').remove();

          // Calculate the Agreed Fee.

          var $agreedDiscount = 0;
          var $agreedFee = 0;

          $('#edit-field-standard-fee-0-value').keyup(function (e) {
            if ($('.field--name-field-agreed-discount input').length > 0) {
              var $agreedDiscount = parseInt($('.field--name-field-agreed-discount input').val());
            }
            else if ($('.client-agreed-discount-number').length > 0) {
              var $agreedDiscount = parseInt($('.client-agreed-discount-number').text().replace('%', ''));
            }
            var $agreedFee = parseFloat($('#edit-field-agreed-fee-0-value').val()).toFixed(2);
            var $standardFee = parseFloat($('#edit-field-standard-fee-0-value').val()).toFixed(2);

            if (e.keyCode != '9') {
              var $total = $standardFee - (($standardFee / 100) * $agreedDiscount);
              var $total = $total.toFixed(2);
              $('#edit-field-agreed-fee-0-value').val($total);
            }
          });

          // Admin status date functions.

          $('#edit-field-admin-status').on('change', function (index) {

            $('.field--name-field-admin-send-to-client-date').hide();
            $('.field--name-field-admin-invoiced-date').hide();
            $('.field--name-field-admin-email-update-date').hide();
            $('.field--name-field-admin-cover-letter-date').hide();
            $('.field--name-field-admin-affidavit-date').hide();

            var $today = moment().format('YYYY-MM-DD');

            $('#edit-field-admin-status option:selected').each(function (index) {

              if ($(this).val() == 'Sent to Client') {
                $('.field--name-field-admin-send-to-client-date').show();
                if ($('.field--name-field-admin-send-to-client-date input').val().length < 1) {
                  $('.field--name-field-admin-send-to-client-date input').val($today);
                  $('.field--name-field-admin-send-to-client-date input').trigger('change');
                }
              }

              if ($(this).val() == 'Invoiced') {
                $('.field--name-field-admin-invoiced-date').show();
                if ($('.field--name-field-admin-invoiced-date input').val().length < 1) {
                  $('.field--name-field-admin-invoiced-date input').val($today);
                  $('.field--name-field-admin-invoiced-date input').trigger('change');

                }
              }

              if ($(this).val() == 'Email Update Sent') {
                $('.field--name-field-admin-email-update-date').show();
                if ($('.field--name-field-admin-email-update-date input').val().length < 1) {
                  $('.field--name-field-admin-email-update-date input').val($today);
                  $('.field--name-field-admin-email-update-date input').trigger('change');
                }
              }

              if ($(this).val() == 'Cover Letter Drafted') {
                $('.field--name-field-admin-cover-letter-date').show();
                if ($('.field--name-field-admin-cover-letter-date input').val().length < 1) {
                  $('.field--name-field-admin-cover-letter-date input').val($today);
                  $('.field--name-field-admin-cover-letter-date input').trigger('change');
                }
              }

              if ($(this).val() == 'Affidavit Drafted') {
                $('.field--name-field-admin-affidavit-date').show();
                if ($('.field--name-field-admin-affidavit-date input').val().length < 1) {
                  $('.field--name-field-admin-affidavit-date input').val($today);
                  $('.field--name-field-admin-affidavit-date input').trigger('change');
                }
              }
            });

          }).trigger('change');


          // Default 'Person Delivered To' to 'Name to Serve'.

          $('#edit-field-served-party-select').change(function (e) {

            var $textName = $('#edit-field-served-party-select option:selected').text();
            var $idName = $('#edit-field-served-party-select option:selected').val();

            if ($('#edit-field-person-delivered-to-select option:selected').val() < 1) {
              $('#edit-field-person-delivered-to-select option').val($idName);
            }
          });

          // Default 'Served By' to 'Allocated to' (same user).

          $('#edit-field-allocated-to').change(function (e) {

            var $allocatedUser = $('#edit_field_allocated_to_chosen a span').text();
            var $allocatedUserId = $('#edit-field-allocated-to option').filter(':selected').val();
            $('#edit_field_served_by_chosen a span').text($allocatedUser);
            $('#edit-field-served-by option[value="' + $allocatedUserId + '"]').attr('selected', 'selected');

            if ($allocatedUser !== '- None -') {
              var $allocated_user = $allocatedUser.split(" ");

              $('#edit-field-served-by-full-name-0-value').val($allocatedUser);
              $('#edit-field-served-by-first-name-0-value').val($allocated_user[0]);
              $('#edit-field-served-by-last-name-0-value').val($allocated_user[1]);

              $('#edit-field-allocated-to-full-name-0-value').val($allocatedUser);
              $('#edit-field-allocated-to-first-name-0-value').val($allocated_user[0]);
              $('#edit-field-allocated-to-last-name-0-value').val($allocated_user[1]);
            }
            else {
              $('#edit-field-served-by-full-name-0-value').val('');
              $('#edit-field-served-by-first-name-0-value').val('');
              $('#edit-field-served-by-last-name-0-value').val('');

              $('#edit-field-allocated-to-full-name-0-value').val('');
              $('#edit-field-allocated-to-first-name-0-value').val('');
              $('#edit-field-allocated-to-last-name-0-value').val('');
            }

          });

          $('#edit-field-case-status').change(function (e) {
            var $allocatedUser = $('#edit_field_allocated_to_chosen a span').text();
            var $allocatedUserId = $('#edit-field-allocated-to option').filter(':selected').val();
            $('#edit_field_served_by_chosen a span').text($allocatedUser);
            $('#edit-field-served-by option[value="' + $allocatedUserId + '"]').attr('selected', 'selected');

            var now = new Date();
            var day = ("0" + now.getDate()).slice(-2);
            var month = ("0" + (now.getMonth() + 1)).slice(-2);
            var today = now.getFullYear()+"-"+(month)+"-"+(day) ;

            var hours = (now.getHours() <= 9) ? '0' + now.getHours() : now.getHours();
            var timeValue = hours + ':' + now.getMinutes();

            $('#edit-field-date-service-0-value-date').val(today);
            $('#edit-field-date-service-0-value-time').val(timeValue);

            if ($allocatedUser !== '- None -') {
              var $allocated_user = $allocatedUser.split(" ");

              $('#edit-field-served-by-full-name-0-value').val($allocatedUser);
              $('#edit-field-served-by-first-name-0-value').val($allocated_user[0]);
              $('#edit-field-served-by-last-name-0-value').val($allocated_user[1]);
            }
            else {
              $('#edit-field-served-by-full-name-0-value').val('');
              $('#edit-field-served-by-first-name-0-value').val('');
              $('#edit-field-served-by-last-name-0-value').val('');
            }
          });

          $('#edit-field-served-by').change(function (e) {
            var $servedBy = $('#edit_field_served_by_chosen a span').text();
            var $servedById = $('#edit-field-served-by option').filter(':selected').val();

            if ($servedBy !== '- None -') {
              var $served_user = $servedBy.split(" ");
              $('#edit-field-served-by-full-name-0-value').val($servedBy);
              $('#edit-field-served-by-first-name-0-value').val($served_user[0]);
              $('#edit-field-served-by-last-name-0-value').val($served_user[1]);
            }
            else {
              $('#edit-field-served-by-full-name-0-value').val('');
              $('#edit-field-served-by-first-name-0-value').val('');
              $('#edit-field-served-by-last-name-0-value').val('');
            }
          });

          // Default 'Employee' to 'Allocated to' (same user).

          $('#edit-field-allocated-to').change(function (e) {

            var $allocatedUser = $('#edit_field_allocated_to_chosen a span').text();
            var $allocatedUserId = $('#edit-field-allocated-to option').filter(':selected').val();

              $('div[id*="_subform_field_by"] a span').text($allocatedUser);
              $('select[id*="-subform-field-by"] option[value="' + $allocatedUserId + '"]').attr('selected', 'selected');
          });

          // Label overrides.

          $('#edit-field-client .ief-first-column-header').text('Client Name');
          $('.paragraphs-icon-button-collapse').val('Save');


          $(document).ajaxComplete(function () {

            // Trigger the Existing Address function.

           /* existingAddress($addressSelectedKey);
            $('.draggable .field--name-field-party-to-serve-addresses select').trigger('change');
            */
            // Label overrides.

            $('input[data-drupal-selector=edit-field-client-form-inline-entity-form-actions-ief-add-save]').val('Save');

            // Remove the node ID from autocomplete fields.

            $('body').find('.form-autocomplete').on('autocompleteclose', function (event, node) {
              var $val = jQuery(this).val();
              var $match = $val.match(/\((.*?)\)$/);
              if ($match) {
                $(this).data('real-value', $val);
                $(this).val($val.replace(' ' + $match[0], ''));
              }
            });
            $('body').find('.form-autocomplete').trigger('autocompleteclose');
            $('.field--name-field-agreed-discount input').keyup(function (e) {
              $('#edit-field-standard-fee-0-value').keyup();
            });

            // Client ID generation.

            var $icid = $('.field--name-field-client-id input', this).val();
            var $iclient = $('.field--name-title input', this).val();

            $('.field--name-title input').keydown(function (e) {
              if ($icid && e.keyCode != '9') {
                var $cid = '';
                $('.field--name-field-client-id input').val($cid);
              }
            });
            $('.field--name-title input').keyup(function (e) {
              if ($icid && e.keyCode != '9') {
                var $cid = $icid.replace(/[^0-9]/g, '');
                var $client = $('.field--name-title input').val().toUpperCase().replace(/ /g, '').substring(0, 3);
                $('.field--name-field-client-id input').val($client + $cid);
              }
            });
            $('.field--name-title input').ready(function (e) {
              if ($icid && e.keyCode != '9') {
                var $cid = $icid.replace(/[^0-9]/g, '');
                var $client = $('.field--name-title input').val().toUpperCase().replace(/ /g, '').substring(0, 3);
                $('.field--name-field-client-id input').val($client + $cid);
              }
            });
          });


          // Add the numbered option values and ordinal suffixes to the party
          // type menus.

          // Ordinal function.

          Number.prototype.ordinate = function () {
            var $num = this,
              $ones = $num % 10, //gets the last digit
              $tens = $num % 100, //gets the last two digits
              $ord = ['st', 'nd', 'rd'][$tens > 10 && $tens < 20 ? null : $ones - 1] || 'th';
            return $num.toString() + $ord;
          };


          // Add the suffixes to Parties Side Two.

          $(document).ajaxComplete(function () {
            $('#edit-field-party-2-wrapper .field--name-field-party-type-2 select').each(function (index) {
              var $opt = index + 1;
              $('option', this).each(function () {
                if ($(this).val() != '_none' && $(this).text().search(/^\d/) < 0) {
                  $(this).text(($opt).ordinate() + ' ' + $(this).text());
                }
              });
            });
          });

          // Add the suffixes to Parties Side One.

          $(document).ajaxComplete(function () {
            $('#edit-field-parties-wrapper .field--name-field-party-type select').each(function (index) {
              var $opt = index + 1;
              $('option', this).each(function () {
                if ($(this).val() != '_none' && $(this).text().search(/^\d/) < 0) {
                  $(this).text(($opt).ordinate() + ' ' + $(this).text());
                }
              });
            });
          });


          // Auto set the deadline date.

          if ($('#edit-field-lock-deadline-date-value').is(':checked')) {
            $('#edit-field-deadline-0-value-date').attr('disabled', 'disabled');
          }

          $('#edit-field-lock-deadline-date-value').change(function () {
            if (this.checked) {
              var $returnVal = confirm('Are you sure you wish to lock the Deadline date?');
              $(this).prop('checked', $returnVal);
              $('#edit-field-deadline-0-value-date').attr('disabled', 'disabled');
            }
            else {
              $('#edit-field-deadline-0-value-date').removeAttr('disabled', 'disabled');
            }
          });

          $('#edit-field-hearing-date-0-value-date').change(function () {

            if (($('#edit-field-hearing-date-0-value-date').val().length > 1) && (!$('#edit-field-lock-deadline-date-value').is(':checked'))) {

              var $docPrimary = [];

              $.each($('.field--name-field-primary-document .field__item'), function (index, value) {

                // Get the Primary Document if in view mode.

                $(this).each(function () {
                  if ($(this).text() == 'Yes') {
                    $docPrimary.push(index);
                  }
                });

              });

              $.each($('.field--name-field-primary-document input'), function (index, value) {

                // Get the Primary Document if in edit mode.

                $(this).each(function () {
                  if ($(this).is(':checked')) {
                    $docPrimary.push(index);
                  }
                });

              });

              var $docPrimary = $docPrimary.toString();

              if ($docPrimary.length > 0) {

                // Get the notice period from the primary document.

                if ($("input[name='field_documents["+$docPrimary+"][subform][field_override_notice_period][0][value]']").val()) {
                  var periodValue = $("input[name='field_documents["+$docPrimary+"][subform][field_override_notice_period][0][value]']").val();
                }
                else {
                  var periodValue = $("input[name='field_documents["+$docPrimary+"][subform][field_doc_notice_period][0][value]']").val();
                }

                var period = parseInt(periodValue);
                var hearing = moment($('#edit-field-hearing-date-0-value-date').val());

                // Subtract the notice period from the Hearing Date.

                var deadline = hearing.subtract(period, 'days').format('YYYY-MM-DD');
                // Set the Deadline date.

                $('#edit-field-deadline-0-value-date').val(deadline);
                $('#edit-field-deadline-0-value-date').trigger('change');
              }
            }
          });

          $('#edit-field-hearing-date-0-value-date').trigger('change');
          $(document).on('click', "input[name*='[subform][field_primary_document][value]']", function(){
            $('#edit-field-hearing-date-0-value-date').trigger('change');
          });

          $(document).ajaxComplete(function () {

            $('.field--name-field-notice-period input').keyup(function () {
              $('#edit-field-hearing-date-0-value-date').trigger('change');
            });

            $('#edit-field-hearing-date-0-value-date').trigger('change');

            $('.field--name-field-override-notice-period input').keyup(function () {
              $('#edit-field-hearing-date-0-value-date').trigger('change');
            });

          });

          // Hide the Service Details pane if no served date set.

          if ($('.field--name-field-date-service .field__item').length < 1) {
            $('#service-details-panel').hide();
          }

          // Make the job number read-only for Agent role.

          $('.page-user-type-agent #edit-title-0-value').prop('readonly', true);


          // Show Service Details group when Case Status is changed to Served.

          if ($('#edit-field-case-status').val() == 'Completed – Served') {
            $('#service-details').show();
          }

          $('#edit-field-case-status').on('change', function () {

            if (this.value == 'Completed – Served') {
              $('#service-details').show();
              $('#edit-field-para-address-0-subform-field-primary-address-wrapper').hide();
            }
            else {
              $('#service-details').hide();
            }
          });

          // Party to Serve populated select varaibles.

          var $partySelected = $('#edit-field-served-party-select').find(':selected').text();
          var $partySelectedKey = $('#edit-field-served-party-select').find(':selected').val();

          var $deliveredSelected = $('#edit-field-person-delivered-to-select').find(':selected').text();
          var $deliveredSelectedKey = $('#edit-field-person-delivered-to-select').find(':selected').val();

          var $businessSelected = $('#edit-field-business-name-select').find(':selected').text();
          var $businessSelectedKey = $('#edit-field-business-name-select').find(':selected').val();

          // Populate the Party to Serve select field
          // option values with the Party name field values.

          $('#edit-field-served-party-select, #edit-field-served-party-other').change(function () {

            /*var $partyID = [];
            var $partyNames = [];

            $('#edit-field-party-2-wrapper .field--name-field-name').each(function (index) {
              $first_name =  $('[data-drupal-selector="edit-field-party-2-' + index + '-subform-field-name-0-value"]').val();
              $last_name =  $('[data-drupal-selector="edit-field-party-2-' + index + '-subform-field-last-name-0-value"]').val();

              $partyNames.push($first_name + " " + $last_name );

            });

            $('#edit-field-parties-wrapper .field--name-field-name').each(function (index) {
              
              $first_name =  $('[data-drupal-selector="edit-field-parties-' + index + '-subform-field-name-0-value"]').val();
              $last_name =  $('[data-drupal-selector="edit-field-parties-' + index + '-subform-field-last-name-0-value"]').val();

              $partyNames.push($first_name + " " + $last_name );
            });
           

            // Store the newly selected option as a variable.

            var $newPartySelectedKey = $('#edit-field-served-party-select').find(':selected').val();

            // Remove the Party Served select menu options.

            $('#edit-field-served-party-select').empty();

            // Add parties to Party Served select menu.

            $('#edit-field-served-party-select').append($('<option>', { value: '' }).text('- None -'));

            $.each($partyNames, function (key, value) {
              if (value.length > 1 && value != $partySelectedKey) {
                $('#edit-field-served-party-select').append($('<option>', { value: value }).text(value));
              }
            });

            // Add back the Other option and the selected option attribute.

            if ($partySelectedKey.length > 1 && $partySelectedKey != 'select_or_other') {
              $('#edit-field-served-party-select').append($('<option>', { value: $partySelectedKey }).text($partySelected));
            }

            $('#edit-field-served-party-select').append($('<option>', { value: 'select_or_other' }).text('Other'));

            // Select the selected option that was selected while selecting
            // options.

            if (($newPartySelectedKey.length > 0) || ($newPartySelectedKey == 'select_or_other')) {
              $('#edit-field-served-party-select option[value="' + $newPartySelectedKey + '"]').attr('selected', 'selected');
            }*/

            var $partySelectedText = $('#edit-field-served-party-select option:selected').text();

            if ($partySelectedText == 'Other') {
              var $partySelectedText = $('#edit-field-served-party-other').val();
              if ($partySelectedText !== "") {
                $('#edit-field-name-to-other-0-value').val($partySelectedText);
              }
            }

            $('#edit-field-name-to-server-value-0-value').val($partySelectedText);
            $('#edit-field-person-delivered-to-select').trigger('change');
            $('#edit-field-business-name-select').trigger('change');

          }).trigger('change', [$partySelected, $partySelectedKey]);

          // Populate the Person Delivered To select field
          // option values with the Party name field values.

          $('#edit-field-person-delivered-to-select, #edit-field-person-delivered-to-other').change(function () {

            /*var $partyID = [];
            var $partyNames = [];

            $('#edit-field-party-2-wrapper .field--name-field-name').each(function (index) {
              $first_name =  $('[data-drupal-selector="edit-field-party-2-' + index + '-subform-field-name-0-value"]').val();
              $last_name =  $('[data-drupal-selector="edit-field-party-2-' + index + '-subform-field-last-name-0-value"]').val();

              $partyNames.push($first_name + " " + $last_name );

            });

            $('#edit-field-parties-wrapper .field--name-field-name').each(function (index) {
              
              $first_name =  $('[data-drupal-selector="edit-field-parties-' + index + '-subform-field-name-0-value"]').val();
              $last_name =  $('[data-drupal-selector="edit-field-parties-' + index + '-subform-field-last-name-0-value"]').val();

              $partyNames.push($first_name + " " + $last_name );
            });

            // Store the newly selected option as a variable.

            var $newDeliveredSelectedKey = $('#edit-field-person-delivered-to-select').find(':selected').val();

            // Remove the Delivered To select menu options.

            $('#edit-field-person-delivered-to-select').empty();

            $('#edit-field-person-delivered-to-select').append($('<option>', { value: '' }).text('- None -'));


            // Add parties to Delivered To select menu.

            if ($('#edit-field-person-delivered-to-other').val().length > 0) {
              var $deliveredServer = $('#edit-field-person-delivered-to-other').val();
            }
            else if ($('#edit-field-person-delivered-value-0-value').val().length > 0) {
              var $deliveredServer = $('#edit-field-person-delivered-value-0-value').val();
            }

            $.each($partyNames, function (key, value) {
              if (value.length > 1 && value != $deliveredSelectedKey) {
                $('#edit-field-person-delivered-to-select').append($('<option>', { value: value }).text(value));
              }
            });

            // Add back the Other option and the selected option attribute.

            if ($deliveredSelectedKey.length > 1 && $deliveredSelectedKey != 'select_or_other') {
              $('#edit-field-person-delivered-to-select').append($('<option>', { value: $deliveredSelectedKey }).text($deliveredSelected));
            }

            $('#edit-field-person-delivered-to-select').append($('<option>', { value: 'select_or_other' }).text('Other'));

            // Select the selected option that was selected while selecting.
            // options.

            if (($newDeliveredSelectedKey.length > 0) || ($newDeliveredSelectedKey == 'select_or_other')) {
              $('#edit-field-person-delivered-to-select option[value="' + $newDeliveredSelectedKey + '"]').attr('selected', 'selected');

            }*/

            var $deliveredSelectedText = $('#edit-field-person-delivered-to-select option:selected').text();

            if ($deliveredSelectedText == 'Other') {
              var $deliveredSelectedText = $('#edit-field-person-delivered-to-other').val();
              if ($deliveredSelectedText !== "") {
                $('#edit-field-person-delivered-to-other-0-value').val($deliveredSelectedText);
              }
            }
            if ($deliveredSelectedText !== '- None -') {
              var $deliveredText = $deliveredSelectedText.split(" ");
              $('#edit-field-person-delivered-value-0-value').val($deliveredSelectedText);
              $('#edit-field-person-delivered-to-fname-0-value').val($deliveredText[0]);
              $('#edit-field-person-delivered-to-lname-0-value').val($deliveredText[1]);
            }

          }).trigger('change', [$deliveredSelected, $deliveredSelectedKey]);

          // Populate the Business Name select field
          // option values with the Party name field values.

          $('#edit-field-business-name-select, #edit-field-business-name-other').change(function () {
            /*var $partyID = [];

            $('#edit-field-parties-wrapper .field--name-field-name').each(function (index) {
              $partyID.push('[data-drupal-selector="edit-field-parties-' + index + '-subform-field-name-0-value"]');
            });

            $('#edit-field-party-2-wrapper .field--name-field-name').each(function (index) {
              $partyID.push('[data-drupal-selector="edit-field-party-2-' + index + '-subform-field-name-0-value"]');
            });

            var $partyNames = [];

            $.each($partyID, function (index, value) {
              $partyNames.push($(value).val());
            });

            // Store the newly selected option as a variable.

            var $newBusinessSelectedKey = $('#edit-field-business-name-select').find(':selected').val();

            // Remove the Business Name select menu options.

            $('#edit-field-business-name-select').empty();

            $('#edit-field-business-name-select').append($('<option>', { value: '' }).text('- None -'));

            // Add parties to Business Name To select menu.

            if ($('#edit-field-business-name-other').val().length > 0) {
              var $businessServer = $('#edit-field-business-name-other').val();
            }
            else if ($('#edit-field-business-name-value-0-value').val().length > 0) {
              var $businessServer = $('#edit-field-business-name-value-0-value').val();
            }

            $.each($partyNames, function (key, value) {
              if (value.length > 1 && value != $businessSelectedKey) {
                $('#edit-field-business-name-select').append($('<option>', { value: value }).text(value));
              }
            });

            // Add back the Other option and the selected option attribute.

            if ($businessSelectedKey.length > 1 && $businessSelectedKey != 'select_or_other') {
              $('#edit-field-business-name-select').append($('<option>', { value: $businessSelectedKey }).text($businessSelected));
            }

            $('#edit-field-business-name-select').append($('<option>', { value: 'select_or_other' }).text('Other'));

            // Select the selected option that was selected while selecting
            // options.

            if (($newBusinessSelectedKey.length > 0) || ($newBusinessSelectedKey == 'select_or_other')) {
              $('#edit-field-business-name-select option[value="' + $newBusinessSelectedKey + '"]').attr('selected', 'selected');
            }*/

            var $businessSelectedText = $('#edit-field-business-name-select option:selected').text();

            if ($businessSelectedText == 'Other') {
              var $businessSelectedText = $('#edit-field-business-name-other').val();
              if ($businessSelectedText !== "") {
                $('#edit-field-business-other-0-value').val($businessSelectedText);
              }
            }

            $('#edit-field-business-name-value-0-value').val($businessSelectedText);


          }).trigger('change', [$businessSelected, $businessSelectedKey]);

          // Update the Party to Serve populated selects when the party names change.

          $('#parties-panel').on('keyup', '.form-text', function () {
            $('#edit-field-served-party-select').trigger('change');
            $('#edit-field-business-name-select').trigger('change');
            $('#edit-field-person-delivered-to-select').trigger('change');
          });

          // Populate the values with the selected Party name.

          $('.field--name-field-name  .field__item').each(function (key, value) {
            if (key == parseInt($('.field--edit-field-person-delivered-to-select .field__item').html())) {
              $('.field--name-field-served-party .field__item').html(value);
            }
          });

          // Update the existing address select
          // options when the Party to Serve addresses change.

          /*$('.field--name-field-service-address').on('keyup', '.form-text', function () {
            $('.draggable .field--name-field-party-to-serve-addresses select').trigger('change');
          });*/

          var $addressSelectedKey = '';

          // Function to allow populating new address field values with previously entered address values.

          /*function existingAddress($addressSelectedKey) {
            $('.draggable').on('change focus', '.field--name-field-party-to-serve-addresses select', function (key, value) {

              var $addressLine1 = [];
              var $addressLine2 = [];
              var $addressLocality = [];
              var $addressState = [];
              var $addressCompany = [];
              var $addressPostCode = [];

              // Get the list of added addresses.

              $('.party-served-details .field--name-field-address').each(function (index) {
                $addressLine1.push('[data-drupal-selector="edit-field-service-address-' + index + '-subform-field-address-0-address-address-line1"]');
                $addressLine2.push('[data-drupal-selector="edit-field-service-address-' + index + '-subform-field-address-0-address-address-line2"]');
                $addressLocality.push('[data-drupal-selector="edit-field-service-address-' + index + '-subform-field-address-0-address-locality"]');
                $addressState.push('[data-drupal-selector="edit-field-service-address-' + index + '-subform-field-address-0-address-administrative-area"]');
                $addressCompany.push('[data-drupal-selector="edit-field-service-address-' + index + '-subform-field-address-0-address-organization"]');
                $addressPostCode.push('[data-drupal-selector="edit-field-service-address-' + index + '-subform-field-address-0-address-postal-code"]');
              });

              var $dataSelector = $(this).parent().parent().parent().attr('data-drupal-selector');
              var $addressSelectedKey = $('div[data-drupal-selector="' + $dataSelector + '"] .field--name-field-selected-address .form-item .form-text').val();

              // Store the newly selected option.

              var $newAddressSelectedKey = $(this).find(':selected').val();

              if ($newAddressSelectedKey == '_none') {
                $newAddressSelectedKey = $addressSelectedKey;
              }
              else if ($newAddressSelectedKey.length < 1) {
                $addressSelectedKey = '';
              }

              var $addressLine1Value = [];
              var $addressLine2Value = [];
              var $addressLocalityValue = [];
              var $addressStateValue = [];
              var $addressCompanyValue = [];
              var $addressPostCodeValue = [];
              var $addressIndex = 0;
              $.each($addressLine1, function (index, value) {
                $addressLine1Value.push($(value).val());
                if ($newAddressSelectedKey == $(value).val()) {
                  $addressIndex = $addressIndex + index;
                }
              });

              $.each($addressLine2, function (index, value) {
                $addressLine2Value.push($(value).val());
              });

              $.each($addressLocality, function (index, value) {
                $addressLocalityValue.push($(value).val());
              });

              $.each($addressState, function (index, value) {
                $addressStateValue.push($(value).val());
              });

              $.each($addressCompany, function (index, value) {
                $addressCompanyValue.push($(value).val());
              });

              $.each($addressPostCode, function (index, value) {
                $addressPostCodeValue.push($(value).val());
              });

              // Remove the Existing Address select menu options.

              $(this).empty();

              $(this).append($('<option>', { value: '' }).text('- None -'));

              // Add addresses to the Existing Address select menu.

              var $selector = $(this);

              $.each($addressLine1Value, function (key, value) {
                if (value.length > 1) {
                  $($selector).append($('<option>', { value: value }).text(value));
                }
              });

              // Auto populate the address fields with the selected address values.

              if ($newAddressSelectedKey.length > 0) {

                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .field--name-field-selected-address input').val($newAddressSelectedKey);
                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .address-line1').val($addressLine1Value[$addressIndex]);
                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .address-line2').val($addressLine2Value[$addressIndex]);
                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .locality').val($addressLocalityValue[$addressIndex]);
                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .organization').val($addressCompanyValue[$addressIndex]);
                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .administrative-area option[value="' + $addressStateValue[$addressIndex] + '"]').attr('selected', 'selected');
                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .administrative-area').val($addressStateValue[$addressIndex]);
                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .postal-code').val($addressPostCodeValue[$addressIndex]);
              }
              else {
                $('.draggable div[data-drupal-selector="' + $dataSelector + '"] .field--name-field-selected-address input').val($newAddressSelectedKey);

              }

              // Add back the selected option attribute.

              $('option[value="' + $newAddressSelectedKey + '"]', this).attr('selected', 'selected');

            }).trigger('change', [$addressSelectedKey]);
          }
          existingAddress($addressSelectedKey);*/

          // $('.draggable .field--name-field-party-to-serve-addresses select').trigger('change');

          // Do some things before submitting the form.

          $('#edit-submit').click(function (e) {

            // Clear the Party to Serve address select.

            // $('.field--name-field-party-to-serve-addresses select').val('');

            // Clear Service Details if Case Status not equal to Completed -- Served.

            var $caseStatus = $('#edit-field-case-status').find(':selected').val();
            if ($caseStatus != 'Completed – Served') {
              $('.field-group-fieldset.service-details textarea').val('');
              $('.field-group-fieldset.service-details input').val('');
              $('.field-group-fieldset.service-details select option:selected').prop('selected', false);
              $('.field-group-fieldset.service-details select.country').val('AU');
            }
          });

          $(document).trigger('ajaxComplete');
        }

        // Job Page View functions.

        if ($('.page-node-type-node-view').length > 0) {

          // Hide/Show sections on the Job View page.

          if ($('.fieldset--party-details .field__item .paragraph .field__item').length > 0) {
            $('.fieldset--party-details').show();
          }

          if ($('.fieldset--party-to-serve .serve-name-text .field__item').text() != '- None -') {
            $('.fieldset--party-to-serve').show();
          }

          if ($('.fieldset--job-status .status-case-status-value .field__item').text() === 'Completed – Served') {
            $('.fieldset--service-details').show();
          }

          if ($('.fieldset--attendance-notes .attendance-employee-ref .field__item').text().length > 0) {
            $('.fieldset--attendance-notes').show();
          }

          // Hide/Show values on the Job View page.

          var $deliveredTo = $('.field--name-field-person-delivered-value .field__item').text();

          if ($deliveredTo == '- None -') {
            $('.field--name-field-person-delivered-value').hide();
          }

          var $businessName = $('.field--name-field-business-name-value .field__item').text();

          if ($businessName == '- None -') {
            $('.field--name-field-business-name-value').hide();
          }

          var $nameServed = $('.field--name-field-name-to-server-value .field__item').text();
          if ($nameServed == '- None -') {
            $('.field--name-field-name-to-server-value').hide();
          }

          // Add the ordinal suffixes to the party types on the job view page.

          $.each($('.party-side-one-type-select .field__item'), function (index, value) {
            index = index + 1;
            $(this).each(function () {
              var $j = index % 10;
              if ($j == 1 && index != 11) {
                return $(this).html(index + 'st ' + $(this).html());
              }
              if ($j == 2 && index != 12) {
                return $(this).html(index + 'nd ' + $(this).html());
              }
              if ($j == 3 && index != 13) {
                return $(this).html(index + 'rd ' + $(this).html());
              }
              return $(this).html(index + 'th ' + $(this).html());
            });
          });

          $.each($('.party-side-two-type-select .field__item'), function (index, value) {
            index = index + 1;
            $(this).each(function () {
              var $j = index % 10;
              if ($j == 1 && index != 11) {
                return $(this).html(index + 'st ' + $(this).html());
              }
              if ($j == 2 && index != 12) {
                return $(this).html(index + 'nd ' + $(this).html());
              }
              if ($j == 3 && index != 13) {
                return $(this).html(index + 'rd ' + $(this).html());
              }
              return $(this).html(index + 'th ' + $(this).html());
            });
          });
        }

        if ($('.page-node-type-node-view .field--name-field-affidavits').length > 0) {

          // Document token replacement.

          var $d = $('.field--name-field-affidavits');
          var $fields = new Array('client-rendered-entity',
            'client-job-ref-text',
            'client-job-email',
            'client-standard-fee-number',
            'client-agreed-fee-number',
            'client-id-text',
            'client-address',
            'client-phone',
            'client-generic-email',
            'client-agreed-discount-number',
            'client-notes-textarea',
            'court-court-text',
            'court-case-no-text',
            'court-division-text',
            'court-list-text',
            'court-registry-text',
            'court-hearing-date',
            'party-side-one-name-text',
            'party-side-two-name-text',
            'party-side-one-type-select',
            'party-side-two-type-select',
            'party-side-one-rendered-entity',
            'party-side-two-rendered-entity',
            'serve-name-text',
            'serve-phone',
            'serve-address',
            'serve-notes-textarea',
            'status-case-status-select',
            'status-case-status-value',
            'status-admin-status-select',
            'status-send-to-client-date',
            'status-invoiced-date',
            'status-email-update-date',
            'status-cover-letter-date',
            'status-affidavit-date',
            'status-job-type-select',
            'status-instructions-date',
            'status-allocation-date',
            'status-allocated-to-ref',
            'status-deadline-date',
            'service-served-by-ref',
            'service-served-date',
            'service-address-rendered-entity',
            'service-method-select',
            'service-person-delivered-select',
            'service-relationship-or-position-text',
            'service-business-name-select',
            'service-additional-notes-textarea',
            'attendance-notes-rendered-entity',
            'attendance-employee-ref',
            'attendance-date',
            'attendance-address-rendered-entity',
            'attendance-notes-textarea',
            'address',
            'document-dated-issued-select',
            'document-date',
            'document-individuals-name-text',
            'document-amount-number',
            'document-notice-period-number',
            'document-document-rendered-entity',
            'document-document-affidavit-ref',
            'document-document-notice-period-number');

          var $today = moment().format('D/MM/YYYY');
          $d.html($d.html().trim().replace(/\[FIELD-TODAY\]/i, $today));
          $.each($fields, function (index, value) {
            $d.html($d.html().trim().replace('\[FIELD-' + value.toUpperCase() + '\]', $('.' + value + ' .field__item').html()));
          });

          // Document Print and Preview buttons.

          $('#print-button').click(function () {
            $('#reports-text').show();
            $('#reports-text').print();
            $('#reports-text').hide();
          });
          $('#preview-button').click(function () {
            $('#reports-text').show();
          });
        }
      });
    }
  }

})(jQuery, Drupal, once);
