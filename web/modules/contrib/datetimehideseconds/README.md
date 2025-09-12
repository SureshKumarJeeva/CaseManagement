# DateTime hide Seconds

This module provides a feature which allows admin user to "Hide seconds" in a
datetime field widgets by providing a toggle for it.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/datetimehideseconds).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/datetimehideseconds).

## Requirements

This module requires no modules outside of Drupal core.


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

If your site is [managed via Composer](https://www.drupal.org/node/2718229), use
Composer to download the module:

   ```sh
   composer require "drupal/datetimehideseconds"
   ```
Enable the module manually through the "Extend" admin menu entry, or simply use [drush](https://www.drush.org/latest/):

   ```sh
   drush -y en datetimehideseconds
   ```


## Configuration

1. Navigate to Administration > Extend and enable the module.
2. Navigate to Administration > Structure > Content Type.
3. Manage form display of the content type which has the datetime field.
4. Alter the widget settings of the Date and time range.
5. Enable the radio button to hide the seconds.
6. Update and Save.


## Maintainers

- Andi Rüther [(anruether)](https://www.drupal.org/u/anruether)
- Merlin Axel Rutz [(geek-merlin)](https://www.drupal.org/u/geek-merlin)
- Rainer Halbmann [(heliogabal)](https://www.drupal.org/u/heliogabal)
