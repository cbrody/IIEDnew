<?php

namespace Drupal\Tests\facets_summary\Unit\Plugin\Processor;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\facets_summary\Entity\FacetsSummary;
use Drupal\facets_summary\Plugin\facets_summary\processor\ResetFacetsProcessor;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Tests\UnitTestCase;

/**
 * Class ResetFacetsProcessorTest.
 *
 * @group facets
 * @coversDefaultClass \Drupal\facets_summary\Plugin\facets_summary\processor\ResetFacetsProcessor
 */
class ResetFacetsProcessorTest extends UnitTestCase {

  /**
   * The processor we're testing.
   *
   * @var \Drupal\facets_summary\Processor\ProcessorInterface|\Drupal\facets_summary\Processor\BuildProcessorInterface
   */
  protected $processor;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $string_translation = $this->prophesize(TranslationInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $string_translation->reveal());
    $requestStack = $this->getMockBuilder(RequestStack::class)
      ->disableOriginalConstructor()
      ->getMock();
    $container->set('request_stack', $requestStack);
    \Drupal::setContainer($container);

    $this->processor = new ResetFacetsProcessor(['settings' => ['link_text' => 'Text', 'clear_string' => FALSE]], 'reset_facets', [], $requestStack);
  }

  /**
   * Tests the is hidden method.
   *
   * @covers ::isHidden
   */
  public function testIsHidden() {
    $this->assertFalse($this->processor->isHidden());
  }

  /**
   * Tests the is locked method.
   *
   * @covers ::isLocked
   */
  public function testIsLocked() {
    $this->assertFalse($this->processor->isLocked());
  }

  /**
   * Tests the build method.
   *
   * @covers ::build
   */
  public function testBuildWithEmptyItems() {
    $summary = new FacetsSummary([], 'facets_summary');
    $summary->setFacetSourceId('foo');
    $config = [
      'processor_id' => 'reset_facets',
      'weights' => [],
      'settings' => ['link_text' => 'Text'],
    ];
    $summary->addProcessor($config);

    $result = $this->processor->build($summary, ['foo'], []);
    $this->assertSame('array', gettype($result));
  }

}
