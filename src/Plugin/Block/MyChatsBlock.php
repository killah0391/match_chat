<?php

namespace Drupal\match_chat\Plugin\Block;

use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\Core\Block\BlockBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\match_abuse\Service\BlockCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'My Chats' block.
 *
 * @Block(
 * id = "my_chats_block",
 * admin_label = @Translation("My Chats List"),
 * category = @Translation("Match Chat")
 * )
 */
class MyChatsBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The block checker service.
   *
   * @var \Drupal\match_abuse\Service\BlockCheckerInterface|null
   */
  protected $blockChecker;

  /**
   * Constructs a new MyChatsBlock instance.
   *
   * @param array $configuration
   * A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   * The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   * The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   * The date formatter service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   * The current route match.
   */
  public function __construct( // phpcs:ignore
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    DateFormatterInterface $date_formatter,
    RouteMatchInterface $route_match,
    ?BlockCheckerInterface $block_checker
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->dateFormatter = $date_formatter;
    $this->routeMatch = $route_match; // Add this
    $this->blockChecker = $block_checker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    $block_checker_service = NULL;
    if ($container->has('match_abuse.block_checker')) {
      $block_checker_service = $container->get('match_abuse.block_checker');
    }
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('current_route_match'),
      $block_checker_service
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    // You could add default config here, e.g., number of items.
    return ['label_display' => FALSE]; // Example: Don't display block title by default
  }

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    if ($this->currentUser->isAnonymous()) {
      return [];
    }

    $current_user_id = $this->currentUser->id();
    $thread_storage = $this->entityTypeManager->getStorage('match_thread');
    $user_storage = $this->entityTypeManager->getStorage('user');
    $message_storage = $this->entityTypeManager->getStorage('match_message');

    $threads_data = [];
    // Add current_route_name to cache contexts if the block's content changes based on it.
    // In this case, it does because we exclude a thread.
    $cache_tags = ['match_thread_list', 'user:' . $current_user_id . ':match_threads_list'];

    $query = $thread_storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC');
    $group = $query->orConditionGroup()
      ->condition('user1', $current_user_id)
      ->condition('user2', $current_user_id);
    $query->condition($group);

    // Get the currently viewed thread UUID, if any
    $current_viewed_thread_id = NULL;
    if ($this->routeMatch->getRouteName() == 'match_chat.view_thread') {
      $match_thread_uuid = $this->routeMatch->getParameter('match_thread_uuid');
      if ($match_thread_uuid) {
        $current_threads = $thread_storage->loadByProperties(['uuid' => $match_thread_uuid]);
        if (!empty($current_threads)) {
          /** @var \Drupal\match_chat\Entity\MatchThreadInterface $current_viewed_thread_entity */
          $current_viewed_thread_entity = reset($current_threads);
          $current_viewed_thread_id = $current_viewed_thread_entity->id();
          // Exclude the current thread from the list
          if ($current_viewed_thread_id) {
            $query->condition('id', $current_viewed_thread_id, '<>');
          }
        }
      }
    }

    $thread_ids = $query->execute();

    if (!empty($thread_ids)) {
      // It's possible $thread_ids could become empty after excluding the current thread.
      $threads = $thread_storage->loadMultiple($thread_ids);

      /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
      foreach ($threads as $thread) {
        // This check is now redundant if the query excludes it, but harmless.
        // if ($current_viewed_thread_id && $thread->id() == $current_viewed_thread_id) {
        // continue;
        // }

        $cache_tags[] = 'match_thread:' . $thread->id();

        // ... (rest of the loop logic from your previous MyChatsBlock.php to gather $threads_data)
        // This includes fetching user1, user2, other_user, last message, unread_count etc.
        // For brevity, I'm not repeating the entire loop content here.
        // Ensure you copy it from your working MyChatsBlock.php.

        $user1 = $thread->getUser1();
        $user2 = $thread->getUser2();

        // If $user1 or $user2 from thread are null, skip.
        if (!$user1 || !$user2) {
          continue;
        }

        // Explicitly load user objects again, similar to a "previous" structure.
        // Note: $user_storage was defined earlier in the build() method.
        $loaded_user1 = $user_storage->load($user1->id());
        $loaded_user2 = $user_storage->load($user2->id());

        if (!$loaded_user1 || !$loaded_user2) { // Check again after explicit load
          continue;
        }
        $other_user = ($loaded_user1->id() == $current_user_id) ? $loaded_user2 : $loaded_user1;
        $current_user_object = $user_storage->load($current_user_id);

        // If the blockChecker service is available, check for blocks.
        if ($this->blockChecker && $current_user_object && $other_user) {
          if ($this->blockChecker->isBlockActive($current_user_object, $other_user)) {
            $cache_tags[] = 'match_abuse_block_list'; // Add dependency on block list.
            continue; // Skip this thread if a block is active in either direction.
          }
        }

        // Fetch last message
        $last_message_query = $message_storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('thread_id', $thread->id())
          ->sort('created', 'DESC')
          ->range(0, 1);
        $last_message_ids = $last_message_query->execute();
        $last_message_text = $this->t('No messages yet.');
        $last_message_date = '';
        $last_message_sender_name = '';

        if (!empty($last_message_ids)) {
          /** @var \Drupal\match_chat\Entity\MatchMessageInterface $last_message */
          $last_message = $message_storage->load(reset($last_message_ids));
          if ($last_message) {
            $message_content = $last_message->getMessage();
            if (!empty($last_message->getChatImages())) {
              $image_count = count($last_message->getChatImages());
              $image_text = $this->formatPlural($image_count, '1 image', '@count images');
              if (!empty(trim($message_content ?? ''))) {
                $message_content .= " (" . $image_text . ")";
              } else {
                $message_content = $image_text;
              }
            }
            $last_message_text = $message_content;
            $last_message_date = $this->dateFormatter->format($last_message->getCreatedTime(), 'short');
            $last_message_sender = $last_message->getOwner();
            if ($last_message_sender) {
              $last_message_sender_name = ($last_message_sender->id() == $current_user_id) ? $this->t('You') : $last_message_sender->getDisplayName();
            }
          }
        }

        // Calculate unread messages
        $unread_count = 0;
        $last_seen_timestamp = 0;

        if ($current_user_id == $user1->id()) {
          $last_seen_timestamp = $thread->getUser1LastSeenTimestamp() ?? 0;
        } elseif ($current_user_id == $user2->id()) {
          $last_seen_timestamp = $thread->getUser2LastSeenTimestamp() ?? 0;
        }

        $unread_query = $message_storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('thread_id', $thread->id())
          ->condition('sender', $current_user_id, '<>')
          ->condition('created', $last_seen_timestamp, '>');
        $unread_count = (int) $unread_query->count()->execute();

        $thumb_style = ImageStyle::load('thumbnail');

        $picture_url = NULL;
        if (!$other_user->get('user_picture')->isEmpty() && $other_user->get('user_picture')->entity instanceof File) {
          $user_picture_file = $other_user->get('user_picture')->entity;
          $picture_url = $thumb_style ? $thumb_style->buildUrl($user_picture_file->getFileUri()) : $user_picture_file->createFileUrl(FALSE);
        }
        else {
          $config = \Drupal::config('field.field.user.user.user_picture');
          $default_image = $config->get('settings.default_image');
          if (!empty($default_image['uuid'])) {
            $file = $this->entityTypeManager->getStorage('file')->loadByProperties(['uuid' => $default_image['uuid']]);
            if ($file = reset($file)) {
              $picture_url = $thumb_style ? $thumb_style->buildUrl($file->getFileUri()) : $file->createFileUrl(FALSE);
            }
          }
        }

        $threads_data[] = [
          'thread_uuid' => $thread->uuid(),
          'other_user_name' => $other_user->getDisplayName(),
          'other_user_picture' => $picture_url,
          'last_message_text' => $last_message_text,
          'last_message_date' => $last_message_date,
          'last_message_sender_name' => $last_message_sender_name,
          'thread_url' => Url::fromRoute('match_chat.view_thread', ['match_thread_uuid' => $thread->uuid()])->toString(),
          'unread_count' => $unread_count,
        ];
      }
    }

    return [
      '#theme' => 'match_threads_list',
      '#threads' => $threads_data,
      '#empty_message' => $this->t('No active chats.'), // Message updated for context
      '#attached' => [
        'library' => [
          'match_chat/match_chat_styles',
        ],
      ],
      '#cache' => [
        // Add 'url' context because content changes based on current URL/route parameters.
        'contexts' => ['user', 'url'],
        'tags' => $cache_tags,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts()
  {
    // Add 'url' context here as well, as the logic in build() depends on the current URL.
    return parent::getCacheContexts() + ['user', 'url'];
  }

  /**
   * {@inheritdoc}
   *
   * Add cache tags that this block depends on.
   * These are broad tags; specific entity tags are added in build().
   */
  public function getCacheTags()
  {
    $tags = parent::getCacheTags();
    $tags[] = 'match_thread_list';
    if ($this->currentUser && !$this->currentUser->isAnonymous()) {
      $tags[] = 'user:' . $this->currentUser->id() . ':match_threads_list';
    }
    // Individual match_thread:<id> tags are added dynamically in build() based on query results.
    return $tags;
  }
}
