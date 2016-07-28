<?php

namespace Drupal\rate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;

/**
 * Returns responses for Rate routes.
 */
class ResultsController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Display rate voting results views.
   */
  public function results(NodeInterface $node) {
    // @Todo: do we need this?
    // The view in the config folder is what is displayed.
  }

}
