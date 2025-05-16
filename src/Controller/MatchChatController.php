<?php

namespace Drupal\match_chat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\match_chat\Entity\MatchThreadInterface;
use Drupal\user_match\Service\UserMatchService;

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
   * The current user.
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
   * @var \Drupal\Component\Uuid\UuidInterface  // UPDATE PHPDOC
   */
  protected $uuidGenerator;

  /**
   * Constructs a new MatchChatController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_generator  // UPDATE TYPE HINT
   * The UUID generator.
   * @param \Drupal\user_match\Service\UserMatchService $user_match_service
   * The user match service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, UuidInterface $uuid_generator, UserMatchService $user_match_service,)
  {
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
  { // Ensure correct UserInterface alias if needed
    $currentUserAccount = $this->currentUser; // Use $this->currentUser which is AccountInterface
    $targetUser = $user;

    if ($currentUserAccount->id() == $targetUser->id()) {
      $this->messenger()->addWarning($this->t("You cannot start a chat with yourself."));
      return $this->redirect('<front>');
    }

    // Check recipient's message acceptance preference.
    $acceptsOnlyFromMatches = FALSE;
    if ($targetUser->hasField('field_accept_msg_from_matches') && !$targetUser->get('field_accept_msg_from_matches')->isEmpty()) {
      $acceptsOnlyFromMatches = (bool) $targetUser->get('field_accept_msg_from_matches')->value;
    }

    if ($acceptsOnlyFromMatches) {
      $isMutualMatch = $this->userMatchService->checkForMatch($currentUserAccount->id(), $targetUser->id());
      if (!$isMutualMatch) {
        $this->messenger()->addError($this->t('@username only accepts messages from mutual matches. You cannot start a chat at this time.', ['@username' => $targetUser->getAccountName()]));
        return $this->redirect('<front>'); // Or redirect to a more appropriate page like user's profile.
      }
    }

    $query = $this->entityTypeManager->getStorage('match_thread')->getQuery()
      ->accessCheck(TRUE)
      ->condition('user1', $currentUserAccount->id())
      ->condition('user2', $targetUser->id())
      ->range(0, 1);
    $thread_ids_condition1 = $query->execute();

    $thread_ids = [];
    if (!empty($thread_ids_condition1)) {
      $thread_ids = $thread_ids_condition1;
    } else {
      $query = $this->entityTypeManager->getStorage('match_thread')->getQuery()
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
      $thread = $this->entityTypeManager->getStorage('match_thread')->load($thread_id);
    } else {
      /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
      $thread = $this->entityTypeManager->getStorage('match_thread')->create([
        'user1' => $currentUserAccount->id(),
        'user2' => $targetUser->id(),
        // 'uuid' => $this->uuidGenerator->generate(), // UUID field auto-generates if not set
      ]);
      // The UUID for the entity will be auto-generated by Drupal if the
      // entity type definition has 'uuid' in its entity_keys and no value is provided.
      // MatchThread entity already defines this, so ->save() will populate it.
      $thread->save();
    }
    // Make sure the thread has a UUID before redirecting. If it's a new thread,
    // $thread->uuid() might be null until save, but ->save() populates it.
    // If 'uuid' field in MatchThread.php baseFieldDefinitions is set to NOT auto-generate,
    // then you MUST set it: $thread->set('uuid', $this->uuidGenerator->generate())->save();
    // But typically it is auto-generated.

    if (!$thread->uuid()) {
      // This case should ideally not happen if UUID is auto-generated on save.
      // Log an error or handle it, as redirecting without UUID will fail.
      $this->messenger()->addError($this->t('Failed to retrieve UUID for the chat thread.'));
      return $this->redirect('<front>');
    }


    return new RedirectResponse(Url::fromRoute('match_chat.view_thread', ['match_thread_uuid' => $thread->uuid()])->toString());
  }

  // ... (rest of the controller remains the same)
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

    $user1_id = $thread->get('user1')->target_id;
    $user2_id = $thread->get('user2')->target_id;
    $current_user_id = $this->currentUser->id();

    if ($current_user_id != $user1_id && $current_user_id != $user2_id) {
      throw new AccessDeniedHttpException();
    }

    $messages_render_array = $this->renderMessages($thread);
    $form = $this->formBuilder()->getForm('\Drupal\match_chat\Form\MatchMessageForm', $thread);

    $current_user_for_template = NULL;
    if (!$this->currentUser->isAnonymous()) {
      $current_user_for_template = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    }

    return [
      '#theme' => 'match_thread',
      '#thread' => $thread,
      '#messages_list' => $messages_render_array['messages_list'],
      '#message_form' => $form,
      '#current_user' => $current_user_for_template, // <<< PASS THE USER ENTITY
      '#attached' => [
        'library' => [
          'core/drupal.ajax',
          'match_chat/match_chat_styles',
          'match_chat/match_chat_scrolltobottom',
        ],
      ],
    ];
  }

  /**
   * Helper to render messages for a thread.
   *
   * @param \Drupal\match_chat\Entity\MatchThreadInterface $thread
   * The thread entity.
   *
   * @return array
   * A render array containing the messages list.
   */
  public function renderMessages(MatchThreadInterface $thread)
  {
    $message_storage = $this->entityTypeManager->getStorage('match_message');
    $message_ids = $message_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('thread_id', $thread->id())
      ->sort('created', 'ASC')
      ->execute();

    $messages_for_list_items = []; // Changed variable name for clarity
    if (!empty($message_ids)) {
      $message_entities = $message_storage->loadMultiple($message_ids);
      $view_builder = $this->entityTypeManager->getViewBuilder('match_message');
      foreach ($message_entities as $message_entity_item) { // Changed variable name
        $is_sender = ($message_entity_item->getOwnerId() == $this->currentUser->id());
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

    // 1. Define the item_list render array for the messages themselves
    $actual_list_of_messages = [
      '#theme' => 'item_list',
      '#items' => $messages_for_list_items,
      '#title' => NULL,
      '#attributes' => ['class' => ['chat-messages-ul']], // Class for the <ul> element
      '#empty' => $this->t('No messages yet. Be the first to say hello!'),
      // DO NOT use #wrapper_attributes here if it's not working
    ];

    // 2. Return a new render array that explicitly wraps the list in our target container
    return [
      // This 'messages_list' key is what viewThread() and ajaxSubmitCallback() expect
      'messages_list' => [
        '#type' => 'container', // This will render as a <div>
        '#attributes' => [
          'id' => 'match-chat-messages-wrapper', // << AJAX TARGET ID IS ON THIS DIV
          'class' => ['chat-messages-list-inner-content'], // Add any other classes you need
        ],
        // Nest the actual item_list render array inside this container
        'the_list_itself' => $actual_list_of_messages, // The key here ('the_list_itself') is arbitrary
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
        $user1 = $thread->get('user1')->entity; // Or $thread->getUser1()
        $user2 = $thread->get('user2')->entity; // Or $thread->getUser2()
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
