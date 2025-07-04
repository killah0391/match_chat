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
use Drupal\Core\Ajax\AjaxResponse;
use League\Container\Exception\NotFoundException;
use Drupal\notifier\NotifierService;
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
   * The notifier service.
   *
   * @var \Drupal\notifier\NotifierService
   */
  protected $notifierService;

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
   * @param \Drupal\notifier\NotifierService $notifier_service
   *   The notifier service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    UuidInterface $uuid_generator,
    UserMatchService $user_match_service,
    DateFormatterInterface $date_formatter,
    RendererInterface $renderer,
    NotifierService $notifier_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->uuidGenerator = $uuid_generator;
    $this->userMatchService = $user_match_service;
    $this->dateFormatter = $date_formatter;
    $this->renderer = $renderer;
    $this->notifierService = $notifier_service;
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
      $container->get('date.formatter'),
      $container->get('renderer'),
      $container->get('notifier.service')
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

      // Add a notification for the receiver about the new chat thread.
      $message_for_target = $this->t('@username started a new chat with you.', ['@username' => $currentUserAccount->getDisplayName()]);
      $this->notifierService->addNotification($message_for_target->render(), 'status', $targetUser->id());
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
    $message_form = NULL;
    $chat_settings_popover_form = NULL;

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

    // Get user picture render array.
    $picture_render_array = $this->getUserPictureRenderArray($other_user_entity, 'thumbnail');

    $is_current_user_blocked_by_other = FALSE;
    $current_user_has_blocked_other = FALSE;
    $block_message_for_form_container = '';

    $block_storage = $this->entityTypeManager->getStorage('match_abuse_block');
    // Check if the other user has blocked the current user.
    $blocks_by_other = $block_storage->getQuery()
      ->condition('blocker_uid', $other_user_entity->id())
      ->condition('blocked_uid', $current_user_entity->id())
      ->accessCheck(FALSE) // Check existence, not entity access for viewing the block itself.
      ->execute();
    if (!empty($blocks_by_other)) {
      $is_current_user_blocked_by_other = TRUE;
      $warning_message_text = $this->t('@username has blocked you. You cannot send messages or change chat settings.', ['@username' => $other_user_entity->getAccountName()]);
      // This markup will be used if the user is blocked.
      $block_message_for_form_container = '<div class="alert alert-warning bootstrap-warning-alert-container" role="alert">' . $warning_message_text . '</div>';
    } else {
      // Only check if current user is blocker if they are NOT blocked by other.
      $blocks_by_current_user = $block_storage->getQuery()
        ->condition('blocker_uid', $current_user_entity->id())
        ->condition('blocked_uid', $other_user_entity->id())
        ->accessCheck(FALSE)
        ->execute();
      if (!empty($blocks_by_current_user)) {
        $current_user_has_blocked_other = TRUE;
        $warning_message_text = $this->t('You have blocked @username. You cannot send messages until you unblock them.', ['@username' => $other_user_entity->getAccountName()]);
        $block_message_for_form_container = '<div class="alert alert-warning bootstrap-warning-alert-container" role="alert">' . $warning_message_text . '</div>';
      }
    }

    $messages_render_array = $this->renderMessages($thread, $current_user_entity);

    // Main message input form
    if ($is_current_user_blocked_by_other || $current_user_has_blocked_other) {
      $message_form_render_array = ['#markup' => $block_message_for_form_container];
    } else {
      $message_form_render_array = $this->formBuilder()->getForm(\Drupal\match_chat\Form\MatchMessageForm::class, $thread);
    }

    // Chat Settings Popover Form (includes block user and allow uploads)
    if ($is_current_user_blocked_by_other) { // If current user is blocked by other, hide popover entirely
      $chat_settings_popover_form_render_array = ['#markup' => '']; // Empty if blocked by other
    } else { // Otherwise, show it (it will internally handle its own state)
      $chat_settings_popover_form_render_array = $this->formBuilder()->getForm(ChatSettingsPopoverForm::class, $thread);
    }

    return [
      '#theme' => 'match_thread',
      '#thread' => $thread,
      '#messages_list' => $messages_render_array['messages_list'],
      '#message_form' => $message_form_render_array,
      '#chat_settings_popover_form' => $chat_settings_popover_form_render_array,
      '#current_user_entity' => $current_user_entity,
      '#other_user_entity' => $other_user_entity,
      '#other_user_picture' => $picture_render_array,
      '#current_user_has_blocked_other' => $current_user_has_blocked_other,
      '#is_current_user_blocked_by_other' => $is_current_user_blocked_by_other,
      '#attached' => [
        'library' => [
          'core/drupal.ajax',
          'match_chat/match_chat_styles',
          'match_chat/match_chat_scrolltobottom',
          'match_chat/match_chat_popover',
          'match_abuse/match-abuse-script',
          'match_chat/match_chat_image_zoom',
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
   *
   * @return array
   * A render array containing the messages list.
   */
  public function renderMessages(
    MatchThreadInterface $thread,
    UserInterface $current_user_entity
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
      $actual_list_of_messages['#empty'] = $this->t('No messages yet. Be the first to say hello!');
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
  public function myThreads($match_thread_uuid = NULL)
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

    $selected_thread = NULL;
    $selected_thread_render_array = [];

    // If a specific thread UUID is provided, try to load it.
    if ($match_thread_uuid) {
      $selected_threads_by_uuid = $thread_storage->loadByProperties(['uuid' => $match_thread_uuid]);
      if (!empty($selected_threads_by_uuid)) {
        $selected_thread = reset($selected_threads_by_uuid);
      }
    } elseif (!empty($thread_ids)) {
      // If no UUID provided, select the most recent thread.
      $selected_thread = $thread_storage->load(reset($thread_ids));
    }

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


        // Calculate unread messages.
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

        // Get user picture render array.
        $picture_render_array = $this->getUserPictureRenderArray($other_user, 'profile_picture_thumbnail_100x100');

        $threads_data[] = [
          'thread_uuid' => $thread->uuid(),
          'other_user_name' => $other_user->getDisplayName(),
          'other_user_picture' => $picture_render_array,
          'last_message_text' => $last_message_text,
          'last_message_date' => $last_message_date,
          'last_message_sender_name' => $last_message_sender_name,
          'thread_url' => Url::fromRoute('match_chat.view_thread', ['match_thread_uuid' => $thread->uuid()])->toString(),
          'unread_count' => $unread_count, // Pass the count to Twig
        ];
      }
    }

    // Prepare the selected thread's content if a thread is selected.
    if ($selected_thread) {
      $current_user_entity = ($selected_thread->getUser1()->id() == $current_user_id) ? $selected_thread->getUser1() : $selected_thread->getUser2();
      $other_user_entity = ($selected_thread->getUser1()->id() == $current_user_id) ? $selected_thread->getUser2() : $selected_thread->getUser1();

      $is_current_user_blocked_by_other = FALSE;
      $current_user_has_blocked_other = FALSE;
      $block_message_for_form_container = '';

      $block_storage = $this->entityTypeManager->getStorage('match_abuse_block');
      $blocks_by_other = $block_storage->getQuery()
        ->condition('blocker_uid', $other_user_entity->id())
        ->condition('blocked_uid', $current_user_entity->id())
        ->accessCheck(FALSE)
        ->execute();
      if (!empty($blocks_by_other)) {
        $is_current_user_blocked_by_other = TRUE;
        $warning_message_text = $this->t('@username has blocked you. You cannot send messages or change chat settings.', ['@username' => $other_user_entity->getAccountName()]);
        $block_message_for_form_container = '<div class="alert alert-warning bootstrap-warning-alert-container" role="alert">' . $warning_message_text . '</div>';
      } else {
        $blocks_by_current_user = $block_storage->getQuery()
          ->condition('blocker_uid', $current_user_entity->id())
          ->condition('blocked_uid', $other_user_entity->id())
          ->accessCheck(FALSE)
          ->execute();
        if (!empty($blocks_by_current_user)) {
          $current_user_has_blocked_other = TRUE;
          $warning_message_text = $this->t('You have blocked @username. You cannot send messages until you unblock them.', ['@username' => $other_user_entity->getAccountName()]);
          $block_message_for_form_container = '<div class="alert alert-warning bootstrap-warning-alert-container" role="alert">' . $warning_message_text . '</div>';
        }
      }

      $messages_render_array = $this->renderMessages($selected_thread, $current_user_entity);

      if ($is_current_user_blocked_by_other || $current_user_has_blocked_other) {
        $message_form_render_array = ['#markup' => $block_message_for_form_container];
      } else {
        $message_form_render_array = $this->formBuilder()->getForm(\Drupal\match_chat\Form\MatchMessageForm::class, $selected_thread);
      }

      if ($is_current_user_blocked_by_other) {
        $chat_settings_popover_form_render_array = ['#markup' => ''];
      } else {
        $chat_settings_popover_form_render_array = $this->formBuilder()->getForm(ChatSettingsPopoverForm::class, $selected_thread);
      }

      // Get user picture render array.
      $picture_render_array = $this->getUserPictureRenderArray($other_user_entity, 'thumbnail');

      $selected_thread_render_array = [
        '#theme' => 'match_thread',
        '#thread' => $selected_thread,
        '#messages_list' => $messages_render_array['messages_list'],
        '#message_form' => $message_form_render_array,
        '#chat_settings_popover_form' => $chat_settings_popover_form_render_array,
        '#current_user_entity' => $current_user_entity,
        '#other_user_entity' => $other_user_entity,
        '#other_user_picture' => $picture_render_array,
        '#current_user_has_blocked_other' => $current_user_has_blocked_other,
        '#is_current_user_blocked_by_other' => $is_current_user_blocked_by_other,
        '#attached' => [
          'library' => [
            'core/drupal.ajax',
            'match_chat/match_chat_scrolltobottom',
            'match_chat/match_chat_popover',
            'match_abuse/match-abuse-script',
            'match_chat/match_chat_image_zoom',
          ],
          'drupalSettings' => [
            'match_chat' => [
              'thread_id' => $selected_thread->id(),
              'popover_form_internal_wrapper_id_tpl' => 'chat-settings-popover-form-wrapper-',
            ],
          ],
        ],
      ];
    }

    $build['#theme'] = 'match_threads_list';
    $build['#threads'] = $threads_data;
    $build['#empty_message'] = $this->t('You have no active chats yet.');
    $build['#selected_thread_uuid'] = $selected_thread ? $selected_thread->uuid() : NULL;
    $build['#selected_thread_content'] = $selected_thread_render_array;
    $build['#attached']['library'][] = 'match_chat/match_chat_styles'; // Styles for the entire component.
    $build['#attached']['library'][] = 'match_chat/match_chat_scrolltobottom'; // JS for AJAX thread loading and scrolling.

    $build['#cache'] = [
      'contexts' => ['user'], // The list depends on the current user
      'tags' => $cache_tags,  // Tags to invalidate this cache item
    ];

    return $build;
  }

  /**
   * AJAX callback to load a chat thread's content.
   *
   * @param string $match_thread_uuid
   *   The UUID of the Match Thread to load.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response containing the rendered thread content.
   */
  public function loadThreadAjax($match_thread_uuid)
  {
    $response = new AjaxResponse();
    $thread_storage = $this->entityTypeManager->getStorage('match_thread');
    $threads = $thread_storage->loadByProperties(['uuid' => $match_thread_uuid]);

    if (empty($threads)) {
      $response->addCommand(new \Drupal\Core\Ajax\HtmlCommand('#chat-conversation-area', $this->t('Chat thread not found.')));
      return $response;
    }

    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = reset($threads);

    $current_user_id = $this->currentUser->id();
    $user1 = $thread->getUser1();
    $user2 = $thread->getUser2();

    if (!$user1 || !$user2 || ($current_user_id != $user1->id() && $current_user_id != $user2->id())) {
      $response->addCommand(new \Drupal\Core\Ajax\HtmlCommand('#chat-conversation-area', $this->t('You do not have access to this chat thread.')));
      return $response;
    }

    // Re-use the logic from viewThread to get the render array for the thread content.
    $thread_render_array = $this->viewThread($match_thread_uuid);

    // Extract the relevant part of the render array (the conversation area).
    // This assumes match_thread.html.twig is structured to be a self-contained conversation.
    $rendered_content = $this->renderer->renderRoot($thread_render_array);

    $response->addCommand(new \Drupal\Core\Ajax\HtmlCommand('#chat-conversation-area', $rendered_content));
    // Also update the active class in the thread list.
    $response->addCommand(new \Drupal\Core\Ajax\InvokeCommand('.match-threads-list .list-group-item', 'removeClass', ['active']));
    $response->addCommand(new \Drupal\Core\Ajax\InvokeCommand('[data-thread-uuid="' . $match_thread_uuid . '"]', 'addClass', ['active']));

    return $response;
  }

  /**
   * Gets a render array for a user's picture.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   * @param string $image_style
   *   The image style to use.
   *
   * @return array
   *   A render array for the user's picture.
   */
  private function getUserPictureRenderArray(UserInterface $user, string $image_style): array
  {
    // The 'user_picture' field handles the default image logic automatically
    // when rendered, so we don't need to manually check for an empty field
    // or load the default image configuration.
    return $user->get('user_picture')->view([
      'label' => 'hidden',
      'type' => 'image',
      'settings' => ['image_style' => $image_style],
    ]);
  }
}
