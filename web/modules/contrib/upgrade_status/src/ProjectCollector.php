<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use http\Exception\InvalidArgumentException;

/**
 * Collects projects collated for the purposes of upgrade status.
 */
class ProjectCollector {

  /**
   * The list of available modules.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The list of available themes.
   *
   * @var \Drupal\Core\Extension\ThemeExtensionList
   */
  protected $themeExtensionList;

  /**
   * The list of available profiles.
   *
   * @var \Drupal\Core\Extension\ProfileExtensionList
   */
  protected $profileExtensionList;

  /**
   * A list of allowed extension types.
   *
   * @var array
   */
  protected $allowedTypes = [
    'module',
    'theme',
    'profile',
  ];

  /**
   * Constructs a \Drupal\upgrade_status\ProjectCollector.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list service.
   * @param \Drupal\Core\Extension\ThemeExtensionList $theme_extension_list
   *   The theme extension handler service.
   * @param \Drupal\Core\Extension\ProfileExtensionList $profile_extension_list
   *   The profile extension handler service.
   */
  public function __construct(
    ModuleExtensionList $module_extension_list,
    ThemeExtensionList $theme_extension_list,
    ProfileExtensionList $profile_extension_list
  ) {
    $this->moduleExtensionList = $module_extension_list;
    $this->themeExtensionList = $theme_extension_list;
    $this->profileExtensionList = $profile_extension_list;
  }

  /**
   * Collect projects of installed modules grouped by custom and contrib.
   *
   * @return array
   *   An array keyed by 'custom' and 'contrib' where each array is a list
   *   of projects grouped into that project group. Custom modules get a
   *   project name based on their topmost parent custom module and only
   *   that topmost custom module gets included in the list. Each item is
   *   a \Drupal\Core\Extension\Extension object in both arrays.
   */
  public function collectProjects() {
    $projects = ['custom' => [], 'contrib' => []];
    $modules = $this->moduleExtensionList->getList();
    $themes = $this->themeExtensionList->getList();
    $profiles = $this->profileExtensionList->getList();
    $extensions = array_merge($modules, $themes, $profiles);
    unset($modules, $themes, $profiles);

    /** @var \Drupal\Core\Extension\Extension $extension */
    foreach ($extensions as $key => $extension) {

      if ($extension->origin === 'core') {
        // Ignore core extensions for the sake of upgrade status.
        continue;
      }

      if ($extension->getType() !== 'profile' && $extension->status === 0) {
        // Ignore disabled extensions.
        continue;
      }

      // If the project is already specified in this extension, use that.
      $project = isset($extension->info['project']) ? $extension->info['project'] : '';
      if (array_key_exists($project, $projects['custom'])
        || array_key_exists($project, $projects['contrib'])
      ) {
        // If we already have a representative of this project in the list,
        // don't add this extension.
        // @todo Make sure to use the extension with the shortest file path.
        continue;
      }

      // At this point extensions that don't have a project should be considered
      // custom. Extensions that have the 'drupal' project but did not have the
      // 'core' origin assigned are custom extensions that are running in a
      // Drupal core git checkout, so also categorize them as custom.
      if (empty($project) || $project === 'drupal') {
        $projects['custom'][$key] = $extension;
        continue;
      }

      // @todo should this use $project as the key?
      $projects['contrib'][$key] = $extension;
    }

    // Collate custom extensions to projects, removing sub-extensions.
    $projects['custom'] = $this->collateCustomExtensionsIntoProjects($projects['custom']);

    return $projects;
  }

  /**
   * Finds topmost custom extension for each extension and keeps only that.
   *
   * @param \Drupal\Core\Extension\Extension[] $projects
   *   List of all enabled custom extensions.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   List of custom extensions, with only the topmost custom extension left
   *   for each extension that has a parent extension.
   */
  protected function collateCustomExtensionsIntoProjects(array $projects) {
    foreach ($projects as $name_a => $data_a) {
      $subpath_a = $data_a->subpath . '/';
      $subpath_a_length = strlen($subpath_a);

      foreach ($projects as $name_b => $data_b) {
        $subpath_b = $data_b->subpath;
        // If the extension is not the same but the beginning of paths match,
        // remove this extension from the list as it is part of another one.
        if ($name_b != $name_a && substr($subpath_b, 0, $subpath_a_length) === $subpath_a) {
          unset($projects[$name_b]);
        }
      }
    }
    return $projects;
  }

  /**
   * Returns a single extension based on type and machine name.
   *
   * @param string $type
   *   One of 'module' or 'theme' or 'profile' to signify the type of the
   *   extension.
   * @param string $project_machine_name
   *   Machine name for the extension.
   *
   * @return \Drupal\Core\Extension\Extension
   *   A project if exists.
   *
   * @throws \InvalidArgumentException
   *   If the type was not one of the allowed ones.
   * @throws \Drupal\Core\Extension\Exception\UnknownExtensionException
   *   If there was no extension with the given name.
   */
  public function loadProject(string $type, string $project_machine_name) {
    if (!in_array($type, $this->allowedTypes)) {
      throw new InvalidArgumentException(sprintf('"%s" is not a valid type. Valid types are module, profile and theme.', $type));
    }

    if ($type === 'module') {
      return $this->moduleExtensionList->get($project_machine_name);
    }

    if ($type === 'profile') {
      return $this->profileExtensionList->get($project_machine_name);
    }

    return $this->themeExtensionList->get($project_machine_name);
  }

}
