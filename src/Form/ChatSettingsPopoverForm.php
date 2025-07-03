<?php

namespace Drupal\match_chat\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\match_chat\Entity\MatchThreadInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for chat settings (blocking, file uploads) within a popover.
 */
class ChatSettingsPopoverForm extends FormBase
{

  protected EntityTypeManagerInterface $entityTypeManager;
  protected AccountInterface $currentUser;

  // Target selector for AJAX messages, consistent with MatchMessageForm if desired.
  // Or a more specific one if popover messages need a different location.
  const AJAX_MESSAGES_CONTAINER_SELECTOR = '.chat-status-messages'; // Or another stable selector

  /**
   * Constructs a new ChatSettingsPopoverForm.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'match_chat_settings_popover_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, MatchThreadInterface $thread = NULL): array
  {
    $thread_id_suffix = $thread && $thread->id() ? $thread->id() : 'no-thread-available';
    $form_internal_wrapper_id = 'chat-settings-popover-form-wrapper-' . $thread_id_suffix;

    $form['#prefix'] = '<div id="' . $form_internal_wrapper_id . '" class="match-chat-popover-form-inner-wrapper p-2">';
    $form['#suffix'] = '</div>';

    // No inline message placeholder needed in the form structure itself.
    // Messages will be appended to a global/stable container.

    if (!$thread || !$thread->id()) {
      if ($thread && !$thread->id()) {
        \Drupal::logger('match_chat')->warning('ChatSettingsPopoverForm::buildForm: Passed thread has no ID.');
      }
      $form['error_no_thread'] = ['#markup' => '<div class="alert alert-danger small p-2">' . $this->t('Chat thread information is currently unavailable.') . '</div>'];
      return $form;
    }

    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $user1 = $thread->getUser1();
    $user2 = $thread->getUser2();

    if (!$current_user_obj || !$user1 || !$user2) {
      $form['error_participants'] = ['#markup' => '<div class="alert alert-danger small p-2">' . $this->t('Participant information could not be loaded.') . '</div>'];
      \Drupal::logger('match_chat')->error('ChatSettingsPopoverForm::buildForm: Failed to load users for thread @tid.', ['@tid' => $thread->id()]);
      return $form;
    }

    $form['thread_id'] = ['#type' => 'hidden', '#value' => $thread->id()];
    $form['main_message_form_wrapper_id'] = ['#type' => 'hidden', '#value' => 'match-message-form-wrapper-' . $thread->id()];
    $form['this_popover_form_wrapper_id'] = ['#type' => 'hidden', '#value' => $form_internal_wrapper_id];

    $current_user_allows_uploads = ($user1->id() === $current_user_obj->id()) ? $thread->getUser1AllowsUploads() : $thread->getUser2AllowsUploads();
    $form['allow_uploads'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I allow file uploads in this chat'),
      '#default_value' => $current_user_allows_uploads,
      '#ajax' => [
        'callback' => '::ajaxUploadsToggleCallback',
        'event' => 'change',
        'wrapper' => $form_internal_wrapper_id, // This form part will be replaced
        'progress' => ['type' => 'throbber', 'message' => NULL],
      ],
      '#weight' => 20,
    ];

    // --- Block/Unblock User Button ---
    $other_user_for_block = ($user1->id() === $current_user_obj->id()) ? $user2 : $user1;
    $current_user_has_blocked_other_user = FALSE; // True if current user has blocked $other_user_for_block
    if ($this->currentUser->hasPermission('block users')) {
      $block_storage = $this->entityTypeManager->getStorage('match_abuse_block');
      $existing_block_ids = $block_storage->getQuery()
        ->condition('blocker_uid', $this->currentUser->id())
        ->condition('blocked_uid', $other_user_for_block->id())
        ->accessCheck(FALSE) // Check existence, not entity access for viewing the block itself.
        ->execute();
      $current_user_has_blocked_other_user = !empty($existing_block_ids);
    }

    // "Allow uploads" checkbox should be hidden if the current user is the one blocking.
    $form['allow_uploads']['#access'] = !$current_user_has_blocked_other_user;

    $block_button_text = $current_user_has_blocked_other_user ?
      $this->t('Unblock @username', ['@username' => $other_user_for_block->getAccountName()]) :
      $this->t('Block @username', ['@username' => $other_user_for_block->getAccountName()]);

    $form['block_user_toggle'] = [
      '#type' => 'button',
      '#value' => $block_button_text,
      '#ajax' => [
        'callback' => '::ajaxBlockToggleCallback',
        'wrapper' => $form_internal_wrapper_id, // Replaces this form
        'progress' => ['type' => 'throbber', 'message' => NULL],
      ],
      '#weight' => 100,
      '#attributes' => ['class' => ['btn', $current_user_has_blocked_other_user ? 'btn-warning' : 'btn-danger', 'btn-sm', 'mt-2', 'w-100', 'text-white']],
      '#access' => $this->currentUser->hasPermission('block users'),
    ];
    return $form;
  }

  protected function addDrupalAjaxMessages(AjaxResponse $response, string $target_selector = self::AJAX_MESSAGES_CONTAINER_SELECTOR)
  {
    // Retrieve AND clear messages from the session queue.
    $messages_for_ajax = \Drupal::messenger()->deleteAll();

    if (!empty($messages_for_ajax)) {
      $first_message_in_batch = TRUE;
      foreach ($messages_for_ajax as $type => $messages_of_type) {
        foreach ($messages_of_type as $individual_message_text) {
          $response->addCommand(new MessageCommand(
            $individual_message_text,
            $target_selector,
            ['type' => $type],
            $first_message_in_batch
          ));
          $first_message_in_batch = FALSE;
        }
      }
    }
  }

  /**
   * AJAX callback for the Allow File Uploads checkbox.
   */
  public function ajaxUploadsToggleCallback(array &$form, FormStateInterface $form_state): AjaxResponse
  {
    $response = new AjaxResponse();
    $thread_id = $form_state->getValue('thread_id');
    $uploads_allowed_new_state = (bool) $form_state->getValue('allow_uploads');

    $thread = $thread_id ? $this->entityTypeManager->getStorage('match_thread')->load($thread_id) : NULL;
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $announce_message = '';

    if ($thread && $current_user_obj) {
      $user1 = $thread->getUser1();
      $user2 = $thread->getUser2();
      if ($user1 && $user2) {
        try {
          if ($user1->id() === $current_user_obj->id()) {
            $thread->setUser1AllowsUploads($uploads_allowed_new_state);
          } elseif ($user2->id() === $current_user_obj->id()) {
            $thread->setUser2AllowsUploads($uploads_allowed_new_state);
          } else {
            $this->messenger()->addWarning($this->t('Could not update upload preference: not a participant.'));
            \Drupal::logger('match_chat')->warning('ajaxUploadsToggleCallback: Current user @uid not participant of thread @tid.', ['@uid' => $current_user_obj->id(), '@tid' => (string) $thread_id]);
            goto end_of_uploads_logic_drupal_cb;
          }
          $thread->save();
          $action_text = $uploads_allowed_new_state ? $this->t('enabled') : $this->t('disabled');
          $this->messenger()->addStatus($this->t('File uploads from you are now @state.', ['@state' => $action_text]));
          $announce_message = $this->t('Your file upload preference updated to: @state.', ['@state' => $action_text]);
        } catch (\Exception $e) {
          $this->messenger()->addError($this->t('An error occurred updating upload preference.'));
          \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: Exception for thread @tid: @message', ['@tid' => (string) $thread_id, '@message' => $e->getMessage()]);
        }
      } else {
        $this->messenger()->addError($this->t('Chat participant data is incomplete. Cannot update upload preference.'));
        \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: User1 or User2 is null for thread @tid.', ['@tid' => (string) $thread_id]);
      }
    } else {
      $this->messenger()->addError($this->t('Essential information missing. Upload preference not updated.'));
      \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: Thread or current user not loaded for thread @tid.', ['@tid' => (string) $thread_id]);
    }

    end_of_uploads_logic_drupal_cb:
    // Add Drupal status messages via AJAX.
    $this->addDrupalAjaxMessages($response);

    if (!empty($announce_message)) {
      $response->addCommand(new AnnounceCommand($announce_message));
    }

    // Rebuild this popover form.
    $this_popover_form_wrapper_id = $form_state->getValue('this_popover_form_wrapper_id', $form['#ajax']['wrapper'] ?? '');
    $rebuilt_popover_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    if (!empty($this_popover_form_wrapper_id)) {
      $response->addCommand(new ReplaceCommand('#' . $this_popover_form_wrapper_id, $rebuilt_popover_form));
    }

    // Rebuild main message form.
    $main_message_form_wrapper_id = $form_state->getValue('main_message_form_wrapper_id');
    if ($main_message_form_wrapper_id && $thread instanceof MatchThreadInterface && $thread->id()) {
      try {
        $main_message_form = \Drupal::formBuilder()->getForm(\Drupal\match_chat\Form\MatchMessageForm::class, $thread);
        $response->addCommand(new ReplaceCommand('#' . $main_message_form_wrapper_id, $main_message_form));
      } catch (\Throwable $e) {
        $this->messenger()->addError($this->t('Error updating main chat form. Please refresh.'));
        // Show this error too.
        $ajax_messages = \Drupal::messenger()->deleteAll();
        if (!empty($ajax_messages)) {
          $first_message_in_batch = TRUE;
          foreach ($ajax_messages as $type => $messages_of_type) {
            foreach ($messages_of_type as $individual_message_text) {
              $response->addCommand(new MessageCommand(
                $individual_message_text,
                static::AJAX_MESSAGES_CONTAINER_SELECTOR,
                ['type' => $type],
                $first_message_in_batch
              ));
              $first_message_in_batch = FALSE;
            }
          }
        }
        \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: Exception getting MatchMessageForm for thread @tid: @msg.', ['@tid' => $thread->id(), '@msg' => $e->getMessage()]);
      }
    }
    return $response;
  }

