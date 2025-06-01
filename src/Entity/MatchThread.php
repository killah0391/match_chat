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

    $fields['user1_allows_uploads'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('User 1 Allows Uploads'))
      ->setDescription(t('Whether user 1 allows file uploads in this thread.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['user2_allows_uploads'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('User 2 Allows Uploads'))
      ->setDescription(t('Whether user 2 allows file uploads in this thread.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['user1_last_seen_timestamp'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('User 1 Last Seen Timestamp'))
      ->setDescription(t('The time User 1 last saw messages in this thread.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['user2_last_seen_timestamp'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('User 2 Last Seen Timestamp'))
      ->setDescription(t('The time User 2 last saw messages in this thread.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

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
  public function isParticipant(UserInterface $account)
  {
    $user1_id = $this->get('user1')->target_id;
    $user2_id = $this->get('user2')->target_id;
    $account_id = $account->id();

    // Check if the provided account's ID matches either user1 or user2.
    return ($account_id == $user1_id || $account_id == $user2_id);
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

  /**
   * {@inheritdoc}
   */
  public function getUser1AllowsUploads(): bool
  {
    return (bool) $this->get('user1_allows_uploads')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setUser1AllowsUploads(bool $allow): self
  {
    $this->set('user1_allows_uploads', $allow);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUser2AllowsUploads(): bool
  {
    return (bool) $this->get('user2_allows_uploads')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setUser2AllowsUploads(bool $allow): self
  {
    $this->set('user2_allows_uploads', $allow);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function bothParticipantsAllowUploads(): bool
  {
    return $this->getUser1AllowsUploads() && $this->getUser2AllowsUploads();
  }

  /**
   * {@inheritdoc}
   */
  public function getUser1LastSeenTimestamp(): ?int
  {
    return $this->get('user1_last_seen_timestamp')->value ? (int) $this->get('user1_last_seen_timestamp')->value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setUser1LastSeenTimestamp(int $timestamp): self
  {
    $this->set('user1_last_seen_timestamp', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUser2LastSeenTimestamp(): ?int
  {
    return $this->get('user2_last_seen_timestamp')->value ? (int) $this->get('user2_last_seen_timestamp')->value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setUser2LastSeenTimestamp(int $timestamp): self
  {
    $this->set('user2_last_seen_timestamp', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasUserBlockedOther(UserInterface $user): bool {
    $user1 = $this->getUser1();
    $user2 = $this->getUser2();

    if (!$user1 || !$user2) {
      return FALSE; // Should not happen in a valid thread.
    }

    $blocker_uid = NULL;
    $blocked_uid = NULL;

    if ($user->id() == $user1->id()) {
      $blocker_uid = $user1->id();
      $blocked_uid = $user2->id();
    }
    elseif ($user->id() == $user2->id()) {
      $blocker_uid = $user2->id();
      $blocked_uid = $user1->id();
    }
    else {
      // The provided user is not part of this thread.
      return FALSE;
    }

    $block_storage = \Drupal::entityTypeManager()->getStorage('match_abuse_block');
    $query = $block_storage->getQuery()
      ->condition('blocker_uid', $blocker_uid)
      ->condition('blocked_uid', $blocked_uid)
      ->accessCheck(TRUE) // Important for respecting entity access.
      ->count();
    return $query->execute() > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function isUserBlockedByOther(UserInterface $user): bool {
    $user1 = $this->getUser1();
    $user2 = $this->getUser2();

    if (!$user1 || !$user2) {
      return FALSE; // Should not happen in a valid thread.
    }

    $potential_blocker_uid = NULL;
    $potentially_blocked_uid = NULL;

    if ($user->id() == $user1->id()) {
      $potential_blocker_uid = $user2->id();
      $potentially_blocked_uid = $user1->id();
    }
    elseif ($user->id() == $user2->id()) {
      $potential_blocker_uid = $user1->id();
      $potentially_blocked_uid = $user2->id();
    } else {
      // The provided user is not part of this thread.
      return FALSE;
    }

    $block_storage = \Drupal::entityTypeManager()->getStorage('match_abuse_block');
    $query = $block_storage->getQuery()
      ->condition('blocker_uid', $potential_blocker_uid)
      ->condition('blocked_uid', $potentially_blocked_uid)
      ->accessCheck(TRUE) // Important for respecting entity access.
      ->count();
    return $query->execute() > 0;
  }
}
