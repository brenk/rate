<?php

namespace Drupal\rate;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\votingapi\VoteResultFunctionManager;

/**
 * The rate.entity.vote_widget service.
 */
class RateEntityVoteWidget {

  /**
   * The config factory wrapper to fetch settings.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Account proxy (the current user).
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $accountProxy;

  /**
   * Votingapi result manager.
   *
   * @var \Drupal\votingapi\VoteResultFunctionManager
   */
  protected $resultManager;

  /**
   * Constructs a RateEntityVoteWidget object.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param AccountProxyInterface $account_proxy
   *   The account proxy.
   * @param VoteResultFunctionManager $result_manager
   *   The vote result manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
                              EntityTypeManagerInterface $entity_type_manager,
                              AccountProxyInterface $account_proxy,
                              VoteResultFunctionManager $result_manager) {
    $this->config = $config_factory->get('rate.settings');
    $this->entityTypeManager = $entity_type_manager;
    $this->accountProxy = $account_proxy;
    $this->result_manager = $result_manager;
  }

  /**
   * Returns a renderable array of the updated vote totals.
   *
   * @param string $entity_id
   *   The entity id.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle id.
   *
   * @return array
   *   A renderable array.
   */
  public function buildRateVotingWidget($entity_id, $entity_type_id, $bundle) {
    $output = [];
    $config_id = $entity_type_id . '_' . $bundle . '_available';

    $widget_type = $this->config->get('widget_type', 'number_up_down');
    $rate_theme = 'rate_template_' . $widget_type;
    $enabled_bundles = $this->config->get('enabled_bundles', FALSE);

    if (isset($enabled_bundles[$bundle])) {
      // Set variables.
      $use_ajax = $this->config->get('use_ajax', FALSE);
      $vote_storage = $this->entityTypeManager->getStorage('vote');
      $user_votes = $vote_storage->getUserVotes($this->accountProxy->id(), NULL, NULL, $entity_id);
      $has_voted = (!empty($user_votes)) ? TRUE : FALSE;
      $user_can_vote = $this->accountProxy->hasPermission('cast rate vote on ' . $bundle);

      // Get voting results.
      $results = $this->result_manager->getResults($entity_type_id, $entity_id);

      // Set vote type results for the entity.
      $votes_types = ['up', 'down', 'star1', 'star2', 'star3', 'star4', 'star5'];
      $vote_sums = [];
      foreach ($votes_types as $vote_type) {
        $vote_sums[$vote_type] = 0;
      }
      foreach ($results as $vote_type => $vote_sum) {
        $vote_sums[$vote_type] = $vote_sum['vote_sum'];
      }

      // Set the theme variables.
      if ($this->config->get('widget_type') == 'fivestar') {
        $output['votingapi_links'] = [
          '#theme' => $rate_theme,
          '#star1_votes' => $vote_sums['star1'],
          '#star2_votes' => $vote_sums['star2'],
          '#star3_votes' => $vote_sums['star3'],
          '#star4_votes' => $vote_sums['star4'],
          '#star5_votes' => $vote_sums['star5'],
          '#use_ajax' => $use_ajax,
          '#can_vote' => $user_can_vote,
          '#has_voted' => $has_voted,
          '#entity_id' => $entity_id,
          '#entity_type_id' => $entity_type_id,
          '#attributes' => ['class' => ['links', 'inline']],
          '#cache' => ['tags' => ['vote:' . $bundle . ':' . $entity_id]],
        ];
      }
      else {
        $output['votingapi_links'] = [
          '#theme' => $rate_theme,
          '#up_votes' => $vote_sums['up'],
          '#down_votes' => $vote_sums['down'],
          '#use_ajax' => $use_ajax,
          '#can_vote' => $user_can_vote,
          '#has_voted' => $has_voted,
          '#entity_id' => $entity_id,
          '#entity_type_id' => $entity_type_id,
          '#attributes' => ['class' => ['links', 'inline']],
          '#cache' => ['tags' => ['vote:' . $bundle . ':' . $entity_id]],
        ];
      }
    }

    return $output;
  }

}
