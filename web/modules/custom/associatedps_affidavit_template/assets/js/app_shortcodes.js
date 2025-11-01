/**
* Inserts selected shortcode into CKEditor 5 body field at the cursor.
*/
(function (Drupal, drupalSettings, once) {
    Drupal.behaviors.appShortcodes = {
        attach(context) {
            console.log("attach");
            const settings = drupalSettings.associatedpsShortcodes || null;
            if (!settings) {
                console.log("!settings");
                return;
            }

            // Bind once per form.
            once('associatedps-shortcode-ui', '.node-template-form, .node-template-edit-form', context).forEach((ui) => {
                console.log("once");
                const select = ui.querySelector('select[name="field_short_codes"]');
                // const insertBtn = ui.querySelector('.associatedps-shortcode-insert');
                if (!select ){//|| !insertBtn) {
                    console.log("!select");
                    return;
                }

                select.addEventListener('change', (e) => {
                    console.log("change");
                    e.preventDefault();
                    const field = select.value;
                    if (!field || field == 0) {
                        console.log("!field");
                        return;
                    }

                    const code = settings.shortcodeMap?.[field] || `[${field}]`;
                    console.log("code", code);
                    const container = document.querySelector(settings.targetContainerSelector);
                    if (!container) {
                        console.log("!container");
                        return;
                    }

                    // Find the textarea for the text_format inside the wrapper.
                    // There should be exactly one in your subform wrapper.
                    const textarea = container.querySelector('textarea');
                    if (!textarea) {
                        console.log("!textarea");
                        return;
                    }
                    console.log(textarea);
                    // Try Drupal's CKEditor 5 registry first.
                    // let editor1 = document.querySelector('[data-drupal-selector="edit-template-editor-value"]');
                    // if (window.Drupal && Drupal.CKEditor5Instances && Drupal.CKEditor5Instances.get) {
                    //     console.log("CKEditor5Instances");
                    //     editor = Drupal.CKEditor5Instances.get(textarea);
                    // }
                    let editor = null;
                    if (typeof CKEDITOR !== 'undefined' && CKEDITOR.instances[textarea.getAttribute('id')]) {
                        editor = CKEDITOR.instances[textarea.getAttribute('id')];
                        console.log('✅ CKEditor editor');
                    }

                    console.log(editor);
                    if (editor) {
                        console.log("editor");
                        editor.focus();
                        editor.insertText(code);
                    } else {
                        console.log("!editor");
                        // Fallback: plain textarea insertion at caret.
                        const start = textarea.selectionStart || textarea.value.length;
                        const end = textarea.selectionEnd || textarea.value.length;
                        const before = textarea.value.slice(0, start);
                        const after = textarea.value.slice(end);
                        textarea.value = before + code + after;
                        textarea.selectionStart = textarea.selectionEnd = start + code.length;
                        textarea.focus();
                    }
                });
            });
        },
    };
})(Drupal, drupalSettings, once);