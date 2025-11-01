(function ($, Drupal, once) {
    Drupal.behaviors.fileUploadSuccess = {
        attach: function (context, settings) {
            console.log('JS loaded');
            once('fileUploadSuccess', 'body', context).forEach(() => {

                // Listen to ALL Drupal AJAX success events.
                $(document).on('ajaxSuccess', function (event, xhr, ajaxSettings) {
                    if(ajaxSettings.extraData && ajaxSettings.extraData._triggering_element_name){
                        const trigger = ajaxSettings.extraData._triggering_element_name;
                        if(trigger.includes('file_upload')){ //check if the ajax was triggered through file upload process
                            console.log('Drupal ajaxSuccess event fired new');
                            try{
                                const fid =  $('input[name="field_file_upload[0][fids]"]').val();
                                if(fid){ //call to server function with fid
                                    $.ajax({
                                        url: '/associatedps_affidavit_template/convert',
                                        method: "POST",
                                        data: {fid:fid},
                                        success: 
                                           function (res){
                                                console.log("reached");
                                                const editor = document.querySelector('[data-drupal-selector="edit-field-template-editor-0-value"]');
                                                console.log(editor);
                                                if (typeof CKEDITOR !== 'undefined' && CKEDITOR.instances[editor.getAttribute('id')]) {
                                                    CKEDITOR.instances[editor.getAttribute('id')].setData(res.html);
                                                    console.log('✅ CKEditor4 updated via setData');
                                                    return;
                                                }
                                            }
                                    });
                                }else{
                                    console.error('fid not defined', fid);
                                }
                            }catch(e){
                                console.error('Exception', e);
                            }
                        }
                    }
                });

                // OR listen only for Drupal's internal AJAX system.
                $(document).on('drupalAjaxSuccess', function (event, xhr, ajaxSettings) {
                    console.log('drupalAjaxSuccess event fired');
                });
            });
        }
    };

})(jQuery, Drupal, once);