<?php

namespace Drupal\d8_migrations\Plugin\migrate\process;

use Drupal\d8_migrations\LayoutBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Processes default layouts for Layout Builder.
 *
 * This plugin is specifically for adding the default layout to the migration.
 *
 * Available configuration keys
 * - bundle: The D8 node bundle the migration is acting on.
 *
 * @MigrateProcessPlugin(
 *   id = "default_layout"
 * )
 */
class DefaultLayout extends LayoutBase {

  /**
   * Transform for DefaultLayout.
   *
   * @param mixed $value
   *   The values from the migration source.
   * @param \Drupal\migrate\MigrateExecutableInterface $migrate_executable
   *   The current migration executable.
   * @param \Drupal\migrate\Row $row
   *   The current migration row.
   * @param string $destination_property
   *   The destination property.
   *
   * @return \Drupal\layout_builder\Section[]|mixed
   *   An array of layout builder section or the values from the source field
   *   unchanged.
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $bundle = $this->configuration['bundle'];
    if ($bundle) {
      $sections = $this->loadDefaultSections($bundle);
      if (!empty($sections)) {
        return $sections;
      }
      else {
        return NULL;
      }
    }
    return $value;
  }

}
