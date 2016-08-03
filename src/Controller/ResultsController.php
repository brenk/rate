<?php

namespace Drupal\rate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * Returns responses for Rate routes.
 */
class ResultsController extends ControllerBase {

  /**
   * Display rate voting results views.
   */
  public function results(NodeInterface $node) {
    $page[] = views_embed_view('rate_results', 'results_block', $node->id());
    $page[] = views_embed_view('rate_results', 'summary_block', $node->id());
    return $page;
  }

}
