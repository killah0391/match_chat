<?php

namespace Drupal\match_chat\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface; // Keep this for type hinting if you override owner methods

/**
 * Defines the Match Thread entity.
 *
 * @ContentEntityType(
 * id = "match_thread",
 * label = @Translation("Match Thread"),
 * handlers = {
 * "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 * "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 * "views_data" = "Drupal\views\EntityViewsData",
 * "form" = {
 * "default" = "Drupal\Core\Entity\ContentEntityForm",
 * "add" = "Drupal\Core\Entity\ContentEntityForm",
 * "edit" = "Drupal\Core\Entity\ContentEntityForm",
 * "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 * },
 * "route_provider" = {
 * "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 * },
 * "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 * },
 * base_table = "match_thread",
 * admin_permission = "administer site configuration",
 * entity_keys = {
 * "id" = "id",
 * "uuid" = "uuid",
 * "owner" = "user1",
 * "label" = "id",
 * },
 * links = {
 * "canonical" = "/chat/thread/{match_thread}",
 * "add-form" = "/admin/structure/match_thread/add",
 * "edit-form" = "/admin/structure/match_thread/{match_thread}/edit",
 * "delete-form" = "/admin/structure/match_thread/{match_thread}/delete",
 * "collection" = "/admin/structure/match_thread",
 * },
 * fieldable = TRUE,
 * )
 */
class MatchThread extends ContentEntityBase implements MatchThreadInterface
{

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
  {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['user1'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel((string) t('User 1 (Owner)')) // Cast to string
      ->setDescription((string) t('The first user in the thread, designated as owner.')) // Cast to string
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setDefaultValueCallback('static::getCurrentUserId')
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['user2'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel((string) t('User 2')) // Cast to string
      ->setDescription((string) t('The second user in the thread.')) // Cast to string
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel((string) t('Created')) // Cast to string
      ->setDescription((string) t('The time that the entity was created.')); // Cast to string

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel((string) t('Changed')) // Cast to string
      ->setDescription((string) t('The time that the entity was last edited.')); // Cast to string

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getUuid()
  {
    return $this->get('uuid')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime()
  {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp)
  {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUser1()
  {
    return $this->get('user1')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setUser1($uid)
  { // Parameter should be $uid or UserInterface
    $this->set('user1', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUser2()
  {
    return $this->get('user2')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setUser2($uid)
  { // Parameter should be $uid or UserInterface
    $this->set('user2', $uid);
    return $this;
  }

  /**
   * Default value callback for 'user1' base field definition.
   */
  public static function getCurrentUserId()
  {
    return [\Drupal::currentUser()->id()];
  }
}
