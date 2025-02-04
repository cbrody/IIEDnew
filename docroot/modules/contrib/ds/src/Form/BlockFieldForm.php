<?php

namespace Drupal\ds\Form;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configure block fields.
 */
class BlockFieldForm extends FieldFormBase implements ContainerInjectionInterface {

  /**
   * The type of the dynamic ds field.
   */
  const TYPE = 'block';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ds_field_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $field_key = '') {
    $form = parent::buildForm($form, $form_state, $field_key);

    $field = $this->field;

    $manager = \Drupal::service('plugin.manager.block');

    $blocks = [];
    foreach ($manager->getDefinitions() as $plugin_id => $plugin_definition) {
      $blocks[$plugin_id] = $plugin_definition['admin_label'];
    }
    asort($blocks);

    $form['block_identity']['block'] = [
      '#type' => 'select',
      '#options' => $blocks,
      '#title' => $this->t('Block'),
      '#required' => TRUE,
      '#default_value' => isset($field['properties']['block']) ? $field['properties']['block'] : '',
    ];

    $form['use_block_title'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use block title as the field label'),
      '#default_value' => isset($field['properties']['use_block_title']) ? $field['properties']['use_block_title'] : FALSE,
      '#weight' => 90,
    ];

    $form['add_block_wrappers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add block wrappers and classes'),
      '#default_value' => isset($field['properties']['add_block_wrappers']) ? $field['properties']['add_block_wrappers'] : FALSE,
      '#description' => $this->t('Render using the block theme hook to add the block wrappers and clases.'),
      '#weight' => 91,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getProperties(FormStateInterface $form_state) {
    $properties['block'] = $form_state->getValue('block');

    // Preserve existing block config.
    $field_key = $form_state->getValue('id');
    $field = $this->config('ds.field.' . $field_key)->get();
    if (isset($field['properties']) && ($field['properties']['block'] == $properties['block'])) {
      $properties = $field['properties'];
    }

    // Save configuration.
    $properties['use_block_title'] = $form_state->getValue('use_block_title');
    $properties['add_block_wrappers'] = $form_state->getValue('add_block_wrappers');

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function getType() {
    return BlockFieldForm::TYPE;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeLabel() {
    return 'Block field';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    // Create an instance of the block to find out if it has a config form.
    // Redirect to the block config form if there is one.
    /* @var $block \Drupal\Core\Block\BlockPluginInterface */
    $manager = \Drupal::service('plugin.manager.block');
    $block_id = $this->field['properties']['block'];
    $block = $manager->createInstance($block_id);

    // Use a separate function so we don't override $form_state.
    $block_config_form = $this->getBlockConfigForm($block);

    if ($block_config_form) {
      $url = new Url('ds.manage_block_field_config', ['field_key' => $this->field['id']]);
      $form_state->setRedirectUrl($url);
    }

    // Invalidate all blocks.
    Cache::invalidateTags(['config:ds.block_base']);
  }

  /**
   * Returns the block form for the block plugin.
   *
   * We use this to check if the block has a form to redirect to.
   *
   * @param \Drupal\Core\Block\BlockPluginInterface $block_plugin
   *   The block plugin to check.
   *
   * @return array
   *   The form render array.
   *   It will be empty is the block didn't inject anything.
   *
   * @see \Drupal\Core\Block\BlockPluginTrait::blockForm()
   */
  public function getBlockConfigForm(BlockPluginInterface $block_plugin) {
    // Inject default theme in form state (Site branding needs it for instance).
    $form_state = new FormState();
    $default_theme = $this->config('system.theme')->get('default');
    $form_state->set('block_theme', $default_theme);
    $block_config_form = $block_plugin->blockForm([], $form_state);
    return $block_config_form;
  }

}
