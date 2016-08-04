<?php

namespace Drupal\rate\Controller;

use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\rate\RateBotDetector;
use Drupal\rate\RateEntityVoteWidget;
use Drupal\votingapi\Entity\Vote;
use Drupal\node\Entity\Node;
use Drupal\votingapi\Entity\VoteType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Rate routes.
 */
class VoteController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Record a vote.
   *
   * @param string $entity_type_id
   *   Entity type ID such as node.
   * @param string $vote_type_id
   *   Vote type id.
   * @param int $entity_id
   *   Entity id of the entity type.
   * @param Request $request
   *   Request object that contains redirect path.
   *
   * @return RedirectResponse
   *   Redirect to path provided in request.
   */
  public function vote($entity_type_id, $vote_type_id, $entity_id, Request $request) {
    $databae = \Drupal::database();
    $user = \Drupal::currentUser();

    $bot_detector = new RateBotDetector($databae);
    $is_bot_vote = $bot_detector->checkIsBot();

    $config = \Drupal::config('rate.settings');
    $use_ajax = $config->get('use_ajax', FALSE);

    if (!$is_bot_vote && $user->hasPermission('cast rate vote')) {
      $vote_storage = \Drupal::entityTypeManager()->getStorage('vote');
      $user_votes = $vote_storage->getUserVotes(
        $user->id(),
        $vote_type_id,
        $entity_type_id,
        $entity_id
      );

      // If user hasn't voted, save the vote.
      if (empty($user_votes)) {
        $vote_type = VoteType::load($vote_type_id);
        $vote = Vote::create(['type' => $vote_type_id]);
        $vote->setVotedEntityId($entity_id);
        $vote->setVotedEntityType($entity_type_id);
        $vote->setValueType($vote_type->getValueType());
        $vote->setValue(1);
        $vote->save();

        if (!$use_ajax) {
          drupal_set_message(t('Your :type vote was added.', [
            ':type' => $vote_type_id,
          ]));
        }
      }
      // Otherwise, inform user of previous vote.
      elseif (!$use_ajax) {
        drupal_set_message(
          t('You are not allowed to vote the same way multiple times.'), 'warning'
        );
      }
    }

    // If Request was AJAX and voting on a node, send AJAX response.
    if ($use_ajax && $entity_type_id == 'node') {
      $response = new AjaxResponse();
      $node = Node::load($entity_id);
      $rate_totals = new RateEntityVoteWidget($node, $user);
      $rate_totals_output = $rate_totals->getEntityVoteTotals();
      $widget_class = $config->get('widget_type', 'number_up_down');
      $widget_class = '.rate-widget-' . str_ireplace('_', '-', $widget_class);
      $html = \Drupal::service('renderer')->render($rate_totals_output);
      $response->addCommand(new ReplaceCommand($widget_class, $html));
      return $response;
    }
    // Otherwise, redirect back to destination.
    else {
      $url = $request->getUriForPath($request->getPathInfo());
      return new RedirectResponse($url);
    }
  }

}
