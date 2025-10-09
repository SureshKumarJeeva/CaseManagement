(function ($, Drupal, drupalSettings, once) {

  'use strict';

  /**
   * Move an element up or down one line.
   *
   * This is highly inspired from tabledrag.js keydown event listener.
   */
  const arrowClick = (evt) => {
    evt.preventDefault();
    const el = evt.currentTarget;
    const direction = el.getAttribute('data-drag-and-drop-direction');
    const currentRow = el.closest('.draggable');
    const draggableTable = currentRow.closest('.draggable-table');

    // Find the row we want to swap with.
    let swapRow = direction === 'up' ? currentRow.previousElementSibling : currentRow.nextElementSibling;
    while (swapRow && !swapRow.matches('.draggable')) {
      swapRow = direction === 'up' ? swapRow.previousElementSibling : swapRow.nextElementSibling;
    }

    // Let Assistive Tech users know they cannot move the row this direction.
    if (!swapRow) {
      let message = Drupal.t('You cannot move this row up because it is already the first.');
      if (direction === 'down') {
        message = Drupal.t('You cannot move this row down because it is already the last.');
      }
      Drupal.announce(message);
      return;
    }

    // Use tableDrag API to swap rows.
    const tableDrag = Drupal.tableDrag[draggableTable.id];
    tableDrag.rowObject = new tableDrag.row(currentRow);
    tableDrag.rowObject.swap(direction === 'up' ? 'before' : 'after', swapRow);

    // Warn Assistive Tech users that their operation was successful.
    let message = Drupal.t('The row has been moved up. Do not forget to save your changes.');
    if (direction === 'down') {
      message = Drupal.t('The row has been moved down. Do not forget to save your changes.');
    }
    Drupal.announce(message);

    // Show the changed warning on the table.
    tableDrag.rowObject.markChanged();
    if (tableDrag.changed === false) {
      const template = document.createElement('template');
      template.innerHTML = Drupal.theme('tableDragChangedWarning');

      draggableTable.parentNode.insertBefore(template.content.firstChild, draggableTable);
      tableDrag.changed = true;
    }

    el.focus();
  };

  /**
   * Initialize drag and drop buttons for paragraphs tabledrag.
   *
   * @type {Object}
   */
  Drupal.behaviors.paragraphsEeDragDropArrowsInit = {
    attach: function (context) {
      once('drag-and-drop-arrows', '.drag-drop-buttons').forEach((el) => {
        const currentRow = el.closest('.draggable');
        // Find drag handle.
        const tabledragHandle = currentRow.querySelector('.tabledrag-handle');
        if (tabledragHandle == null) {
          return;
        }

        // Create arrow buttons.
        const $arrowDown = $(Drupal.theme('paragraphsEeTableDragArrowDown'));
        const $arrowUp = $(Drupal.theme('paragraphsEeTableDragArrowUp'));

        // Attach to tabledrag.
        tabledragHandle.parentNode.insertBefore($arrowUp.get(0), tabledragHandle);
        tabledragHandle.parentNode.appendChild($arrowDown.get(0));
      });
    }
  };

  /**
   * Click handler for drag and drop arrows.
   *
   * @type {Object}
   */
  Drupal.behaviors.paragraphsEeDragDropArrowsEvents = {
    attach: function (context) {
      once('drag-and-drop-arrow-events', '.js-drag-and-drop-arrow').forEach((el) => {
        // Add event listener.
        el.addEventListener('click', arrowClick);
      });
    }
  };


  $.extend(
    Drupal.theme,
    /** @lends Drupal.theme */ {
      /**
       * @return {string}
       *  Markup for tabledrag: arrow down.
       */
      paragraphsEeTableDragArrowDown() {
        return `<a href="#" class="drag-and-drop-arrows__arrow drag-and-drop-arrows__arrow--down js-drag-and-drop-arrow" data-drag-and-drop-direction="down" title="${Drupal.t(
          'Move this @paragraph_title down', {'@paragraph_title': drupalSettings.paragraphs_ee.widgetTitle}
        )}"></a>`;
      },

      /**
       * @return {string}
       *  Markup for tabledrag: arrow up.
       */
      paragraphsEeTableDragArrowUp() {
        return `<a href="#" class="drag-and-drop-arrows__arrow drag-and-drop-arrows__arrow--up js-drag-and-drop-arrow" data-drag-and-drop-direction="up" title="${Drupal.t(
          'Move this @paragraph_title up', {'@paragraph_title': drupalSettings.paragraphs_ee.widgetTitle}
        )}"></a>`;
      }
    }
  );

}(jQuery, Drupal, drupalSettings, once));
