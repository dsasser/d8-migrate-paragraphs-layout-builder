<?php

namespace Drupal\d8_migrations\Plugin\migrate\process;

use Drupal\d8_migrations\LayoutBase;
use Drupal\d8_migrations\LayoutMigrationItem;
use Drupal\d8_migrations\LayoutMigrationMissingBlockException;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Paragraphs Layout process plugin.
 *
 * Available configuration keys:
 *   - source_field: The source field containing paragraphs.
 *
 * @code
 * layout_builder__layout:
 *   plugin: layout_builder_layout
 *   source_field: field_paragraphs
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "paragraphs_layout"
 * )
 */
class ParagraphsLayout extends LayoutBase {

  /**
   * Transform paragraph source values into a Layout Builder sections.
   *
   * @param mixed $value
   *   The value to be transformed.
   * @param \Drupal\migrate\MigrateExecutableInterface $migrate_executable
   *   The migration in which this process is being executed.
   * @param \Drupal\migrate\Row $row
   *   The row from the source to process. Normally, just transforming the value
   *   is adequate but very rarely you might need to change two columns at the
   *   same time or something like that.
   * @param string $destination_property
   *   The destination property currently worked on. This is only used together
   *   with the $row above.
   *
   * @return \Drupal\layout_builder\Section
   *   A Layout Builder Section object populated with Section Components.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\migrate\MigrateException
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!isset($this->configuration['source_field'])) {
      throw new MigrateException('Missing source_field for paragraph layout process plugin.');
    }

    // Create a single column Section.
    $section = $this->createSection();

    // We need the entire contents of the paragraphs field in order to create
    // the correct sections from the dataset, so we ignore the incoming value.
    $values = $row->getSourceProperty($this->configuration['source_field']);

    $map = $row->getSource()['constants']['map'];
    if (is_array($values)) {
      foreach ($values as $delta => $item) {
        try {
          // Get the paragraph type from the current paragraph id from the
          // source.
          $type = $this->getParagraphType($item['value']);
          $migration_id = $map[$type];
          $item = new LayoutMigrationItem($type, $item['value'], $delta, $migration_id);
          $section->appendComponent($this->createComponent($item));
        }
        catch (LayoutMigrationMissingBlockException $e) {
          // Prevent the migration from skipping this row due to a missing
          // block.
          $this->handleMissingBlockException($migrate_executable, $e);
          continue;
        }
      }
    }

    return $section;
  }

  /**
   * Gets the type of paragraph given a paragraph id.
   *
   * Uses basic static caching since this may be called multiple times for the
   * same paragraphs.
   *
   * @param string $id
   *   The paragraph id.
   *
   * @return string
   *   The paragraph bundle.
   */
  public function getParagraphType($id) {
    $types = &drupal_static(__FUNCTION__);
    if (!isset($types[$id])) {
      $query = $this->migrateDb->select('paragraphs_item', 'p');
      $query->fields('p', ['bundle']);
      $query->condition('p.item_id', $id, '=');
      $types[$id] = $query->execute()->fetchField();
    }
    return $types[$id];
  }

}