  /**
   * AJAX callback for the Block/Unblock User button.
   */
  public function ajaxBlockToggleCallback(array &$form, FormStateInterface $form_state): AjaxResponse
  {
    $response = new AjaxResponse();
    $thread_id = $form_state->getValue('thread_id');
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = $thread_id ? $this->entityTypeManager->getStorage('match_thread')->load($thread_id) : NULL;

    if (!$thread) {
      $this->messenger()->addError($this->t('Chat thread not found.'));
      $this->addDrupalAjaxMessages($response, static::AJAX_MESSAGES_CONTAINER_SELECTOR);
      return $response;
    }

    $user1 = $thread->getUser1();
    $user2 = $thread->getUser2();
    $current_user_id = $this->currentUser->id();

    if (!$user1 || !$user2) {
      $this->messenger()->addError($this->t('Chat participants not found.'));
      $this->addDrupalAjaxMessages($response, static::AJAX_MESSAGES_CONTAINER_SELECTOR);
      return $response;
    }

    $other_user = ($user1->id() === $current_user_id) ? $user2 : $user1;

    if (!$this->currentUser->hasPermission('block users')) {
      $this->messenger()->addError($this->t('You do not have permission to block users.'));
      $this->addDrupalAjaxMessages($response, static::AJAX_MESSAGES_CONTAINER_SELECTOR);
      return $response;
    }

    $block_storage = $this->entityTypeManager->getStorage('match_abuse_block');
    $existing_block_ids = $block_storage->getQuery()
      ->condition('blocker_uid', $current_user_id)
      ->condition('blocked_uid', $other_user->id())
      ->accessCheck(FALSE) // Check existence, not entity access for viewing the block itself.
      ->execute();

    if (!empty($existing_block_ids)) { // User is currently blocked, so unblock
      $entities_to_delete = $block_storage->loadMultiple($existing_block_ids);
      $block_storage->delete($entities_to_delete);
      $response->addCommand(new RedirectCommand(Url::fromRoute('match_chat.my_threads', ['match_thread_uuid' => $thread->uuid()])->toString()));
      $this->messenger()->addStatus($this->t('You have unblocked @username.', ['@username' => $other_user->getAccountName()]));
    } else { // User is not blocked, so block
      $block = $block_storage->create([
        'blocker_uid' => $current_user_id,
        'blocked_uid' => $other_user->id(),
      ]);
      $block->save();
      $response->addCommand(new RedirectCommand(Url::fromRoute('entity.user.canonical', ['user' => $other_user->id()])->toString()));
      $this->messenger()->addStatus($this->t('You have blocked @username.', ['@username' => $other_user->getAccountName()]));
    }

    $this->addDrupalAjaxMessages($response, static::AJAX_MESSAGES_CONTAINER_SELECTOR);

    // Check if the current user is NOW blocked by the other user
    $current_user_is_blocked_by_other = FALSE;
    $block_storage_check = $this->entityTypeManager->getStorage('match_abuse_block');
    $blocks_by_other_check = $block_storage_check->getQuery()
      ->condition('blocker_uid', $other_user->id())
      ->condition('blocked_uid', $current_user_id)
      ->accessCheck(FALSE)
      ->execute();
    if (!empty($blocks_by_other_check)) {
      $current_user_is_blocked_by_other = TRUE;
    }

    // New: Check if the current user is NOW the blocker
    $current_user_is_now_the_blocker = FALSE;
    $blocks_by_current_user_check = $block_storage_check->getQuery()
      ->condition('blocker_uid', $current_user_id)
      ->condition('blocked_uid', $other_user->id())
      ->accessCheck(FALSE)
      ->execute();
    if (!empty($blocks_by_current_user_check)) {
      $current_user_is_now_the_blocker = TRUE;
    }

    if ($current_user_is_blocked_by_other) {
      // Current user is blocked by the other user.
      // Replace chat form container with warning, hide popover trigger and content.
      $warning_message_text = $this->t('@username has blocked you. You cannot send messages or change chat settings.', ['@username' => $other_user->getAccountName()]);
      $warning_html = '<div class="alert alert-warning bootstrap-warning-alert-container" role="alert">' . $warning_message_text . '</div>';

      $response->addCommand(new ReplaceCommand('.chat-form-container', $warning_html));
      $response->addCommand(new InvokeCommand('#chat-settings-popover-trigger-' . $thread_id, 'hide'));
      // Also ensure the popover itself is hidden if it was open
      $response->addCommand(new InvokeCommand('#chat-settings-popover-trigger-' . $thread_id, 'popover', ['hide']));
      $response->addCommand(new HtmlCommand('#chat-settings-popover-content-container-' . $thread_id, '')); // Clear its content
    } else {
      // Current user is NOT blocked by the other.
      // Now check if current user is the blocker, or if it's a clear state.

      if ($current_user_is_now_the_blocker) {
        // Current user is the blocker.
        // Replace chat form container with "you have blocked them" warning.
        $warning_message_text = $this->t('You have blocked @username. You cannot send messages until you unblock them.', ['@username' => $other_user->getAccountName()]);
        $warning_html = '<div class="alert alert-warning bootstrap-warning-alert-container" role="alert">' . $warning_message_text . '</div>';
        $response->addCommand(new ReplaceCommand('.chat-form-container', $warning_html));
      } else {
        // Neither is blocked by the other (or current user just unblocked the other).
        // Rebuild main message form to ensure it's the actual form, not a warning.
        if ($thread instanceof MatchThreadInterface && $thread->id()) {
          $main_message_form_render_array = \Drupal::formBuilder()->getForm(\Drupal\match_chat\Form\MatchMessageForm::class, $thread);
          $response->addCommand(new ReplaceCommand('.chat-form-container', $main_message_form_render_array));
        }
      }

      // In either case (current user is blocker OR chat is clear), rebuild the popover form.
      // This will update button text and hide/show "allow_uploads" based on new state.
      $this_popover_form_wrapper_id = $form_state->getValue('this_popover_form_wrapper_id', $form['#ajax']['wrapper'] ?? '');
      $rebuilt_popover_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
      if (!empty($this_popover_form_wrapper_id)) {
        $response->addCommand(new ReplaceCommand('#' . $this_popover_form_wrapper_id, $rebuilt_popover_form));
      }
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    // All logic is handled by the AJAX callbacks.
  }
}
