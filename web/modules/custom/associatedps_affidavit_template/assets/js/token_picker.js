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
   * @param {string[]} tokens - Array of token strings to display in the dropdown.
   * @param {Object} [options] - Optional configuration.
   * @param {string} [options.label] - Label text shown above the search input.
   * @param {string} [options.idPrefix] - ID prefix to avoid collisions when multiple pickers exist.
   * @returns {Object|undefined} Handle with a destroy() method, or undefined if inputs are invalid.
   */
  function init(containerEl, tokens, options) {
    if (!containerEl || !tokens || !tokens.length) {
      return;
    }

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

      // Filter tokens: show all if query is empty, otherwise substring match.
      var matches = query
        ? tokens.filter(function (v) { return v.toLowerCase().indexOf(query) !== -1; })
        : tokens;

      // Reset keyboard navigation on every re-render.
      activeIndex = -1;

      if (matches.length === 0) {
        listEl.innerHTML = '<li style="padding:8px 12px; color:#888; font-style:italic;">No matching tokens</li>';
        listEl.style.display = 'block';
        return;
      }

      // Build list items with the matching substring highlighted in bold.
      listEl.innerHTML = matches.map(function (v, i) {
        var display = v;
        if (query) {
          // Escape regex special chars in query before using in RegExp.
          var safeQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          display = v.replace(new RegExp('(' + safeQuery + ')', 'gi'), '<strong style="color:#0071b8;">$1</strong>');
        }
        return '<li data-token="' + escapeHtml(v) + '" data-index="' + i + '" ' +
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
     * populates the search input with the copied value.
     *
     * @param {string} tokenText - The token string to copy.
     */
    function copyToken(tokenText) {
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

      // Place the copied token in the search input for visual confirmation.
      searchInput.value = tokenText;

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
          copyToken(items[activeIndex].dataset.token);
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
        copyToken(li.dataset.token);
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
