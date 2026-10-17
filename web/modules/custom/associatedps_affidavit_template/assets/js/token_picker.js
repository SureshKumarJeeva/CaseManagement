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
 *   label       - Label text above the search input (default: 'Search & click to copy token')
 *   idPrefix    - Prefix for generated element IDs to avoid collisions (default: 'aps-token')
 *   multiSelect - Opt in to selecting several fields and copying a Jinja2 loop
 *                 instead of a single token. See buildClipboard() below.
 *
 * ⚠ multiSelect is OFF by default and must stay that way. This file is shared
 * by the DocuSign editor, the DocuSign Document Generation editor, both
 * PandaDoc pages and the email token picker. Jinja2 loop syntax is meaningless
 * to all of them — DocuSign matches a tab's bare Data Label, so a pasted
 * "{%p for ... %}" would sit in the document doing nothing, with no error. Only
 * the Microsoft 365 authoring panel (docusign_library_form.js), whose templates
 * are merged by our own docxtpl service, passes it.
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
   * @param {boolean} [options.multiSelect] - Allow several fields to be selected
   *   and copy a Jinja2 repeating block. Jinja2/docxtpl callers only.
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
    var multiSelect = !!opts.multiSelect;

    // ⚠ The separator between two fields on a line is NOT here. It belongs to
    // line() in the merge service (render.py), because whether one is needed
    // at all depends on whether the values turn out to be empty, which is only
    // known per row at merge time.

    // The loop variable in a generated block. Guarded below against the (today
    // impossible) case of a token actually being called "row" — Drupal's
    // formatVariableName() Ucfirsts every segment, so nothing collides, but the
    // generated template would silently read the wrong value if that changed.
    var loopVar = 'row';
    while (items.some(function (it) { return bareName(it.value) === loopVar; })) {
      loopVar += '_';
    }

    // Chosen fields, in the order they were clicked — that order IS the output
    // order, which is how the author decides between "Name - Notes" and
    // "Notes - Name" without any extra control.
    var selection = [];

    // Create the picker panel container.
    var panel = document.createElement('div');
    panel.className = prefix + '-panel';
    panel.style.cssText = 'margin-bottom:10px; padding:10px; background:#f5f5f5; border:1px solid #ccc; border-radius:4px; position:relative; z-index:5;';

    // Generate unique IDs using timestamp to prevent collisions
    // when multiple pickers are on the same page.
    var searchId = prefix + '-search-' + Date.now();
    var listId = prefix + '-list-' + Date.now();
    var copiedId = prefix + '-copied-' + Date.now();
    var trayId = prefix + '-tray-' + Date.now();

    // The selection tray: counter, chips, Clear, and a preview of exactly what
    // is on the clipboard.
    //
    // The preview is what makes this safe to use. The only feedback the picker
    // gives today is a "Copied!" that flashes for 1.5s, which is enough when
    // the clipboard holds one token the author just clicked. It is NOT enough
    // once a second click silently turns that into a three-line loop — without
    // the preview the author would first learn what happened by pasting it.
    var trayHtml = multiSelect
      ? '<div id="' + trayId + '" style="display:none; margin-top:8px; padding:8px 10px; background:#fff; ' +
        'border:1px solid #ccc; border-radius:4px; max-width:460px;">' +
        '<div class="aps-tray-head" style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px;"></div>' +
        '<div class="aps-tray-chips" style="display:flex; flex-wrap:wrap; gap:4px; margin-bottom:6px;"></div>' +
        '<div class="aps-tray-warning" style="display:none; margin-bottom:6px; padding:6px 8px; background:#fff4e5; ' +
        'border:1px solid #f0c36d; border-radius:3px; color:#7a4b00; font-size:12px; line-height:1.4;"></div>' +
        '<pre class="aps-tray-preview" style="margin:0; padding:8px; background:#f7f7f7; border:1px solid #e2e2e2; ' +
        'border-radius:3px; font-family:monospace; font-size:12px; line-height:1.5; white-space:pre-wrap; ' +
        'word-break:break-word; color:#222;"></pre>' +
        '</div>'
      : '';

    // Build the picker HTML: label, search input, "Copied!" span, and dropdown list.
    panel.innerHTML =
      '<label for="' + searchId + '" style="font-weight:bold; display:block; margin-bottom:4px;">' + escapeHtml(label) + '</label>' +
      '<input id="' + searchId + '" type="text" placeholder="Type to search tokens\u2026" autocomplete="off" ' +
      'style="width:100%; max-width:460px; padding:8px 10px; font-size:14px; border:1px solid #aaa; border-radius:4px; box-sizing:border-box;" />' +
      '<span id="' + copiedId + '" style="margin-left:10px; color:#28a745; font-weight:bold; display:none;">Copied!</span>' +
      '<ul id="' + listId + '" style="list-style:none; margin:4px 0 0; padding:0; max-height:240px; overflow-y:auto; ' +
      'border:1px solid #ccc; border-radius:4px; background:#fff; display:none; position:absolute; width:100%; max-width:460px; box-shadow:0 4px 8px rgba(0,0,0,0.12);"></ul>' +
      trayHtml;

    // Insert the panel as the first child of the container.
    containerEl.insertBefore(panel, containerEl.firstChild);

    var searchInput = document.getElementById(searchId);
    var listEl = document.getElementById(listId);
    var copiedEl = document.getElementById(copiedId);
    var trayEl = multiSelect ? document.getElementById(trayId) : null;

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

        // A tick and a tinted row for anything already chosen. Selection is
        // held in `selection`, not in the DOM, so it survives re-filtering —
        // the author can search "employee", click, clear the box, search
        // "notes", click, and still have both.
        var chosen = multiSelect && indexOfSelected(it.value) !== -1;
        var mark = multiSelect
          ? '<span style="display:inline-block; width:16px; color:#28a745; font-weight:bold;">' + (chosen ? '✓' : '') + '</span>'
          : '';

        return '<li data-token="' + escapeHtml(it.value) + '" data-label="' + escapeHtml(it.label) + '" data-index="' + i + '" ' +
          'style="padding:8px 12px; cursor:pointer; font-family:monospace; font-size:13px; border-bottom:1px solid #eee;' +
          (chosen ? ' background:#eef7ee;' : '') + '">' +
          mark + display + '</li>';
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
      writeClipboard([tokenText]);

      // Show the "Copied!" indicator next to the search input.
      copiedEl.style.display = 'inline';

      // Show the label (not the raw token) as visual confirmation.
      searchInput.value = (displayText != null) ? displayText : tokenText;

      // Close the dropdown after selection.
      listEl.style.display = 'none';

      flashCopied();
    }

    /**
     * Put `lines` on the clipboard as both plain text and HTML paragraphs.
     *
     * ⚠ THE HTML FLAVOUR IS THE WHOLE POINT FOR MULTI-LINE BLOCKS. A generated
     * loop only works if each "{%p ... %}" tag ends up ALONE in its own Word
     * paragraph — that is what makes docxtpl delete the marker paragraph and
     * repeat what is between. A plain-text paste carrying "\n" is at the mercy
     * of how the editor interprets it; if Word for the web turns those into
     * soft line breaks inside one paragraph, the tags are no longer alone and
     * the block silently fails to loop.
     *
     * Offering a text/html flavour of <p> elements removes that risk rather
     * than betting on it: Word takes the HTML and creates real paragraphs, and
     * anything that only understands text (a plain textarea, Notepad) still
     * gets the "\n" version. Browsers without ClipboardItem fall back to text,
     * which is exactly what they would have had anyway.
     *
     * @param {string[]} lines - One entry per output paragraph.
     */
    function writeClipboard(lines) {
      var text = lines.join('\n');

      if (lines.length > 1 && window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
        var html = '<div>' + lines.map(function (line) {
          return '<p>' + escapeHtml(line) + '</p>';
        }).join('') + '</div>';

        try {
          navigator.clipboard.write([
            new window.ClipboardItem({
              'text/plain': new Blob([text], { type: 'text/plain' }),
              'text/html': new Blob([html], { type: 'text/html' })
            })
          ]).catch(function () {
            legacyCopy(text);
          });
          return;
        }
        catch (e) {
          // Blob/ClipboardItem construction refused — fall through to text.
        }
      }

      // Modern Clipboard API (requires HTTPS or localhost).
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(function () {
          legacyCopy(text);
        });
      }
      else {
        legacyCopy(text);
      }
    }

    /**
     * Copy via a temporary textarea, for older browsers and non-HTTPS contexts.
     */
    function legacyCopy(text) {
      var temp = document.createElement('textarea');
      temp.value = text;
      document.body.appendChild(temp);
      temp.select();
      document.execCommand('copy');
      document.body.removeChild(temp);
    }

    /**
     * Show the "Copied!" indicator, then hide it again after 1.5 seconds.
     */
    var copiedTimer = null;
    function flashCopied() {
      copiedEl.style.display = 'inline';
      if (copiedTimer) {
        clearTimeout(copiedTimer);
      }
      copiedTimer = setTimeout(function () { copiedEl.style.display = 'none'; }, 1500);
    }

    // --- Multi-select (opt-in; see the note at the top of the file) ---

    /**
     * Position of a token value in the current selection, or -1.
     */
    function indexOfSelected(value) {
      for (var i = 0; i < selection.length; i++) {
        if (selection[i].value === value) {
          return i;
        }
      }
      return -1;
    }

    /**
     * Add or remove one field, then re-copy whatever the selection now means.
     *
     * WHY EVERY CLICK RE-COPIES: it removes the mode. One click leaves exactly
     * today's behaviour (the token on the clipboard); a second click quietly
     * upgrades it to a loop. "Stopping" is just not clicking again — the right
     * thing is always already on the clipboard, so there is no key to hold and
     * nothing for an author who only ever wants one token to learn.
     *
     * A click is a user gesture, which is what the Clipboard API requires, so
     * re-copying on each one is allowed.
     */
    function toggleSelection(value, label) {
      var at = indexOfSelected(value);
      if (at === -1) {
        selection.push({ value: value, label: label });
      }
      else {
        selection.splice(at, 1);
      }

      syncSelection();

      // Redraw so the tick appears, and leave the list open — the single-select
      // path closes it after a copy, which would make choosing a second field
      // impossible. This is only ever reached from a click on the list itself,
      // so "if open" is always true here; it is written this way so the list is
      // never opened as a side effect of changing the selection.
      refreshListIfOpen();
    }

    function clearSelection() {
      selection = [];
      syncSelection();
      refreshListIfOpen();
    }

    /**
     * Redraw the list to match the selection, WITHOUT opening it.
     *
     * ⚠ renderList() always ends by showing the dropdown, which is right when
     * the author is searching and wrong everywhere else. Working in the tray —
     * removing a chip, or clearing — must not throw the list open over the
     * top of it: they are removing fields, not looking for one, and having the
     * dropdown reappear after each × makes taking several off a fight. The
     * list opens on focus and on typing in the search box, and nowhere else.
     */
    function refreshListIfOpen() {
      if (listEl.style.display !== 'none') {
        renderList(searchInput.value);
      }
    }

    /**
     * Turn the current selection into the exact text to put on the clipboard.
     *
     * This is the whole feature. The picker's values already arrive as
     * "{{ Attendance_Notes.Notes }}" — dotted whenever the field lives on a
     * referenced entity — so the part before the dot IS the repeating parent
     * the merge service exposes as rows, and nothing has to be looked up.
     *
     *   one field                -> that token, unchanged (today's behaviour)
     *   several, one parent      -> a {%p for %} block, one output line per row
     *   several, no parent       -> one line(), since nothing repeats
     *   several, TWO parents     -> refuse: no single loop can be right
     *
     * ⚠ TWO OR MORE FIELDS GO THROUGH line(), NOT "{{ a }} - {{ b }}". The
     * separator has to be decided per row, at merge time, because emptiness is
     * data: an attendance note with no employee recorded would render " - No
     * response…" and one with no note "Jason Ryan - ". line() is a global on
     * our Jinja environment (see build_environment() in render.py) that drops
     * the blanks first, so a missing value costs its own text and nothing else.
     *
     * The two-parent case is the one that matters. "Attendance note" and
     * "Document" rows are different lists of different lengths; any loop over
     * one of them would render the other's field identically on every line.
     * Rather than emit something that looks plausible and is wrong, fall back
     * to plain tokens and say so in the tray.
     *
     * @returns {?{lines: string[], warning: string}}
     */
    function buildClipboard() {
      if (!selection.length) {
        return null;
      }
      if (selection.length === 1) {
        return { lines: [selection[0].value], warning: '' };
      }

      var parts = selection.map(function (it) { return splitToken(it.value); });
      var parents = [];
      parts.forEach(function (part) {
        if (part.parent && parents.indexOf(part.parent) === -1) {
          parents.push(part.parent);
        }
      });

      if (parents.length === 0) {
        // Every chosen field holds a single value for the whole Job, so there
        // is no list to walk. One line of them is the honest answer.
        return { lines: [lineCall(parts, null)], warning: '' };
      }

      if (parents.length > 1) {
        return {
          lines: [lineCall(parts, null)],
          warning: 'These fields come from two different repeating lists (' +
            parents.join(' and ') + '), which cannot share one row. ' +
            'They were copied as a single line instead — deselect one list to get a repeating block.'
        };
      }

      // One repeating parent. Fields belonging to it become "row.<Field>";
      // anything without a dot is the same for every row, so it is left as-is
      // and simply repeats down the block — which is usually what is wanted
      // ("line(row.Employee_Full_Name, Client)").
      return {
        lines: [
          '{%p for ' + loopVar + ' in ' + parents[0] + ' %}',
          lineCall(parts, loopVar),
          '{%p endfor %}'
        ],
        warning: ''
      };
    }

    /**
     * Render the one-line "{{ line(a, b) }}" expression for a set of fields.
     *
     * @param {Array} parts - splitToken() results, in selection order.
     * @param {?string} scope - loop variable to qualify dotted fields with, or
     *   null outside a loop (where the full dotted path is addressed directly).
     */
    function lineCall(parts, scope) {
      var args = parts.map(function (part) {
        if (part.parent && scope) {
          return scope + '.' + part.child;
        }
        return part.inner;
      });

      return '{{ line(' + args.join(', ') + ') }}';
    }

    /**
     * Copy the current selection and redraw the tray to match it.
     */
    function syncSelection() {
      var payload = buildClipboard();

      if (payload) {
        writeClipboard(payload.lines);
        flashCopied();
      }

      renderTray(payload);
    }

    /**
     * Draw the counter, the chips, any warning, and the clipboard preview.
     */
    function renderTray(payload) {
      if (!trayEl) {
        return;
      }

      if (!payload) {
        trayEl.style.display = 'none';
        return;
      }

      trayEl.style.display = 'block';

      var isBlock = payload.lines.length > 1;
      trayEl.querySelector('.aps-tray-head').innerHTML =
        '<span style="font-size:13px; color:#333;"><strong>' + selection.length + '</strong> field' +
        (selection.length === 1 ? '' : 's') + ' selected' +
        (isBlock ? ' — the clipboard now holds a repeating block' : '') + '</span>' +
        '<button type="button" data-clear="1" style="border:1px solid #bbb; background:#fff; border-radius:3px; ' +
        'padding:2px 8px; font-size:12px; cursor:pointer; white-space:nowrap;">Clear</button>';

      // Numbered, because click order IS output order — without the numbers
      // there is nothing on screen to say why "Name - Notes" came out that way
      // round rather than the other.
      trayEl.querySelector('.aps-tray-chips').innerHTML = selection.map(function (it, i) {
        return '<span style="display:inline-flex; align-items:center; gap:4px; background:#eef2f7; ' +
          'border:1px solid #c9d6e3; border-radius:12px; padding:2px 4px 2px 8px; font-size:12px;">' +
          '<span style="color:#5a6b7d;">' + (i + 1) + '</span>' +
          escapeHtml(shortLabel(it.label)) +
          '<button type="button" data-remove="' + i + '" title="Remove" ' +
          'style="border:0; background:none; cursor:pointer; font-size:13px; line-height:1; color:#5a6b7d; padding:0 4px;">×</button>' +
          '</span>';
      }).join('');

      var warningEl = trayEl.querySelector('.aps-tray-warning');
      warningEl.textContent = payload.warning;
      warningEl.style.display = payload.warning ? 'block' : 'none';

      trayEl.querySelector('.aps-tray-preview').textContent = payload.lines.join('\n');
    }

    if (trayEl) {
      trayEl.addEventListener('click', function (e) {
        // Same detached-target problem as the list — see choose(). Removing a
        // chip redraws the tray, so this button is gone from the document by
        // the time the click reaches it.
        e.stopPropagation();

        var remove = e.target.closest('[data-remove]');
        if (remove) {
          selection.splice(parseInt(remove.dataset.remove, 10), 1);
          syncSelection();
          refreshListIfOpen();
          return;
        }
        if (e.target.closest('[data-clear]')) {
          clearSelection();
        }
      });
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
        // Copy — or, in multi-select, toggle — the highlighted token. Keeping
        // Enter and click on the same path means the keyboard route builds a
        // block exactly like the mouse route does.
        if (activeIndex >= 0 && items[activeIndex]) {
          choose(items[activeIndex]);
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
        choose(li, e);
      }
    });

    /**
     * What a click or Enter on a list row does, in either mode.
     *
     * ⚠ THE stopPropagation IS LOAD-BEARING in multi-select, and the reason is
     * not obvious: toggling redraws the list, which REPLACES the <li> that was
     * clicked. By the time the click finishes bubbling to the document-level
     * "close on outside click" handler, e.target is an orphaned node, so
     * listEl.contains(e.target) is false and the dropdown closes — after every
     * single selection, making a second choice impossible. Stopping the click
     * here keeps that handler for genuine outside clicks only.
     */
    function choose(li, e) {
      if (multiSelect) {
        if (e) {
          e.stopPropagation();
        }
        toggleSelection(li.dataset.token, li.dataset.label);
      }
      else {
        copyToken(li.dataset.token, li.dataset.label);
      }
    }

    // Close the dropdown when clicking anywhere outside the search input or list.
    //
    // ⚠ The tray has to count as "inside". It sits in the panel but outside
    // both elements, so without this a click on Clear or on a chip's × would
    // close the dropdown underneath the author mid-selection.
    document.addEventListener('click', function (e) {
      if (!searchInput.contains(e.target) &&
          !listEl.contains(e.target) &&
          !(trayEl && trayEl.contains(e.target))) {
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
   * Strip the Jinja braces from a picker value.
   *
   * Values reach the picker already wrapped — getJobVariableOptionsJinja()
   * does it server-side — but plain-string tokens are passed through bare, so
   * this has to cope with both.
   *
   * @param {string} value - '{{ Attendance_Notes.Notes }}' or 'Attendance_Notes.Notes'.
   * @returns {string} The bare variable name.
   */
  function bareName(value) {
    return String(value).replace(/^\s*\{\{\s*/, '').replace(/\s*\}\}\s*$/, '').trim();
  }

  /**
   * Split a picker value into its repeating parent and the field on it.
   *
   * The dot is the whole signal. DocuSignTokenMapper emits "Parent.Child" for
   * exactly the fields that live on a referenced entity — the ones the merge
   * service also sends as table ROWS — and a bare name for everything that
   * holds a single value for the Job. So a dot means "this field repeats, and
   * Parent is the list to walk", with nothing to look up.
   *
   * @returns {{parent: ?string, child: ?string, inner: string}}
   */
  function splitToken(value) {
    var inner = bareName(value);
    var dot = inner.indexOf('.');
    if (dot === -1) {
      return { parent: null, child: null, inner: inner };
    }
    return { parent: inner.slice(0, dot), child: inner.slice(dot + 1), inner: inner };
  }

  /**
   * Shorten a chip's caption to the last one or two path segments.
   *
   * Full labels read "Attendance Note-Attendance Note Details-Attendance
   * Notes-Employee", which is right in the dropdown where the whole path
   * disambiguates, and far too long once four of them sit side by side.
   */
  function shortLabel(label) {
    var parts = String(label).split('-');
    return parts.slice(-2).join('-').trim() || String(label);
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
