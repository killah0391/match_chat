<?php

namespace Drupal\match_chat\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface; // Keep this for type hinting

/**
 * Defines the Match Message entity.
 *
 * @ContentEntityType(
 * id = "match_message",
 * label = @Translation("Match Message"),
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
 * base_table = "match_message",
 * admin_permission = "administer site configuration",
 * entity_keys = {
 * "id" = "id",
 * "uuid" = "uuid",
 * "owner" = "sender",
 * "label" = "id",
 * },
 * links = {
 * "canonical" = "/admin/structure/match_message/{match_message}",
 * "add-form" = "/admin/structure/match_message/add",
 * "edit-form" = "/admin/structure/match_message/{match_message}/edit",
 * "delete-form" = "/admin/structure/match_message/{match_message}/delete",
 * "collection" = "/admin/structure/match_message",
 * },
 * field_ui_base_route = "entity.match_message.settings",
 * fieldable = TRUE,
 * )
 */
class MatchMessage extends ContentEntityBase implements MatchMessageInterface
{

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
  {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['sender'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel((string) t('Sender')) // Cast to string
      ->setDescription((string) t('The user who sent the message.')) // Cast to string
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setDefaultValueCallback('static::getCurrentUserId') // Use static:: for self-class static methods
      ->setDisplayOptions('view', [
        'label' => 'above',
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

    // This makes EntityOwnerTrait use the 'sender' field.
    // The 'owner' key in entity_keys should point to 'sender'.
    // $fields['owner'] = $fields['sender']; // This line is not strictly needed if entity_keys['owner'] = 'sender'

    $fields['thread_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel((string) t('Thread')) // Cast to string
      ->setDescription((string) t('The chat thread this message belongs to.')) // Cast to string
      ->setSetting('target_type', 'match_thread') // Ensure this matches your thread entity ID
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['message'] = BaseFieldDefinition::create('text_long')
      ->setLabel((string) t('Message')) // Cast to string
      ->setDescription((string) t('The content of the chat message.')) // Cast to string
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'text_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['chat_images'] = BaseFieldDefinition::create('image') // Use 'image' type directly
      ->setLabel((string) t('Chat Images'))
      ->setDescription((string) t('Images attached to the chat message.'))
      ->setCardinality(3) // Max 3 images
      ->setSettings([
        'file_directory' => 'match_chat_images/[date:custom:Y]-[date:custom:m]',
        'alt_field' => FALSE, // No alt field required for chat images by default
        'alt_field_required' => FALSE,
        'title_field' => FALSE,
        'title_field_required' => FALSE,
        'max_resolution' => '4000x4000', // Example max resolution
        'min_resolution' => '', // Example min resolution
        'default_image' => [ // No default image
          'uuid' => '',
          'alt' => '',
          'title' => '',
          'width' => NULL,
          'height' => NULL,
        ],
        'file_extensions' => 'png gif jpg jpeg', // Allowed extensions
        'max_filesize' => '2 MB', // Max filesize per image
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'image', // Or a custom image style
        'weight' => 1,
        'settings' => ['image_style' => 'medium'], // Example: use 'medium' image style
      ])
      ->setDisplayOptions('form', [ // This won't be used by our custom form directly
        'type' => 'image_image',    // but good for entity forms if used elsewhere
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);


    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel((string) t('Sent')) // Cast to string
      ->setDescription((string) t('The time that the message was sent.')); // Cast to string

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel((string) t('Changed')) // Cast to string
      ->setDescription((string) t('The time that the message was last edited.')); // Cast to string

    return $fields;
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
  public function getMessage()
  {
    return $this->get('message')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMessage($message)
  {
    $this->set('message', $message);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getThreadId()
  {
    return $this->get('thread_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setThreadId($thread_id)
  {
    $this->set('thread_id', $thread_id);
    return $this;
  }

  /**
   * Default value callback for 'sender' base field definition.
   */
  public static function getCurrentUserId()
  {
    return [\Drupal::currentUser()->id()];
  }

  public function getChatImages()
  {
      return $this->get('chat_images')->referencedEntities();
    }

    public function setChatImages(array $fids) {
      $this->set('chat_images', $fids);
      return $this;
    }
  }
