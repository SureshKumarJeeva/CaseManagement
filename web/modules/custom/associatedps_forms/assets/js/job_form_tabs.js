/**
 * @file
 * Handles horizontal tab persistence on the Job form.
 *
 * Saves the active tab index to a cookie whenever a tab is clicked,
 * and restores it on page load. This ensures the correct tab remains
 * active after form submission, modal dialog redirects, or any page
 * reload on the same job form.
 */
(function ($, Drupal, once) {
  'use strict';

  var COOKIE_TAB = 'job_form_active_tab';
  var COOKIE_PAGE = 'job_form_active_page';

  /**
   * Get a page identifier from the URL.
   * Returns the node ID for edit pages, or 'add' for /node/add/job.
   */
  function getPageId() {
    var match = window.location.pathname.match(/\/node\/(\d+)/);
    return match ? match[1] : 'add';
  }

  function setCookie(name, value) {
    document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;SameSite=Lax';
  }

  function getCookie(name) {
    var escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + escaped + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function deleteCookie(name) {
    document.cookie = name + '=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT;SameSite=Lax';
  }

  /**
   * Find the index of the currently visible (active) tab pane.
   */
  function getActiveTabIndex($tabsWrapper) {
    var activeIndex = 0;
    $tabsWrapper
      .find('> [data-horizontal-tabs-panes] > .horizontal-tabs-pane')
      .each(function (index) {
        if (!$(this).hasClass('horizontal-tab-hidden')) {
          activeIndex = index;
          return false;
        }
      });
    return activeIndex;
  }

  /**
   * Save the current tab index and page ID to cookies.
   */
  function saveTabState($tabsWrapper, pageId) {
    var index = getActiveTabIndex($tabsWrapper);
    setCookie(COOKIE_TAB, index);
    setCookie(COOKIE_PAGE, pageId);
  }

  Drupal.behaviors.associatedpsJobFormTabs = {
    attach: function (context) {
      $(once('job-form-tabs-fix', '[data-horizontal-tabs]', context)).each(
        function () {
          var $tabsWrapper = $(this);
          var $panes = $tabsWrapper.find('> [data-horizontal-tabs-panes]');
          var pageId = getPageId();

          $panes.each(function () {
            var $this = $(this);
            var $allPanes = $this.find('> .horizontal-tabs-pane');

            // Check cookie for a saved tab index.
            var savedTabIndex = getCookie(COOKIE_TAB);
            var savedPageId = getCookie(COOKIE_PAGE);

            // Restore if page ID matches, or if saved page was 'add'
            // (handles the add -> edit redirect after creating a new job).
            if (savedTabIndex !== null && (savedPageId === pageId || savedPageId === 'add')) {
              var index = parseInt(savedTabIndex, 10);
              var $targetPane = $allPanes.eq(index);
              if ($targetPane.length && $targetPane.data('horizontalTab')) {
                $targetPane.data('horizontalTab').focus();
                // Clear cookies after successful restoration.
                deleteCookie(COOKIE_TAB);
                deleteCookie(COOKIE_PAGE);
                return;
              }
            }

            // Clear stale cookies if page ID doesn't match.
            deleteCookie(COOKIE_TAB);
            deleteCookie(COOKIE_PAGE);

            // Default: if no tab is currently visible, focus the first one.
            var $selectedTab = $this.find(
              '> .horizontal-tabs-pane:not(.horizontal-tab-hidden):visible'
            );

            // If no tab is currently visible/selected, focus the first one.
            if ($selectedTab.length === 0) {
              var $firstPane = $this.find('> .horizontal-tabs-pane:first');
              if ($firstPane.length && $firstPane.data('horizontalTab')) {
                $firstPane.data('horizontalTab').focus();
              }
            }
          });

          // Save tab state on every tab click so it survives modal redirects.
          $tabsWrapper.find('.horizontal-tab-button a').on('click', function () {
            // Small delay to let Drupal's tab switching complete.
            setTimeout(function () {
              saveTabState($tabsWrapper, pageId);
            }, 50);
          });

          // Save tab state when any modal button is clicked (e.g. Add Court,
          // Create New Document), so the active tab persists through the
          // modal's redirect after save. Uses event delegation to cover
          // dynamically added paragraph buttons.
          $tabsWrapper.closest('form').on('click', 'a.use-ajax[data-dialog-type="modal"]', function () {
            saveTabState($tabsWrapper, pageId);
          });

          // Track whether "Save & Next" was clicked.
          var saveNextClicked = false;

          // "Save & Next" — set flag and write next-tab cookie before submit fires.
          $tabsWrapper.closest('form').find('.save-next-btn').on('mousedown', function () {
            saveNextClicked = true;
            var $allPanes = $tabsWrapper
              .find('> [data-horizontal-tabs-panes] > .horizontal-tabs-pane');
            var currentIndex = getActiveTabIndex($tabsWrapper);
            var nextIndex = currentIndex + 1;
            if (nextIndex >= $allPanes.length) {
              nextIndex = 0;
            }
            setCookie(COOKIE_TAB, nextIndex);
            setCookie(COOKIE_PAGE, pageId);
          });

          // Save on form submit for normal save flow,
          // but skip if "Save & Next" already set the cookie.
          $tabsWrapper.closest('form').on('submit', function () {
            if (!saveNextClicked) {
              saveTabState($tabsWrapper, pageId);
            } else {
              saveNextClicked = false;
            }
          });
        }
      );
    }
  };
})(jQuery, Drupal, once);
