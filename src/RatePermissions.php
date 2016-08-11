<?php

namespace Drupal\rate;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\Entity\NodeType;
use Drupal\comment\Entity\CommentType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class to return permissions based on entity type for rate module.
 *
 * @package Drupal\rate
 */
class RatePermissions implements ContainerInjectionInterface {
  use StringTranslationTrait;

  /**
   * The config factory wrapper to fetch settings.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Constructs Permissions object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('rate.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * Get permissions for Taxonomy Views Integrator.
   *
   * @return array
   *   Permissions array.
   */
  public function permissions() {
    $permissions = [];
    foreach (NodeType::loadMultiple() as $node_type) {
      $id = 'node_' . $node_type->id() . '_available';
      if (!empty($this->config->get($id))) {
        $permissions['cast rate vote on ' . $node_type->id()] = [
          'title' => $this->t('Enable voting on node of type %node', array('%node' => $node_type->label())),
        ];
      }
    }

    if (\Drupal::moduleHandler()->moduleExists('comment')) {
      foreach (CommentType::loadMultiple() as $comment_type) {
        $id = 'comment_' . $comment_type->id() . '_available';
        if (!empty($this->config->get($id))) {
          $permissions['cast rate vote on ' . $comment_type->id()] = [
            'title' => $this->t('Enable voting on node of type %comment', array('%comment' => $comment_type->label())),
          ];
        }
      }
    }

    return $permissions;
  }

}
