<?php

namespace Drupal\rate;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxy;

/**
 * The rate.entity_vote_totals service.
 */
class RateEntityVoteWidget {

  /**
   * The entity to get votes from.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * Current user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $user;

  /**
   * Constructs a RateEntityVoteTotals object.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to get votes from.
   * @param \Drupal\Core\Session\AccountProxy $user
   *   The user who is voting.
   */
  public function __construct(EntityInterface $entity, AccountProxy $user) {
    $this->entity = $entity;
    $this->user = $user;
  }

  /**
   * Returns a renderable array of the updated vote totals.
   *
   * @return array
   *   A renderable array.
   */
  public function getEntityVoteTotals() {
    $output = [];
    $config = \Drupal::config('rate.settings');
    $config_id = $this->entity->getEntityTypeId() . '_' . $this->entity->bundle() . '_available';

    $widget_type = $config->get('widget_type', 'number_up_down');
    $rate_theme = 'rate_template_' . $widget_type;

    if ($config->get($config_id, FALSE)) {
      $entity_id = $this->entity->id();
      $entity_type_id = $this->entity->getEntityTypeId();
      $use_ajax = $config->get('use_ajax', FALSE);
      $user_can_vote = $this->user->hasPermission('cast rate vote');

      if ($config->get('widget_type') == 'fivestar') {
        $output['votingapi_links'] = [
          '#theme' => $rate_theme,
          '#star1_votes' => $this->entity->star1,
          '#star2_votes' => $this->entity->star2,
          '#star3_votes' => $this->entity->star3,
          '#star4_votes' => $this->entity->star4,
          '#star5_votes' => $this->entity->star5,
          '#use_ajax' => $use_ajax,
          '#can_vote' => $user_can_vote,
          '#entity_id' => $entity_id,
          '#entity_type_id' => $entity_type_id,
          '#attributes' => ['class' => ['links', 'inline']],
        ];
      }
      else {
        $output['votingapi_links'] = [
          '#theme' => $rate_theme,
          '#up_votes' => $this->entity->up,
          '#down_votes' => $this->entity->down,
          '#use_ajax' => $use_ajax,
          '#can_vote' => $user_can_vote,
          '#entity_id' => $entity_id,
          '#entity_type_id' => $entity_type_id,
          '#attributes' => ['class' => ['links', 'inline']],
        ];
      }
    }

    return $output;
  }

}
