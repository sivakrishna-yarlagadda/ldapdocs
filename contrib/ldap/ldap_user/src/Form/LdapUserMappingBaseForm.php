<?php

declare(strict_types = 1);

namespace Drupal\ldap_user\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\ldap_servers\Mapping;

/**
 * Provides the form to configure user configuration and field mapping.
 */
abstract class LdapUserMappingBaseForm extends LdapUserBaseForm {

  /**
   * Events.
   *
   * @var array
   */
  protected $events;

  /**
   * Direction.
   *
   * @var string
   */
  protected $direction;

  /**
   * Server.
   *
   * @var string
   */
  protected $server;

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    foreach ($values['mappings'] as $key => $mapping) {
      if (isset($mapping['configured_mapping']) && $mapping['configured_mapping'] == 1) {
        // Check that the source is not empty for the selected field to sync
        // to Drupal.
        if (!empty($mapping['source'])) {
          if (empty($mapping['target'])) {
            $formElement = $form['mappings'][$key];
            $form_state->setError($formElement, $this->t('Missing attribute'));
          }
        }
      }
    }

    $processed_mappings = $this->syncMappingsFromForm($form_state->getValues());

    // Notify the user if no actual synchronization event is active for a field.
    $this->checkEmptyEvents($processed_mappings);
  }

  /**
   * Warn about fields without associated events.
   *
   * @param array $mappings
   *   Field mappings.
   */
  private function checkEmptyEvents(array $mappings) {
    foreach ($mappings as $key => $mapping) {
      if (empty($mapping['prov_events'])) {

        $this->messenger()
          ->addWarning($this->t('No synchronization events checked in %item. This field will not be synchronized until some are checked.', ['%item' => $key]));
      }
    }
  }

  /**
   * Derive synchronization mappings from configuration.
   *
   * @param string $direction
   *   Direction.
   * @param string $sid
   *   Server ID.
   *
   * @return array
   *   Mappings.
   */
  protected function loadAvailableMappings($direction, $sid): array {
    $attributes = [];
    if ($sid) {
      try {
        /** @var \Drupal\ldap_servers\Entity\Server $ldap_server */
        $ldap_server = $this->entityTypeManager
          ->getStorage('ldap_server')
          ->load($sid);
        $attributes = $this->fieldProvider
          ->loadAttributes($direction, $ldap_server);
      }
      catch (\Exception $e) {
        $this->logger('ldap_user')->error('Missing server');
      }
    }
    $params = [$direction, $sid];

    $this->moduleHandler->alter(
      'ldap_user_attributes',
      $attributes,
      $params
    );

    return $attributes;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $mappings = $this->currentConfig->get('ldapUserSyncMappings');
    $mappings[$this->direction] = $this->syncMappingsFromForm($form_state->getValues());

    $this->currentConfig
      ->set('ldapUserSyncMappings', $mappings)
      ->save();

    $this->messenger()->addMessage($this->t('User synchronization configuration updated.'));
  }

  /**
   * Extract sync mappings array from mapping table in admin form.
   *
   * @param array $values
   *   Form values.
   *
   * @return array
   *   Returns the relevant mappings.
   */
  protected function syncMappingsFromForm(array $values): array {
    $mappings = [];
    foreach ($values['mappings'] as $row) {
      if (isset($row['source']) &&
        !empty($row['source']) &&
        $row['configured_mapping'] == TRUE &&
        $row['delete'] == FALSE) {
        $events = [];
        foreach ($this->events as $event) {
          if ($row[$event] == 1) {
            $events[] = $event;
          }
        }

        $mapping = new Mapping(
          $row['target'],
          (string) $this->t('User defined mapping for @field', ['@field' => $row['target']]),
          TRUE,
          TRUE,
          $events,
          'ldap_user',
          'ldap_user'
        );
        $mapping->convertBinary((bool) $row['convert']);

        if (!empty($row['user_tokens'])) {
          $mapping->setUserTokens(trim($row['user_tokens']));
        }

        $this->setSpecificMapping($mapping, $row);

        $mappings[$this->sanitizeMachineName($row['target'])] = $mapping->serialize();
      }
    }
    return $mappings;
  }

  /**
   * Sanitize machine name.
   *
   * @param string $string
   *   Field name.
   *
   * @return string
   *   Machine name.
   */
  private function sanitizeMachineName(string $string): string {
    // Replace periods & square brackets.
    return str_replace(['.', '[', ']'], ['-', '', ''], $string);
  }

  /**
   * Set specific mapping.
   *
   * @param \Drupal\ldap_servers\Mapping $mapping
   *   Mapping.
   * @param array $row
   *   Row.
   */
  protected function setSpecificMapping(Mapping $mapping, array $row) {
    // Sub form does it's variant here.
  }

  /**
   * Get mapping form row to LDAP user provisioning mapping admin form table.
   *
   * @param \Drupal\ldap_servers\Mapping $mapping
   *   Is current setting for updates or non-configurable items.
   * @param array $target_fields
   *   Attributes of Drupal user target options.
   * @param int $row_id
   *   Only needed for LDAP.
   */
  protected function getMappingRow(Mapping $mapping, array $target_fields, int $row_id): array {
    // Sub form does it's variant here.
  }

  /**
   * Return the server mappings for the fields.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form State.
   *
   * @return array|bool
   *   Returns the mappings
   */
  protected function getServerMappingFields(FormStateInterface $form_state) {
    $rows = [];
    $user_attribute_options = ['0' => $this->t('Select option')];

    $available_mappings = $this->loadAvailableMappings($this->direction, $this->server);
    // Potential mappings (i.e. fields provided for the user entity) are
    // aggregated so that they can be input for user-defined mappings.
    // The difference being that these available mappings are not *enabled*.
    // Ideally, those would be split into something like a MappingProposal and
    // a MappingRule.
    /** @var \Drupal\ldap_servers\Mapping $mapping */
    foreach ($available_mappings as $target_id => $mapping) {
      if (!empty($mapping->getId()) && $mapping->isConfigurable()) {
        $user_attribute_options[$target_id] = $mapping->getLabel();
      }
    }

    if ($this->direction === self::PROVISION_TO_LDAP) {
      $user_attribute_options['user_tokens'] = '-- user tokens --';
    }

    $index = 0;
    foreach ($available_mappings as $mapping) {
      if ($mapping->isEnabled()) {
        $rows[$index] = $this->getMappingRow($mapping, $user_attribute_options, $index);
        $index++;
      }
    }

    if (empty($form_state->get('row_count'))) {
      $form_state->set('row_count', $index + 1);
    }

    for ($i = $index; $i < $form_state->get('row_count'); $i++) {
      $empty_mapping = new Mapping(
        '',
        '',
        TRUE,
        TRUE,
        [],
        'ldap_user',
        'ldap_user'
      );
      $rows[$i] = $this->getMappingRow($empty_mapping, $user_attribute_options, $i);
    }

    return $rows;
  }

  /**
   * Ajax Callback for the form.
   *
   * @param array $form
   *   The form being passed in.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form element we are changing via ajax
   */
  public function mappingsAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['mappings'];
  }

  /**
   * Functionality for our ajax callback.
   *
   * @param array $form
   *   The form being passed in.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, passed by reference so we can modify.
   */
  public function mappingsAddAnother(array &$form, FormStateInterface $form_state) {
    $form_state->set('row_count', ($form_state->get('row_count') + 1));
    $form_state->setRebuild();
  }

}
