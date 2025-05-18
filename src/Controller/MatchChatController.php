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
    UserMatchService $user_match_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->uuidGenerator = $uuid_generator;
    $this->userMatchService = $user_match_service;
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
      $container->get('user_match.service')
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
}
