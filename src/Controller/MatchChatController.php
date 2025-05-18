<?php

namespace Drupal\match_chat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\user\UserInterface; // Full UserInterface for type hinting
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\match_chat\Entity\MatchThreadInterface;
use Drupal\user_match\Service\UserMatchService; // Assuming this service is still relevant
use Drupal\match_chat\Form\ChatSettingsPopoverForm; // Import the new form class
use League\Container\Exception\NotFoundException;
use Drupal\Core\Datetime\DateFormatterInterface; // Add this
use Drupal\Core\Render\RendererInterface;

/**
 * Controller for Match Chat.
 */
class MatchChatController extends ControllerBase
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user (AccountInterface).
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The user match service.
   *
   * @var \Drupal\user_match\Service\UserMatchService
   */
  protected $userMatchService;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidGenerator;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new MatchChatController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_generator
   * The UUID generator.
   * @param \Drupal\user_match\Service\UserMatchService $user_match_service
   * The user match service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    UuidInterface $uuid_generator,
    UserMatchService $user_match_service,
    DateFormatterInterface $date_formatter, // Add this
    RendererInterface $renderer
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->uuidGenerator = $uuid_generator;
    $this->userMatchService = $user_match_service;
    $this->dateFormatter = $date_formatter; // Add this
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('uuid'),
      $container->get('user_match.service'),
      $container->get('date.formatter'), // Add this
      $container->get('renderer')
    );
  }

  /**
   * Starts a new chat or redirects to an existing one.
   *
   * @param \Drupal\user\UserInterface $user
   * The user to start a chat with.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   * A redirect response to the chat thread.
   */
  public function startChat(UserInterface $user)
  {
    $currentUserAccount = $this->currentUser;
    $targetUser = $user;

    if ($currentUserAccount->id() == $targetUser->id()) {
      $this->messenger()->addWarning($this->t("You cannot start a chat with yourself."));
      return $this->redirect('<front>');
    }

    $acceptsOnlyFromMatches = FALSE;
    if ($targetUser->hasField('field_accept_msg_from_matches') && !$targetUser->get('field_accept_msg_from_matches')->isEmpty()) {
      $acceptsOnlyFromMatches = (bool) $targetUser->get('field_accept_msg_from_matches')->value;
    }

    if ($acceptsOnlyFromMatches) {
      $isMutualMatch = $this->userMatchService->checkForMatch($currentUserAccount->id(), $targetUser->id());
      if (!$isMutualMatch) {
        $this->messenger()->addError($this->t('@username only accepts messages from mutual matches. You cannot start a chat at this time.', ['@username' => $targetUser->getAccountName()]));
        return $this->redirect('<front>');
      }
    }

    $thread_storage = $this->entityTypeManager->getStorage('match_thread');
    $query = $thread_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('user1', $currentUserAccount->id())
      ->condition('user2', $targetUser->id())
      ->range(0, 1);
    $thread_ids_condition1 = $query->execute();

    $thread_ids = [];
    if (!empty($thread_ids_condition1)) {
      $thread_ids = $thread_ids_condition1;
    } else {
      $query = $thread_storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('user1', $targetUser->id())
        ->condition('user2', $currentUserAccount->id())
        ->range(0, 1);
      $thread_ids_condition2 = $query->execute();
      if (!empty($thread_ids_condition2)) {
        $thread_ids = $thread_ids_condition2;
      }
    }

    if (!empty($thread_ids)) {
      $thread_id = reset($thread_ids);
      /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
      $thread = $thread_storage->load($thread_id);
    } else {
      /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
      $thread = $thread_storage->create([
        'user1' => $currentUserAccount->id(),
        'user2' => $targetUser->id(),
      ]);
      $thread->save();
    }

    if (!$thread || !$thread->uuid()) {
      $this->messenger()->addError($this->t('Failed to create or retrieve the chat thread.'));
      return $this->redirect('<front>');
    }

    return new RedirectResponse(Url::fromRoute('match_chat.view_thread', ['match_thread_uuid' => $thread->uuid()])->toString());
  }

  /**
   * Displays a chat thread and messages.
   *
   * @param string $match_thread_uuid
   * The UUID of the Match Thread.
   *
   * @return array
   * A render array for the chat thread page.
   */
  public function viewThread($match_thread_uuid)
  {
    $thread_storage = $this->entityTypeManager->getStorage('match_thread');
    $threads = $thread_storage->loadByProperties(['uuid' => $match_thread_uuid]);

    if (empty($threads)) {
      throw new NotFoundHttpException();
    }

    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = reset($threads);

    $user1 = $thread->getUser1();
    $user2 = $thread->getUser2();
    $current_user_id = $this->currentUser->id();

    if (!$user1 || !$user2) {
      throw new NotFoundHttpException($this->t("Chat participants could not be loaded for this thread."));
    }
    $user1_id = $user1->id();
    $user2_id = $user2->id();

    if ($current_user_id != $user1_id && $current_user_id != $user2_id) {
      throw new NotFoundHttpException();
    }

    // Update last seen timestamp for the current user
    $request_time = \Drupal::time()->getRequestTime();
    $thread_updated = FALSE;
    if ($current_user_id == $user1_id) {
      if ($thread->getUser1LastSeenTimestamp() === NULL || $thread->getUser1LastSeenTimestamp() < $request_time) {
        $thread->setUser1LastSeenTimestamp($request_time);
        $thread_updated = TRUE;
      }
    } elseif ($current_user_id == $user2_id) {
      if ($thread->getUser2LastSeenTimestamp() === NULL || $thread->getUser2LastSeenTimestamp() < $request_time) {
        $thread->setUser2LastSeenTimestamp($request_time);
        $thread_updated = TRUE;
      }
    }

    if ($thread_updated) {
      $thread->save(); // Save the thread with the updated timestamp
      // This also invalidates 'match_thread:<thread_id>' tag
      // And potentially the list tags if the 'changed' field is also updated by some other logic.
      // To be safe, we might want to invalidate list tags here too,
      // if seeing a message should re-sort the "my threads" list.
      // For now, just saving is fine for unread count update on next list load.
    }

    /** @var \Drupal\user\UserInterface $current_user_entity */
    $current_user_entity = ($user1_id == $current_user_id) ? $user1 : $user2;
    /** @var \Drupal\user\UserInterface $other_user_entity */
    $other_user_entity = ($user1_id == $current_user_id) ? $user2 : $user1;

    $has_current_user_blocked_other = $thread->hasUserBlockedOther($current_user_entity);
    $is_current_user_blocked_by_other = $thread->isUserBlockedByOther($current_user_entity);

    $messages_render_array = $this->renderMessages($thread, $current_user_entity, $has_current_user_blocked_other, $is_current_user_blocked_by_other);

    // Main message input form
    $message_form = $this->formBuilder()->getForm(\Drupal\match_chat\Form\MatchMessageForm::class, $thread);

    // Chat Settings Popover Form (includes block user and allow uploads)
    $chat_settings_popover_form = $this->formBuilder()->getForm(ChatSettingsPopoverForm::class, $thread);

    return [
      '#theme' => 'match_thread',
      '#thread' => $thread,
      '#messages_list' => $messages_render_array['messages_list'],
      '#message_form' => $message_form,
      '#chat_settings_popover_form' => $chat_settings_popover_form, // Pass the new form
      '#current_user_entity' => $current_user_entity,
      '#other_user_entity' => $other_user_entity,
      '#has_current_user_blocked_other' => $has_current_user_blocked_other,
      '#is_current_user_blocked_by_other' => $is_current_user_blocked_by_other,
      '#attached' => [
        'library' => [
          'core/drupal.ajax',
          'match_chat/match_chat_styles',
          'match_chat/match_chat_scrolltobottom',
          'match_chat/match_chat_popover', // Ensure this library is defined for popover JS
        ],
        'drupalSettings' => [
          'match_chat' => [
            'thread_id' => $thread->id(),
            // Template for the popover form's internal wrapper ID for JS
            'popover_form_internal_wrapper_id_tpl' => 'chat-settings-popover-form-wrapper-',
          ],
        ],
      ],
    ];
  }

  /**
   * Helper to render messages for a thread.
   *
   * @param \Drupal\match_chat\Entity\MatchThreadInterface $thread
   * The thread entity.
   * @param \Drupal\user\UserInterface $current_user_entity
   * The current user entity.
   * @param bool $has_current_user_blocked_other
   * Whether the current user has blocked the other participant.
   * @param bool $is_current_user_blocked_by_other
   * Whether the current user is blocked by the other participant.
   *
   * @return array
   * A render array containing the messages list.
   */
  public function renderMessages(
    MatchThreadInterface $thread,
    UserInterface $current_user_entity,
    bool $has_current_user_blocked_other,
    bool $is_current_user_blocked_by_other
  ) {
    $message_storage = $this->entityTypeManager->getStorage('match_message');
    $query = $message_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('thread_id', $thread->id())
      ->sort('created', 'ASC');

    $message_ids = $query->execute();
    $messages_for_list_items = [];
    if (!empty($message_ids)) {
      $message_entities = $message_storage->loadMultiple($message_ids);
      $view_builder = $this->entityTypeManager->getViewBuilder('match_message');

      foreach ($message_entities as $message_entity_item) {
        $is_sender = ($message_entity_item->getOwnerId() == $current_user_entity->id());
        $messages_for_list_items[] = [
          '#theme' => 'match_message',
          '#message_entity' => $message_entity_item,
          '#content' => $view_builder->view($message_entity_item, 'default'),
          '#sender_name' => $message_entity_item->getOwner()->getDisplayName(),
          '#message_text' => $message_entity_item->getMessage(),
          '#created_formatted' => \Drupal::service('date.formatter')->format($message_entity_item->getCreatedTime(), 'medium'),
          '#is_sender' => $is_sender,
        ];
      }
    }

    $actual_list_of_messages = [
      '#theme' => 'item_list',
      '#items' => $messages_for_list_items,
      '#title' => NULL,
      '#attributes' => ['class' => ['chat-messages-ul']],
    ];

    // Update empty message based on block status
    if (empty($messages_for_list_items)) {
      if ($has_current_user_blocked_other) {
        $actual_list_of_messages['#empty'] = $this->t('You have blocked this user. No new messages can be exchanged.');
      } elseif ($is_current_user_blocked_by_other) {
        $actual_list_of_messages['#empty'] = $this->t('This user has blocked you. No new messages can be exchanged.');
      } else {
        $actual_list_of_messages['#empty'] = $this->t('No messages yet. Be the first to say hello!');
      }
    }


    return [
      'messages_list' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'match-chat-messages-wrapper',
          'class' => ['chat-messages-list-inner-content'],
        ],
        'the_list_itself' => $actual_list_of_messages,
      ],
    ];
  }

  /**
   * Title callback for the thread view page.
   *
   * @param string $match_thread_uuid
   * The UUID of the Match Thread.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   * The page title.
   */
  public function getThreadTitle($match_thread_uuid)
  {
    $threads = $this->entityTypeManager->getStorage('match_thread')->loadByProperties(['uuid' => $match_thread_uuid]);
    if (!empty($threads)) {
      /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
      $thread = reset($threads);
      if ($thread) {
        $user1 = $thread->getUser1();
        $user2 = $thread->getUser2();
        $current_user_id = $this->currentUser->id();

        if ($user1 && $user2) {
          $other_user = ($user1->id() == $current_user_id) ? $user2 : $user1;
          return $this->t('Chat with @username', ['@username' => $other_user->getDisplayName()]);
        }
      }
    }
    return $this->t('Chat');
  }

  /**
   * Displays a list of chat threads for the current user with the last message.
   *
   * @return array
   * A render array for the "My Chats" page.
   */
  public function myThreads()
  {
    $current_user_id = $this->currentUser->id();
    $thread_storage = $this->entityTypeManager->getStorage('match_thread');
    $message_storage = $this->entityTypeManager->getStorage('match_message');
    // user_storage is not directly used in the loop for data gathering,
    // but user objects are loaded via thread->getUser1/2()

    $threads_data = [];
    $cache_tags = ['match_thread_list', 'user:' . $current_user_id . ':match_threads_list'];

    $query = $thread_storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC');
    $group = $query->orConditionGroup()
      ->condition('user1', $current_user_id)
      ->condition('user2', $current_user_id);
    $query->condition($group);

    $thread_ids = $query->execute();

    if (!empty($thread_ids)) {
      $threads = $thread_storage->loadMultiple($thread_ids);

      /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
      foreach ($threads as $thread) {
        $cache_tags[] = 'match_thread:' . $thread->id();

        $user1 = $thread->getUser1();
        $user2 = $thread->getUser2();

        if (!$user1 || !$user2) {
          \Drupal::logger('match_chat')->warning('Skipping thread ID @tid in myThreads due to incomplete participant data.', ['@tid' => $thread->id()]);
          continue;
        }

        $other_user = ($user1->id() == $current_user_id) ? $user2 : $user1;

        // Get last message (same logic as before)
        // ... (ensure your full last message logic is here)
        $last_message_query = $message_storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('thread_id', $thread->id())
          ->sort('created', 'DESC')
          ->range(0, 1);
        $last_message_ids = $last_message_query->execute();
        $last_message_text = $this->t('No messages yet.');
        $last_message_date = '';
        $last_message_sender_name = '';
        // ... (full logic for $last_message_text, $last_message_date, $last_message_sender_name)
        if (!empty($last_message_ids)) {
          /** @var \Drupal\match_chat\Entity\MatchMessageInterface $last_message */
          $last_message = $message_storage->load(reset($last_message_ids));
          if ($last_message) {
            $message_content = $last_message->getMessage();
            if (!empty($last_message->getChatImages())) {
              $image_count = count($last_message->getChatImages());
              $image_text = $this->formatPlural($image_count, '1 image', '@count images');
              if (!empty(trim($message_content))) {
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

        // Only count if last_seen_timestamp is set (meaning user has viewed the thread at least once)
        // Or, count all messages from others if never seen. For this implementation,
        // if last_seen_timestamp is 0 (default/never seen), all messages from other user are "new".
        $unread_query = $message_storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('thread_id', $thread->id())
          ->condition('sender', $current_user_id, '<>') // Messages not from the current user
          ->condition('created', $last_seen_timestamp, '>'); // Messages newer than last seen
        $unread_count = (int) $unread_query->count()->execute();

        $threads_data[] = [
          'thread_uuid' => $thread->uuid(),
          'other_user_name' => $other_user->getDisplayName(),
          'other_user_picture' => $other_user->hasField('user_picture') && !$other_user->get('user_picture')->isEmpty() ? $this->entityTypeManager->getViewBuilder('user')->view($other_user, 'compact') : NULL,
          'last_message_text' => $last_message_text,
          'last_message_date' => $last_message_date,
          'last_message_sender_name' => $last_message_sender_name,
          'thread_url' => Url::fromRoute('match_chat.view_thread', ['match_thread_uuid' => $thread->uuid()])->toString(),
          'unread_count' => $unread_count, // Pass the count to Twig
        ];
      }
    }

    $build['#theme'] = 'match_threads_list';
    $build['#threads'] = $threads_data;
    $build['#empty_message'] = $this->t('You have no active chats yet.');
    $build['#attached']['library'][] = 'match_chat/match_chat_styles';

    $build['#cache'] = [
      'contexts' => ['user'], // The list depends on the current user
      'tags' => $cache_tags,  // Tags to invalidate this cache item
    ];

    return $build;
  }
}
