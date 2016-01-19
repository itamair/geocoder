<?php

/**
 * @file
 * Contains \Drupal\geocoder_field\Plugin\Field\FieldWidget\GeocodeWidget.
 */

namespace Drupal\geocoder_field\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\geocoder\Geocoder;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Geocode widget implementation for the Geocoder Field module.
 *
 * @FieldWidget(
 *   id = "geocoder_geocode_widget",
 *   label = @Translation("Geocode from/to an existing field"),
 *   field_types = {
 *     "string",
 *     "file",
 *     "geofield"
 *   },
 * )
 */
class GeocodeWidget extends WidgetBase {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'mode' => 'from',
      'field' => '',
      'provider_plugins' => array(),
      'dumper_plugin' => 'wkt',
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['mode'] = array(
      '#type' => 'select',
      '#weight' => 5,
      '#title' => $this->t('Operating mode'),
      '#description' => $this->t('Select the operating mode. <em>From</em> or <em>To</em>.'),
      '#default_value' => $this->getSetting('mode'),
      '#required' => TRUE,
      '#options' => array('from' => $this->t('From'), 'to' => $this->t('To')),
    );

    $entityFieldDefinitions = \Drupal::entityManager()->getFieldDefinitions($this->fieldDefinition->getTargetEntityTypeId(), $this->fieldDefinition->getTargetBundle());

    $options = array();
    foreach ($entityFieldDefinitions as $id => $definition) {
      if (in_array($definition->getType(), array('file', 'string', 'geofield')) && ($definition->getName() != $this->fieldDefinition->getName())) {
        $options[$id] = sprintf('%s (%s)', $definition->getLabel(), $definition->getType());
      }
    }

    $elements['field'] = array(
      '#type' => 'select',
      '#weight' => 10,
      '#title' => $this->t('Field to use'),
      '#description' => $this->t('Select which field you would like to use.'),
      '#default_value' => $this->getSetting('field'),
      '#required' => TRUE,
      '#options' => $options,
    );

    $enabled_plugins = array();
    $i = 0;
    foreach($this->getSetting('provider_plugins') as $plugin_id => $plugin) {
      if ($plugin['checked']) {
        $plugin['weight'] = intval($i++);
        $enabled_plugins[$plugin_id] = $plugin;
      }
    }

    $elements['geocoder_plugins_title'] = array(
      '#type' => 'item',
      '#weight' => 15,
      '#title' => t('Geocoder plugin(s)'),
      '#description' => t('Select the Geocoder plugins to use, you can reorder them. The first one to return a valid value will be used.'),
    );

    $elements['provider_plugins'] = array(
      '#type' => 'table',
      '#weight' => 20,
      '#header' => array(
        array('data' => $this->t('Enabled')),
        array('data' => $this->t('Weight')),
        array('data' => $this->t('Name')),
      ),
      '#tabledrag' => array(
        array(
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'provider_plugins-order-weight',
        ),
      ),
    );

    $rows = array();
    $count = count($enabled_plugins);
    foreach (Geocoder::getPlugins('Provider') as $plugin_id => $plugin_name) {
      if (isset($enabled_plugins[$plugin_id])) {
        $weight = $enabled_plugins[$plugin_id]['weight'];
      } else {
        $weight = $count++;
      }

      $rows[$plugin_id] = array(
        '#attributes' => array(
          'class' => array('draggable'),
        ),
        '#weight' => $weight,
        'checked' => array(
          '#type' => 'checkbox',
          '#default_value' => isset($enabled_plugins[$plugin_id]) ? 1 : 0,
        ),
        'weight' => array(
          '#type' => 'weight',
          '#title' => t('Weight for @title', array('@title' => $plugin_id)),
          '#title_display' => 'invisible',
          '#default_value' => $weight,
          '#attributes' => array('class' => array('provider_plugins-order-weight')),
        ),
        'name' => array(
          '#plain_text' => $plugin_name,
        ),
      );
    }

    uasort($rows, function($a, $b) {
      return strcmp($a['#weight'], $b['#weight']);
    });

    foreach($rows as $plugin_id => $row) {
      $elements['provider_plugins'][$plugin_id] = $row;
    }

    $elements['dumper_plugin'] = array(
      '#type' => 'select',
      '#weight' => 25,
      '#title' => 'Output format',
      '#default_value' => $this->getSetting('dumper_plugin'),
      '#options' => Geocoder::getPlugins('dumper'),
      '#description' => t('Set the output format of the value. Ex, for a geofield, the format must be set to WKT.')
    );

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = array();
    $dumper_plugin = $this->getSetting('dumper_plugin');

    $summary[] = $this->t('Operating mode: !mode', array('!mode' => $this->getSetting('mode')));
    $summary[] = $this->t('Field: !field', array('!field' => $this->getSetting('field')));

    $geocoder_plugins = Geocoder::getPlugins('Provider');
    $dumper_plugins = Geocoder::getPlugins('Dumper');

    // Find the enabled geocoder plugins.
    $provider_plugin_ids = array();
    foreach($this->getSetting('provider_plugins') as $plugin_id => $plugin) {
      if ($plugin['checked']) {
        $provider_plugin_ids[] = $geocoder_plugins[$plugin_id];
      }
    }

    if (!empty($provider_plugin_ids)) {
      $summary[] = t('Geocoder plugin(s): @plugin_ids', array('@plugin_ids' => implode(', ', $provider_plugin_ids)));
    }
    if (!empty($dumper_plugin)) {
      $summary[] = t('Output format plugin: @format', array('@format' => $dumper_plugins[$dumper_plugin]));
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $main_widget = $element + array(
        '#type' => 'textfield',
        '#default_value' => isset($items[$delta]->value) ? $items[$delta]->value : NULL,
      );

    $element['value'] = $main_widget;

    return $element;
  }


  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $violation, array $form, FormStateInterface $form_state) {
    if ($violation->arrayPropertyPath == array('format') && isset($element['format']['#access']) && !$element['format']['#access']) {
      // Ignore validation errors for formats if formats may not be changed,
      // i.e. when existing formats become invalid. See filter_process_format().
      return FALSE;
    }
    return $element;
  }

}
