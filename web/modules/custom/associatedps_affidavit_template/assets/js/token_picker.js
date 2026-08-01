/**
 * @file
 * ApsTokenPicker — reusable searchable token dropdown with click-to-copy.
 *
 * This utility is shared between the PandaDoc template editor page
 * (pandadoc_editor.js) and the Library form email token picker
 * (pandadoc_library_form.js).
 *
 * It renders a search input with a filterable dropdown list of tokens.
 * Users can navigate with arrow keys, select with Enter or click,
 * and the selected token is copied to the clipboard.
 *
 * Usage:
 *   var picker = window.ApsTokenPicker.init(containerElement, tokenArray, options);
 *   picker.destroy(); // remove the picker from the DOM
 *
 * Options:
 *   label     - Label text above the search input (default: 'Search & click to copy token')
 *   idPrefix  - Prefix for generated element IDs to avoid collisions (default: 'aps-token')
 */
(function () {
  'use strict';

  /**
   * Initialize a token picker inside the given container element.
   *
   * Builds the search input, dropdown list, and "Copied!" indicator,
   * then wires up all event listeners for search, keyboard navigation,
   * click selection, and clipboard copy.
   *
   * @param {HTMLElement} containerEl - The DOM element to insert the picker into.
   * @param {(string|{label: string, value: string})[]} tokens - Tokens to list.
   *   A plain string is shown and copied as-is. An object lets the displayed
   *   label differ from the copied value — the DocuSign editor uses this to
   *   show a readable "Tab-Section-Field" path while still copying the raw
   *   token that DocuSign matches on.
   * @param {Object} [options] - Optional configuration.
   * @param {string} [options.label] - Label text shown above the search input.
   * @param {string} [options.idPrefix] - ID prefix to avoid collisions when multiple pickers exist.
   * @returns {Object|undefined} Handle with a destroy() method, or undefined if inputs are invalid.
   */
  function init(containerEl, tokens, options) {
    if (!containerEl || !tokens || !tokens.length) {
      return;
    }

    // Normalize to {label, value} so the rest of the picker has one shape to
    // deal with. Strings map to label === value (the original behaviour).
    var items = tokens.map(function (t) {
      if (typeof t === 'string') {
        return { label: t, value: t };
      }
      var value = (t && t.value != null) ? t.value : t.label;
      var label = (t && t.label != null) ? t.label : value;
      return { label: String(label), value: String(value) };
    });

    var opts = options || {};
    var label = opts.label || 'Search & click to copy token';
    var prefix = opts.idPrefix || 'aps-token';

    // Create the picker panel container.
    var panel = document.createElement('div');
    panel.className = prefix + '-panel';
    panel.style.cssText = 'margin-bottom:10px; padding:10px; background:#f5f5f5; border:1px solid #ccc; border-radius:4px; position:relative; z-index:100;';

    // Generate unique IDs using timestamp to prevent collisions
    // when multiple pickers are on the same page.
    var searchId = prefix + '-search-' + Date.now();
    var listId = prefix + '-list-' + Date.now();
    var copiedId = prefix + '-copied-' + Date.now();

    // Build the picker HTML: label, search input, "Copied!" span, and dropdown list.
    panel.innerHTML =
      '<label for="' + searchId + '" style="font-weight:bold; display:block; margin-bottom:4px;">' + escapeHtml(label) + '</label>' +
      '<input id="' + searchId + '" type="text" placeholder="Type to search tokens\u2026" autocomplete="off" ' +
      'style="width:100%; max-width:460px; padding:8px 10px; font-size:14px; border:1px solid #aaa; border-radius:4px; box-sizing:border-box;" />' +
      '<span id="' + copiedId + '" style="margin-left:10px; color:#28a745; font-weight:bold; display:none;">Copied!</span>' +
      '<ul id="' + listId + '" style="list-style:none; margin:4px 0 0; padding:0; max-height:240px; overflow-y:auto; ' +
      'border:1px solid #ccc; border-radius:4px; background:#fff; display:none; position:absolute; width:100%; max-width:460px; box-shadow:0 4px 8px rgba(0,0,0,0.12);"></ul>';

    // Insert the panel as the first child of the container.
    containerEl.insertBefore(panel, containerEl.firstChild);

    var searchInput = document.getElementById(searchId);
    var listEl = document.getElementById(listId);
    var copiedEl = document.getElementById(copiedId);

    // Tracks the currently highlighted item index for keyboard navigation.
    var activeIndex = -1;

    /**
     * Render the dropdown list, filtered by the search query.
     *
     * Filters the token array by case-insensitive substring match,
     * highlights the matching portion in each result, and displays
     * the list. Resets the keyboard navigation index.
     *
     * @param {string} filter - The current search input value.
     */
    function renderList(filter) {
      var query = (filter || '').toLowerCase();

      // Filter on the visible label AND the underlying value, so searching
      // still works after a copy puts the raw token in the search box.
      var matches = query
        ? items.filter(function (it) {
            return it.label.toLowerCase().indexOf(query) !== -1 ||
              it.value.toLowerCase().indexOf(query) !== -1;
          })
        : items;

      // Reset keyboard navigation on every re-render.
      activeIndex = -1;

      if (matches.length === 0) {
        listEl.innerHTML = '<li style="padding:8px 12px; color:#888; font-style:italic;">No matching tokens</li>';
        listEl.style.display = 'block';
        return;
      }

      // Build list items with the matching substring highlighted in bold.
      // Escape first, then highlight, so field labels can never inject markup.
      listEl.innerHTML = matches.map(function (it, i) {
        var display = escapeHtml(it.label);
        if (query) {
          // Escape regex special chars in query before using in RegExp.
          var safeQuery = escapeHtml(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          display = display.replace(new RegExp('(' + safeQuery + ')', 'gi'), '<strong style="color:#0071b8;">$1</strong>');
        }
        return '<li data-token="' + escapeHtml(it.value) + '" data-label="' + escapeHtml(it.label) + '" data-index="' + i + '" ' +
          'style="padding:8px 12px; cursor:pointer; font-family:monospace; font-size:13px; border-bottom:1px solid #eee;">' +
          display + '</li>';
      }).join('');

      listEl.style.display = 'block';
    }

    /**
     * Highlight a specific item in the dropdown by index.
     *
     * Clears the background of all items, then sets the active
     * item's background and scrolls it into view.
     *
     * @param {NodeList} items - The list of <li> elements with data-token.
     * @param {number} idx - The index to highlight.
     */
    function highlightItem(items, idx) {
      // Clear all highlights.
      for (var i = 0; i < items.length; i++) {
        items[i].style.background = '';
      }
      if (idx >= 0 && idx < items.length) {
        items[idx].style.background = '#e8f0fe';
        // Scroll the highlighted item into the visible area of the dropdown.
        items[idx].scrollIntoView({ block: 'nearest' });
      }
    }

    /**
     * Copy the selected token text to the clipboard.
     *
     * Uses the modern Clipboard API when available, with a fallback
     * to a temporary textarea + execCommand('copy') for older browsers
     * or non-HTTPS contexts. Shows a brief "Copied!" indicator and
     * puts the readable label in the search input — only the value goes to
     * the clipboard, so the box stays legible while the paste is the token.
     *
     * @param {string} tokenText - The token string to copy.
     * @param {string} [displayText] - Text to show in the input; defaults to
     *   the copied value (the original behaviour for plain string tokens).
     */
    function copyToken(tokenText, displayText) {
      // Modern Clipboard API (requires HTTPS or localhost).
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(tokenText);
      } else {
        // Fallback: create a temporary textarea, select its content, and copy.
        var temp = document.createElement('textarea');
        temp.value = tokenText;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
      }

      // Show the "Copied!" indicator next to the search input.
      copiedEl.style.display = 'inline';

      // Show the label (not the raw token) as visual confirmation.
      searchInput.value = (displayText != null) ? displayText : tokenText;

      // Close the dropdown after selection.
      listEl.style.display = 'none';

      // Hide the "Copied!" indicator after 1.5 seconds.
      setTimeout(function () { copiedEl.style.display = 'none'; }, 1500);
    }

    // --- Event Listeners ---

    // Show the full list when the search input receives focus.
    searchInput.addEventListener('focus', function () {
      renderList(searchInput.value);
    });

    // Re-filter the list on every keystroke in the search input.
    searchInput.addEventListener('input', function () {
      renderList(searchInput.value);
    });

    // Handle keyboard navigation: ArrowDown, ArrowUp, Enter, Escape.
    searchInput.addEventListener('keydown', function (e) {
      var items = listEl.querySelectorAll('li[data-token]');
      if (!items.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        // Move highlight down, clamped to last item.
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        highlightItem(items, activeIndex);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        // Move highlight up, clamped to first item.
        activeIndex = Math.max(activeIndex - 1, 0);
        highlightItem(items, activeIndex);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        // Copy the currently highlighted token.
        if (activeIndex >= 0 && items[activeIndex]) {
          copyToken(items[activeIndex].dataset.token, items[activeIndex].dataset.label);
        }
      } else if (e.key === 'Escape') {
        // Close the dropdown without selecting.
        listEl.style.display = 'none';
      }
    });

    // Handle click selection on a dropdown item.
    listEl.addEventListener('click', function (e) {
      // Find the closest <li> with a data-token attribute (handles clicks on <strong> children).
      var li = e.target.closest('li[data-token]');
      if (li) {
        copyToken(li.dataset.token, li.dataset.label);
      }
    });

    // Close the dropdown when clicking anywhere outside the search input or list.
    document.addEventListener('click', function (e) {
      if (!searchInput.contains(e.target) && !listEl.contains(e.target)) {
        listEl.style.display = 'none';
      }
    });

    // Return a handle so callers can remove the picker from the DOM.
    return {
      destroy: function () {
        if (panel.parentNode) {
          panel.parentNode.removeChild(panel);
        }
      }
    };
  }

  /**
   * Escape special HTML characters in a string to prevent XSS
   * when inserting user-provided text into innerHTML.
   *
   * @param {string} str - The raw string to escape.
   * @returns {string} The HTML-safe string.
   */
  function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // Expose on the global window object so both regular scripts
  // and ES modules can access it.
  window.ApsTokenPicker = { init: init };

})();
