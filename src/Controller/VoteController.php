<?php

namespace Drupal\rate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\votingapi\Entity\Vote;
use Drupal\votingapi\Entity\VoteType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Rate routes.
 */
class VoteController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Check if the given IP is a local IP-address.
   *
   * @param string $ip
   *   IP address to check.
   *
   * @return bool
   *   True if local IP; false otherwise.
   */
  private function botsIsLocal($ip) {
    if (preg_match('/^([012]?[0-9]{2})\\./', $ip, $match)) {
      switch ($match[1]) {
        case 10:
        case 127:
        case 172:
        case 192:
          return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Save IP address as a bot.
   *
   * @param string $ip
   *   IP address to register as bot.
   */
  private function botsRegisterBot($ip) {
    // @Todo: update this.
    db_insert('rate_bot_ip')->fields(array('ip' => $ip))->execute();
  }

  /**
   * Check if the IP-address exists in the local bot database.
   *
   * @param string $ip
   *   IP Address to check.
   *
   * @return bool
   *   TRUE if IP is in database; false otherwise.
   */
  private function botsCheckIp($ip) {
    // @Todo: update this.
    return (bool) db_select('rate_bot_ip', 'rbi')
      ->fields('rbi', array('id'))
      ->condition('rbi.ip', $ip)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Check if the given user agent matches the local bot database.
   *
   * @param string $agent
   *   User agent to check.
   *
   * @return bool
   *   True if match found; false otherwise.
   */
  private function botsCheckAgent($agent) {
    $sql = 'SELECT 1 FROM {rate_bot_agent} WHERE :agent LIKE pattern LIMIT 1';
    // @Todo: update this.
    return (bool) db_query($sql, array(':agent' => $agent))->fetchField();
  }

  /**
   * Check the number of votes between now and $interval seconds ago.
   *
   * @param string $ip
   *   IP address to check.
   * @param int $interval
   *   Interval in seconds.
   *
   * @return int
   *   Number of votes between not and internval.
   */
  private function botsCheckThreshold($ip, $interval) {
    $sql = 'SELECT COUNT(*) FROM {votingapi_vote} WHERE vote_source = :ip AND timestamp > :time';
    return db_query($sql, array(':ip' => $ip, ':time' => REQUEST_TIME - $interval))->fetchField();
  }

  /**
   * Check if botscout thinks the IP is a bot.
   *
   * @param string $ip
   *   IP to check.
   *
   * @return bool
   *   True if botscout returns a positive; false otherwise.
   */
  private function botsCheckBotscout($ip) {
    $config = \Drupal::config('rate.settings');
    $key = $config->get('botscout_key');

    if ($key) {
      $uri = "http://botscout.com/test/?ip=$ip&key=$key";

      try {
        $response = \Drupal::httpClient()->get($uri, array('headers' => array('Accept' => 'text/plain')));
        $data = (string) $response->getBody();
        $status_code = $response->getStatusCode();
        if (!empty($data) && $status_code == 200) {
          if ($data{0} == 'Y') {
            return TRUE;
          }
        }
      }
      catch (RequestException $e) {
        drupal_set_message(t('An error occurred contacting BotScout.'), 'warning');
        watchdog_exception('rate', $e);
      }
    }

    return FALSE;
  }

  /**
   * Check if the current user is blocked.
   *
   * This function will first check if the user is already known to be a bot.
   * If not, it will check if we have valid reasons to assume the user is a bot.
   *
   * @return bool
   *   True if bot detected; false otherwise.
   */
  private function botsIsBot() {
    $ip = \Drupal::request()->getClientIp();
    $agent = $_SERVER['HTTP_USER_AGENT'];

    if ($this->botsIsLocal($ip)) {
      // The IP-address is a local IP-address. This is probably because of
      // misconfigured proxy servers. Do only the user agent check.
      return $this->botsCheckAgent($agent);
    }

    if ($this->botsCheckIp($ip)) {
      return TRUE;
    }

    if ($this->botsCheckAgent($agent)) {
      // Identified as a bot by its user agent. Register this bot by IP-address
      // as well, in case this bots uses multiple agent strings.
      $this->botsRegisterBot($ip);
      return TRUE;
    }

    $config = \Drupal::config('rate.settings');
    $threshold = $config->get('bot_minute_threshold', 25);

    if ($threshold && ($this->botsCheckThreshold($ip, 60) > $threshold)) {
      $this->botsRegisterBot($ip);
      return TRUE;
    }

    $threshold = $config->get('bot_hour_threshold', 250);

    // Always count, even if threshold is disabled. This is to determine if we
    // can skip the BotScout check.
    $count = $this->botsCheckThreshold($ip, 3600);
    if ($threshold && ($count > $threshold)) {
      $this->botsRegisterBot($ip);
      return TRUE;
    }

    if (!$count && $this->botsCheckBotscout($ip)) {
      $this->botsRegisterBot($ip);
      return TRUE;
    }

    return FALSE;
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
    $is_bot_vote = $this->botsIsBot();
    if (!$is_bot_vote) {
      $vote_storage = \Drupal::entityTypeManager()->getStorage('vote');
      $user_votes = $vote_storage->getUserVotes(
        \Drupal::currentUser()->id(),
        $vote_type_id,
        $entity_type_id,
        $entity_id
      );

      if (empty($user_votes)) {
        $vote_type = VoteType::load($vote_type_id);
        $vote = Vote::create(['type' => $vote_type_id]);
        $vote->setVotedEntityId($entity_id);
        $vote->setVotedEntityType($entity_type_id);
        $vote->setValueType($vote_type->getValueType());
        $vote->setValue(1);
        $vote->save();

        drupal_set_message(t('Your :type vote was added.', [
          ':type' => $vote_type_id,
        ]));
      }
      else {
        drupal_set_message(
          t('You are not allowed to vote the same way multiple times.'), 'warning'
        );
      }
    }
    $url = $request->getUriForPath($request->getPathInfo());
    return new RedirectResponse($url);
  }

}
