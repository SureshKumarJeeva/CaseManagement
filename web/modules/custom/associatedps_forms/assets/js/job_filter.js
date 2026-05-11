(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.jobFilterScrollToResults = {
    attach: function (context) {
      // Scroll to results after page load when filters are active.
      var target = document.getElementById('job-filter-results')
        || document.getElementById('job-filter-results-table');
      if (target && once('job-filter-scroll', 'html', context).length) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
  };

})(Drupal, once);
