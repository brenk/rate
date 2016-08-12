<?php

namespace Drupal\rate\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\votingapi\Entity\Vote;
use Drupal\votingapi\Entity\VoteType;
use Drupal\rate\RateBotDetector;
use Drupal\rate\RateEntityVoteWidget;

/**
 * Returns responses for Rate routes.
 */
class VoteController extends ControllerBase implements ContainerInjectionInterface {

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
   * Database connection object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Database connection object.
   *
   * @var \Drupal\rate\RateBotDetector
   */
  protected $botDetector;

  /**
   * RateEntityVoteWidget connection object.
   *
   * @var \Drupal\rate\RateEntityVoteWidget
   */
  protected $voteWidget;

  /**
   * Account proxy (the current user).
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $accountProxy;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new search route subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The entity type manager.
   * @param \Drupal\rate\RateBotDetector $bot_detector
   *   The bot detector service.
   * @param \Drupal\rate\RateEntityVoteWidget $vote_widget
   *   The vote widget to display.
   * @param \Drupal\Core\Session\AccountProxyInterface $account_proxy
   *   The current user.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
                              EntityTypeManagerInterface $entity_type_manager,
                              Connection $database,
                              RateBotDetector $bot_detector,
                              RateEntityVoteWidget $vote_widget,
                              AccountProxyInterface $account_proxy,
                              RendererInterface $renderer) {
    $this->config = $config_factory->get('rate.settings');
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->botDetector = $bot_detector;
    $this->voteWidget = $vote_widget;
    $this->accountProxy = $account_proxy;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('rate.bot_detector'),
      $container->get('rate.entity.vote_widget'),
      $container->get('current_user'),
      $container->get('renderer')
    );
  }

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
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
    $is_bot_vote = $this->botDetector->checkIsBot();
    $use_ajax = $this->config->get('use_ajax', FALSE);

    if (!$is_bot_vote && $this->accountProxy->hasPermission('cast rate vote on ' . $entity->bundle())) {
      $vote_storage = $this->entityTypeManager->getStorage('vote');
      $user_votes = $vote_storage->getUserVotes(
        $this->accountProxy->id(),
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
        $invalidate_tags = [
          $entity_type_id . ':' . $entity_id,
          'vote:' . $entity->bundle() . ':' . $entity_id,
        ];
        Cache::invalidateTags($invalidate_tags);

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
      $vote_widget = $this->voteWidget->buildRateVotingWidget($entity_id, $entity_type_id, $entity->bundle());
      $widget_class = '#rate-' . $entity_type_id . '-' . $entity_id;
      $html = $this->renderer->render($vote_widget);
      $response->addCommand(new ReplaceCommand($widget_class, $html));
      return $response;
    }
    // Otherwise, redirect back to destination.
    else {
      $url = $request->getUriForPath($request->getPathInfo());
      return new RedirectResponse($url);
    }
  }

  /**
   * Record a vote.
   *
   * @param string $entity_type_id
   *   Entity type ID such as node.
   * @param int $entity_id
   *   Entity id of the entity type.
   * @param Request $request
   *   Request object that contains redirect path.
   *
   * @return RedirectResponse
   *   Redirect to path provided in request.
   */
  public function undoVote($entity_type_id, $entity_id, Request $request) {
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
    $is_bot_vote = $this->botDetector->checkIsBot();
    $use_ajax = $this->config->get('use_ajax', FALSE);

    if (!$is_bot_vote && $this->accountProxy->hasPermission('cast rate vote on ' . $entity->bundle())) {
      $vote_storage = $this->entityTypeManager->getStorage('vote');
      $user_votes = $vote_storage->getUserVotes(
        $this->accountProxy->id(),
        NULL,
        $entity_type_id,
        $entity_id
      );

      // If a vote has been found, remove it.
      if (!empty($user_votes)) {
        $vote = Vote::load(array_pop($user_votes));
        $vote->delete();
        $invalidate_tags = [
          $entity_type_id . ':' . $entity_id,
          'vote:' . $entity->bundle() . ':' . $entity_id,
        ];
        Cache::invalidateTags($invalidate_tags);
        if (!$use_ajax) {
          drupal_set_message(t('Your vote was removed.'));
        }
      }
      // Otherwise, inform user of previous vote.
      elseif (!$use_ajax) {
        drupal_set_message(
          t('A previous vote was not found.'), 'warning'
        );
      }
    }

    // If Request was AJAX and voting on a node, send AJAX response.
    if ($use_ajax && $entity_type_id == 'node') {
      $response = new AjaxResponse();
      $vote_widget = $this->voteWidget->buildRateVotingWidget($entity_id, $entity_type_id, $entity->bundle());
      $widget_class = '#rate-' . $entity_type_id . '-' . $entity_id;
      $html = $this->renderer->render($vote_widget);
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
