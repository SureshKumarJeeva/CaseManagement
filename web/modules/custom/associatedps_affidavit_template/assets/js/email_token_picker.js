/**
 * @file
 * Available Tokens picker for the Library email-template form.
 *
 * The picker is a Chosen <select> whose option VALUES are the [node:...] email
 * tokens and whose option LABELS are the readable form-display paths. Selecting
 * a token copies its value to the clipboard so the author can paste it into the
 * email body; the resolver then reads that token straight out of the body at
 * send time. Same working methodology as the DocuSign token picker — the value
 * format is the only difference and it is already baked into the option values
 * server-side (EmailTemplateTokenMapper).
 */
(function ($, Drupal, once) {
  'use strict';

  /**
   * Copy text to the clipboard, with a fallback for non-secure contexts.
   *
   * navigator.clipboard is unavailable over plain http (e.g. staging), so fall
   * back to a hidden textarea + execCommand('copy'). Returns a Promise.
   */
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(function () {
        return fallbackCopy(text);
      });
    }
    return fallbackCopy(text);
  }

  function fallbackCopy(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    var ok = false;
    try {
      ok = document.execCommand('copy');
    }
    catch (e) {
      ok = false;
    }
    document.body.removeChild(textarea);
    return ok ? Promise.resolve() : Promise.reject();
  }

  /**
   * Briefly show a corner toast confirming (or failing) the copy.
   */
  function showToast(message, isError) {
    var toast = document.createElement('div');
    toast.className = 'aps-token-toast' + (isError ? ' aps-token-toast--error' : '');
    toast.textContent = message;
    document.body.appendChild(toast);
    window.requestAnimationFrame(function () {
      toast.classList.add('is-visible');
    });
    window.setTimeout(function () {
      toast.classList.remove('is-visible');
      window.setTimeout(function () {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
      }, 300);
    }, 2000);
  }

  Drupal.behaviors.apsEmailTokenPicker = {
    attach: function (context) {
      // Binds to BOTH the Job "Available Tokens" picker and the Online
      // Instruction picker via the shared .js-aps-token-picker class.
      once('aps-token-picker', '.js-aps-token-picker', context).forEach(function (select) {
        var $select = $(select);

        $select.on('change.apsToken', function () {
          var token = $select.val();
          if (!token) {
            return;
          }

          copyText(token).then(function () {
            showToast('Copied: ' + token, false);
          }).catch(function () {
            showToast('Could not copy — token is: ' + token, true);
          });

          // Reset to the placeholder so the same token can be picked again and
          // the box never holds a stale selection. Programmatic val() does not
          // re-fire change, so this cannot loop.
          $select.val('');
          if ($select.next('.chosen-container').length) {
            $select.trigger('chosen:updated');
          }
        });
      });
    }
  };

})(jQuery, Drupal, once);
