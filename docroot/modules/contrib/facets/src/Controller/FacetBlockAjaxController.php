<?php

namespace Drupal\facets\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Block\BlockManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\PathProcessor\PathProcessorManager;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Defines a controller to load a facet via AJAX.
 */
class FacetBlockAjaxController extends ControllerBase {

  /**
   * The entity storage for block.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * The dynamic router service.
   *
   * @var \Symfony\Component\Routing\Matcher\RequestMatcherInterface
   */
  protected $router;

  /**
   * The path processor service.
   *
   * @var \Drupal\Core\PathProcessor\InboundPathProcessorInterface
   */
  protected $pathProcessor;

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * The block manager service.
   *
   * @var \Drupal\Core\Block\BlockManager
   */
  protected $blockManager;

  /**
   * Constructs a FacetBlockAjaxController object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPath
   *   The current path service.
   * @param \Symfony\Component\Routing\RouterInterface $router
   *   The router service.
   * @param \Drupal\Core\PathProcessor\PathProcessorManager $pathProcessor
   *   The path processor manager.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $currentRouteMatch
   *   The current route match service.
   * @param \Drupal\Core\Block\BlockManager $blockManager
   *   The block manager service.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(RendererInterface $renderer, CurrentPathStack $currentPath, RouterInterface $router, PathProcessorManager $pathProcessor, CurrentRouteMatch $currentRouteMatch, BlockManager $blockManager) {
    $this->storage = $this->entityTypeManager()->getStorage('block');
    $this->renderer = $renderer;
    $this->currentPath = $currentPath;
    $this->router = $router;
    $this->pathProcessor = $pathProcessor;
    $this->currentRouteMatch = $currentRouteMatch;
    $this->blockManager = $blockManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('path.current'),
      $container->get('router'),
      $container->get('path_processor_manager'),
      $container->get('current_route_match'),
      $container->get('plugin.manager.block')
    );
  }

  /**
   * Loads and renders the facet blocks via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the view was not found.
   */
  public function ajaxFacetBlockView(Request $request) {
    $response = new AjaxResponse();

    // Rebuild the request and the current path, needed for facets.
    $path = $request->request->get('facet_link');
    $facets_blocks = $request->request->get('facets_blocks');

    if (empty($path) || empty($facets_blocks)) {
      throw new NotFoundHttpException('No facet link or facet blocks found.');
    }

    // Make sure we are not updating blocks multiple times.
    $facets_blocks = array_unique($facets_blocks);

    $new_request = Request::create($path);
    $request_stack = new RequestStack();
    $processed = $this->pathProcessor->processInbound($path, $new_request);

    $this->currentPath->setPath($processed);
    $request->attributes->add($this->router->matchRequest($new_request));
    $this->currentRouteMatch->resetRouteMatch();
    $request_stack->push($new_request);

    $container = \Drupal::getContainer();
    $container->set('request_stack', $request_stack);
    $active_facet = $request->request->get('active_facet');

    // Build the facets blocks found for the current request and update.
    foreach ($facets_blocks as $block_id => $block_selector) {
      $block_view = '';
      $block_entity = $this->storage->load($block_id);

      if ($block_entity) {
        // Render a block, then add it to the response as a replace command.
        $block_view = $this->entityTypeManager
          ->getViewBuilder('block')
          ->view($block_entity);
      }
      else {
        $instance = $this->blockManager->createInstance($block_id);
        if ($instance) {
          $block_view = $instance->build();
          if (!empty($block_view[0]['#facet']) || !empty($block_view[0][0]['#facet'])) {
            /** @var \Drupal\facets\Entity\Facet $facet */
            $facet = !empty($block_view[0]['#facet']) ? $block_view[0]['#facet'] : $block_view[0][0]['#facet'];
            $widget_type = $facet->get('widget')['type'];
            $block_selector .= ' .facets-widget-' . $widget_type;
          }
        }
      }
      if ($block_view) {
        $block_view = (string) $this->renderer->renderPlain($block_view);
        $response->addCommand(new ReplaceCommand($block_selector, $block_view));
      }
    }

    $response->addCommand(new InvokeCommand('[data-block-plugin-id="' . $active_facet . '"]', 'addClass', ['facet-active']));

    // Update filter summary block.
    $update_summary_block = $request->request->get('update_summary_block');
    if ($update_summary_block) {
      $block_view = NULL;
      $facet_summary_plugin_id = $request->request->get('facet_summary_plugin_id');
      $facet_summary_wrapper_id = $request->request->get('facet_summary_wrapper_id');
      if (!empty($facet_summary_plugin_id)) {
        $instance = $this->blockManager->createInstance($facet_summary_plugin_id);
        if ($instance) {
          $block_view = $instance->build();
        }
        if ($block_view) {
          $block_view = (string) $this->renderer->renderPlain($block_view);
          $response->addCommand(new ReplaceCommand('[data-drupal-facets-summary-id=' . $facet_summary_wrapper_id . ']', $block_view));
        }
      }
    }

    return $response;
  }

}
