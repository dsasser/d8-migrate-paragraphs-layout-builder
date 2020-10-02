<?php

namespace Drupal\d8_migrations\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for flattening and correcting layout sections.
 */
class PreRowSaveSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() {
    return [MigrateEvents::PRE_ROW_SAVE => 'preRowSave'];
  }

  /**
   * Migration pre-row save event subscriber.
   *
   * This method is used to flatten the layout_builder__layout field into a
   * single-dimensional array. This is needed because some of the layout plugins
   * can add multiple sections to this field and this is not a structure
   * supported by the field. Consider the following array, where
   * [Layout Section] is a Drupal\layout_builder\Section object:
   *
   * @code
   *   $layout_builder__layout => [
   *     0 => [Layout Section],
   *     1 => [
   *       0 => [Layout Section],
   *       1 => [Layout section],
   *     ],
   *     2 => [Layout Section],
   *   ];
   * @endcode
   *
   * This method will produce a flattened layout field resulting in the
   * following:
   *
   * @code
   *   $layout_builder__layout => [
   *     0 => [Layout Section],
   *     1 => [Layout Section],
   *     2 => [Layout Section],
   *     3 => [Layout section],
   *   ];
   * @endcode
   *
   * @param \Drupal\migrate\Event\MigratePreRowSaveEvent $event
   *   A migration event.
   */
  public function preRowSave(MigratePreRowSaveEvent $event) {
    if ($event->getRow()->getDestinationProperty('layout_builder__layout')) {
      $current_layout = $event->getRow()->getDestinationProperty('layout_builder__layout');
      $new_layout = [];
      if (is_array($current_layout)) {
        // First, eliminate null array elements.
        $current_layout = array_filter($current_layout);
        foreach ($current_layout as $layout) {
          if (!is_array($layout)) {
            $new_layout[] = $layout;
          }
          else {
            $this->flatten($layout, $new_layout);
          }
        }
      }
      $event->getRow()->setDestinationProperty('layout_builder__layout', $new_layout);
    }
  }

  /**
   * Flattens a multi-dimensional array using recursion.
   *
   * @param array $elements
   *   The elements to flatten.
   * @param array $new_layout
   *   The resulting flattened array.
   */
  public function flatten(array $elements, array &$new_layout) {
    foreach ($elements as $element) {
      if (!is_array($element)) {
        $new_layout[] = $element;
      }
      else {
        $this->flatten($element, $new_layout);
      }
    }
  }

}
