<?php

namespace Drupal\d8_migrations;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_tools\MigrateExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for layout process plugins.
 *
 * @package Drupal\d8_migrations
 */
class LayoutBase extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $db;

  /**
   * The uuid service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The immutable config factory service provided by Drupal core.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Drupal migrate lookup service.
   *
   * @var \Drupal\migrate\MigrateLookupInterface
   */
  protected $migrateLookup;

  /**
   * Block content Entity storage.
   *
   * @var \Drupal\Core\Entity\ContentEntityStorageInterface
   */
  protected $blockContentStorage;

  /**
   * The migration database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $migrateDb;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $pluginId, $pluginDefinition, MigrationInterface $migration, UuidInterface $uuid, Connection $db, EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $configFactory, MigrateLookupInterface $migrateLookup) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->uuid = $uuid;
    $this->db = $db;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $configFactory;
    $this->migrateLookup = $migrateLookup;
    $this->blockContentStorage = $entityTypeManager->getStorage('block_content');
    $this->migrateDb = Database::getConnection('default', 'migrate');
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $migration,
      $container->get('uuid'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('migrate.lookup')
    );
  }

  /**
   * Creates a Layout Builder section.
   *
   * @param \Drupal\layout_builder\SectionComponent[] $components
   *   An array of section components to add to the section.
   * @param string $layout
   *   The layout template id to use for this section.
   * @param array $settings
   *   An array of settings for the layout.
   *
   * @return \Drupal\layout_builder\Section
   *   The created section.
   */
  public function createSection(array $components = [], $layout = 'layout_onecol', array $settings = []) {
    return new Section($layout, $settings, $components);
  }

  /**
   * Creates a component from a paragraph.
   *
   * @param \Drupal\d8_migrations\LayoutMigrationItem $item
   *   A migration item instance.
   * @param string $region
   *   The region the component belongs within.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   A Layout Builder SectionComponent.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\migrate\MigrateException
   * @throws \Drupal\d8_migrations\LayoutMigrationMissingBlockException
   */
  public function createComponent(LayoutMigrationItem $item, $region = 'content') {
    // Find the block from the related migration.
    $block_id = $this->lookupBlock($item->getMigration(), $item->getId());
    // Get the block type. Use a db query instead of loading the entity for
    // performance.
    $query = $this->db->select('block_content_field_data', 'b')
      ->fields('b', ['type'])
      ->condition('b.id', $block_id, '=');
    $block_type = $query->execute()->fetchField();
    if (!$block_type) {
      throw new MigrateException(sprintf('An unknown error occurred trying to find the block type from migration item type %s with id %s.', $item->getType(), $item->getId()));
    }
    // Get the latest revision id for the block.
    $block_revision_id = $this->blockContentStorage->getLatestRevisionId($block_id);

    // Create a new component from the block.
    return $this->createSectionComponent($block_revision_id, $block_type, $item->getDelta(), $region);
  }

  /**
   * Looks up a block from a given migration.
   *
   * @param string $migration_id
   *   The migration id to search.
   * @param string $id
   *   The source id from the migration.
   *
   * @return int
   *   The block id of the located block.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\migrate\MigrateException
   * @throws \Drupal\d8_migrations\LayoutMigrationMissingBlockException
   */
  public function lookupBlock($migration_id, $id) {
    // Find the block from the related migration.
    $source = [$id];
    $block_ids = $this->migrateLookup->lookup($migration_id, $source);
    if (empty($block_ids)) {
      throw new LayoutMigrationMissingBlockException(sprintf('Unable to find related migrated block for source id %s in migration %s', $id, $migration_id), MigrationInterface::MESSAGE_WARNING);
    }
    return reset($block_ids)['id'];
  }

  /**
   * Creates a layout builder section component.
   *
   * @param int|string $block_latest_revision_id
   *   The numeric block content revision id.
   * @param string $block_type
   *   The block type machine name to embed as an inline block for.
   * @param int $weight
   *   The weight of the component.
   * @param string $region
   *   The region of the layout the component will reside in.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   Returns the layout builder section component that gets added.
   */
  public function createSectionComponent($block_latest_revision_id, $block_type, $weight = 0, $region = 'content') {
    return SectionComponent::fromArray([
      'uuid' => $this->uuid->generate(),
      'region' => $region,
      'configuration' =>
        [
          'id' => "inline_block:{$block_type}",
          'label' => 'Layout Builder Inline Block',
          'provider' => 'layout_builder',
          'label_display' => '0',
          'view_mode' => 'full',
          'block_revision_id' => $block_latest_revision_id,
          'block_serialized' => NULL,
          'context_mapping' => [],
        ],
      'additional' => [],
      'weight' => $weight,
    ]);
  }

  /**
   * Loads default layout builder sections for a content type.
   *
   * @param string $bundle
   *   The content type to load defaults from.
   *
   * @return \Drupal\layout_builder\Section[]
   *   An array of the default layout builder section objects loaded from
   *   config.
   */
  protected function loadDefaultSections($bundle) {
    $config = $this->configFactory->get("core.entity_view_display.node.{$bundle}.default");
    $sections_array = $config->get('third_party_settings.layout_builder.sections');
    $sections = [];

    if (!empty($sections_array)) {
      foreach ($sections_array as $section_data) {
        $sections[] = Section::fromArray($section_data);
      }
    }
    return $sections;
  }

  /**
   * Handles exceptions for missing blocks.
   *
   * Writes a message to the migrate map table and displays the message.
   *
   * @param \Drupal\migrate\MigrateExecutableInterface $migrateExecutable
   *   The current migration executable.
   * @param \Drupal\d8_migrations\LayoutMigrationMissingBlockException $e
   *   The exception thrown when unable to find a block.
   */
  protected function handleMissingBlockException(MigrateExecutableInterface $migrateExecutable, LayoutMigrationMissingBlockException $e) {
    $migrateExecutable->saveMessage($e->getMessage(), $e->getCode());
    if ($migrateExecutable instanceof MigrateExecutable) {
      $migrateExecutable->message->display($e->getMessage());
    }
  }

}
