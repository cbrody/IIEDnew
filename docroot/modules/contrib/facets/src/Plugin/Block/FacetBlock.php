<?php

namespace Drupal\facets\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\FacetManager\DefaultFacetManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposes a facet rendered as a block.
 *
 * @Block(
 *   id = "facet_block",
 *   deriver = "Drupal\facets\Plugin\Block\FacetBlockDeriver"
 * )
 */
class FacetBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The facet manager.
   *
   * @var \Drupal\facets\FacetManager\DefaultFacetManager
   */
  protected $facetManager;

  /**
   * The entity storage used for facets.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $facetStorage;

  /**
   * Construct a FacetBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\facets\FacetManager\DefaultFacetManager $facet_manager
   *   The facet manager.
   * @param \Drupal\Core\Entity\EntityStorageInterface $facet_storage
   *   The entity storage used for facets.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, DefaultFacetManager $facet_manager, EntityStorageInterface $facet_storage) {
    $this->facetManager = $facet_manager;
    $this->facetStorage = $facet_storage;
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('facets.manager'),
      $container->get('entity_type.manager')->getStorage('facets_facet')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    /** @var \Drupal\facets\FacetInterface $facet */
    $facet = $this->facetStorage->load($this->getDerivativeId());

    // No need to build the facet if it does not need to be visible.
    if ($facet->getOnlyVisibleWhenFacetSourceIsVisible() &&
      (!$facet->getFacetSource() || !$facet->getFacetSource()->isRenderedInCurrentRequest())) {
      return [];
    }

    // Let the facet_manager build the facets.
    $build = $this->facetManager->build($facet);

    if (!empty($build)) {
      // Add extra elements from facet source, for example, ajax scripts.
      // @see Drupal\facets\Plugin\facets\facet_source\SearchApiDisplay
      /* @var \Drupal\facets\FacetSource\FacetSourcePluginInterface $facet_source */
      $facet_source = $facet->getFacetSource();
      $build += $facet_source->buildFacet();

      // Add contextual links only when we have results.
      $build['#contextual_links']['facets_facet'] = [
        'route_parameters' => ['facets_facet' => $facet->id()],
      ];

      if (!empty($build[0]['#attributes']['class']) && in_array('facet-active', $build[0]['#attributes']['class'], TRUE)) {
        $build['#attributes']['class'][] = 'facet-active';
      }
      else {
        $build['#attributes']['class'][] = 'facet-inactive';
      }

      // Add classes needed for ajax.
      if (!empty($build['#use_ajax'])) {
        $build['#attributes']['class'][] = 'block-facets-ajax';
        // The configuration block id isn't always set in the configuration.
        if (isset($this->configuration['block_id'])) {
          $build['#attributes']['class'][] = 'js-facet-block-id-' . $this->configuration['block_id'];
          $build['#attributes']['id'] = Html::getUniqueId($this->configuration['block_id']);
        }
        else {
          $build['#attributes']['class'][] = 'js-facet-block-id-' . $this->pluginId;
          $build['#attributes']['id'] = Html::getUniqueId($this->pluginId);
        }
      }
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // A facet block cannot be cached, because it must always match the current
    // search results, and Search API gets those search results from a data
    // source that can be external to Drupal. Therefore it is impossible to
    // guarantee that the search results are in sync with the data managed by
    // Drupal. Consequently, it is not possible to cache the search results at
    // all. If the search results cannot be cached, then neither can the facets,
    // because they must always match.
    // Fortunately, facet blocks are rendered using a lazy builder (like all
    // blocks in Drupal), which means their rendering can be deferred (unlike
    // the search results, which are the main content of the page, and deferring
    // their rendering would mean sending an empty page to the user). This means
    // that facet blocks can be rendered and sent *after* the initial page was
    // loaded, by installing the BigPipe (big_pipe) module.
    //
    // When BigPipe is enabled, the search results will appear first, and then
    // each facet block will appear one-by-one, in DOM order.
    // See https://www.drupal.org/project/big_pipe.
    //
    // In a future version of Facet API, this could be refined, but due to the
    // reliance on external data sources, it will be very difficult if not
    // impossible to improve this significantly.
    //
    // Note: when using Drupal core's Search module instead of the contributed
    // Search API module, the above limitations do not apply, but for now it is
    // not considered worth the effort to optimize this just for Drupal core's
    // Search.
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    /** @var \Drupal\facets\FacetInterface $facet */
    $facet = $this->facetStorage->load($this->getDerivativeId());

    return ['config' => [$facet->getConfigDependencyName()]];
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    // Checks for a valid form id. Panelizer does not generate one.
    if (isset($form['id']['#value'])) {
      // Save block id to configuration, we do this for loading the original
      // block with ajax.
      $block_id = $form['id']['#value'];
      $this->configuration['block_id'] = $block_id;
    }
  }

}
