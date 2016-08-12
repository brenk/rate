<?php

namespace Drupal\rate\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure rate settings for the site.
 */
class RateSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rate_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['rate.settings'];
  }

  /**
   * Get a list of acceptable entity types.
   *
   * @return array
   *   Returns an array of allowed entity types for voting.
   */
  protected function getAllowedTypes() {
    // @Todo: make these configurable.
    return [
      'block',
      'block_content_type',
      'comment_type',
      'contact_form',
      'node_type',
      'search_page',
      'taxonomy_vocabulary',
      'view',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('rate.settings');

    $widget_type_options = [
      "fivestar" => "Fivestar",
      "number_up_down" => "Number Up / Down",
      "thumbs_up" => "Thumbs Up",
      "thumbs_up_down" => "Thumbs Up / Down",
      "yesno" => "Yes / No",
    ];

    $form['widget_type'] = [
      '#type' => 'select',
      '#title' => t('Widget Type'),
      '#options' => $widget_type_options,
      '#default_value' => $config->get('widget_type', 'number_up_down'),
    ];

    $form['use_ajax'] = [
      '#type' => 'checkbox',
      '#title' => t('Use AJAX'),
      '#default_value' => $config->get('use_ajax', FALSE),
      '#description' => t('Record vote via AJAX.'),
    ];

    $form['bot'] = [
      '#type' => 'fieldset',
      '#title' => t('Bot detection'),
      '#description' => t('Bots can be automatically banned from voting if they rate more than a given amount of votes within one minute or hour. This threshold is configurable below. Votes from the same IP-address will be ignored forever after reaching this limit.'),
      '#collapsbile' => FALSE,
      '#collapsed' => FALSE,
    ];

    $threshold_options = array_combine([0, 10, 25, 50, 100, 250, 500, 1000], [
      0,
      10,
      25,
      50,
      100,
      250,
      500,
      1000,
    ]);
    $threshold_options[0] = t('disable');

    $form['bot']['bot_minute_threshold'] = [
      '#type' => 'select',
      '#title' => t('1 minute threshold'),
      '#options' => $threshold_options,
      '#default_value' => $config->get('bot_minute_threshold', 25),
    ];

    $form['bot']['bot_hour_threshold'] = [
      '#type' => 'select',
      '#title' => t('1 hour threshold'),
      '#options' => $threshold_options,
      '#default_value' => $config->get('bot_hour_threshold', 250),
    ];

    $form['bot']['botscout_key'] = [
      '#type' => 'textfield',
      '#title' => t('BotScout.com API key'),
      '#default_value' => $config->get('botscout_key', ''),
      '#description' => t('Rate will check the voters IP against the BotScout database if it has an API key. You can request a key at %url.', ['%url' => 'http://botscout.com/getkey.htm']),
    ];

    $form['rate_types_enabled'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => t('Entity types with Rate widgets enabled:'),
      '#description' => t('If you disable any type here, already existing data will remain untouched.'),
    ];

    $entity_types = \Drupal::entityTypeManager()->getDefinitions();
    $enabled_bundles = $config->get('enabled_bundles');
    foreach ($entity_types as $entity_type_id => $entity_type) {
      $bundles = \Drupal::entityTypeManager()->getStorage($entity_type_id)->loadMultiple();
      if (!empty($bundles) && in_array($entity_type_id, $this->getAllowedTypes())) {
        $form['rate_types_enabled'][$entity_type_id . '_enabled'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => $entity_type->getLabel(),
        ];
        foreach ($bundles as $bundle) {
          $default_value = (isset($enabled_bundles[$bundle->id()])) ? 1 : 0;
          $form['rate_types_enabled'][$entity_type_id . '_enabled'][$bundle->id()] = [
            '#type' => 'checkbox',
            '#title' => $bundle->label(),
            '#default_value' => $default_value,
          ];
        }
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue(['botscout_key'])) {
      $uri = "http://botscout.com/test/?ip=84.16.230.111&key=" . $form_state->getValue(['botscout_key']);
      try {
        $response = \Drupal::httpClient()->get($uri, ['headers' => ['Accept' => 'text/plain']]);
        $data = (string) $response->getBody();
        $status_code = $response->getStatusCode();
        if (empty($data)) {
          drupal_set_message(t('An empty response was returned from botscout.'), 'warning');
        }
        elseif ($status_code == 200) {
          if ($data{0} == 'Y' || $data{0} == 'N') {
            drupal_set_message(t('Rate has succesfully contacted the BotScout server.'));
          }
          else {
            $form_state->setErrorByName('botscout_key', t('Invalid API-key.'));
          }
        }
        else {
          drupal_set_message(t('Rate was unable to contact the BotScout server.'), 'warning');
        }
      }
      catch (RequestException $e) {
        drupal_set_message(t('An error occurred contacting BotScout.'), 'warning');
        watchdog_exception('rate', $e);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('rate.settings');

    $enabled_bundles = [];
    $entity_types = \Drupal::entityTypeManager()->getDefinitions();
    foreach ($entity_types as $entity_type_id => $entity_type) {
      $bundles = \Drupal::entityTypeManager()->getStorage($entity_type_id)->loadMultiple();
      foreach ($bundles as $bundle) {
        if ($form_state->getValue($bundle->id())) {
          $enabled_bundles[$bundle->id()] = $entity_type->getBundleOf();
        }
      }
    }
    $config->set('enabled_bundles', $enabled_bundles);

    $config->set('widget_type', $form_state->getValue('widget_type'))
      ->set('bot_minute_threshold', $form_state->getValue('bot_minute_threshold'))
      ->set('bot_hour_threshold', $form_state->getValue('bot_hour_threshold'))
      ->set('botscout_key', $form_state->getValue('botscout_key'))
      ->set('use_ajax', $form_state->getValue('use_ajax'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
