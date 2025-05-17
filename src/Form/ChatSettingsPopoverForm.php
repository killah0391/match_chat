<?php

namespace Drupal\match_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\match_chat\Entity\MatchThreadInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
// Ensure UserInterface is imported if type hints are used explicitly for it.
// use Drupal\user\UserInterface as DrupalUserInterface;

/**
 * Provides a form for chat settings (blocking, file uploads) within a popover.
 */
class ChatSettingsPopoverForm extends FormBase
{

  protected EntityTypeManagerInterface $entityTypeManager;
  protected AccountInterface $currentUser;

  /**
   * Constructs a new ChatSettingsPopoverForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user.
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
    $form_internal_wrapper_id = 'chat-settings-popover-form-wrapper-' . ($thread ? $thread->id() : 'no-thread-available');
    $form['#prefix'] = '<div id="' . $form_internal_wrapper_id . '" class="match-chat-popover-form-inner-wrapper p-2">';
    $form['#suffix'] = '</div>';

    if (!$thread || !$thread->id()) { // Ensure thread has an ID too.
      $form['error_no_thread'] = ['#markup' => '<div class="alert alert-danger small p-2">' . $this->t('Chat thread information is currently unavailable.') . '</div>'];
      // Log this occurrence if $thread was passed but has no ID.
      if ($thread && !$thread->id()) {
        \Drupal::logger('match_chat')->warning('ChatSettingsPopoverForm::buildForm: Passed thread has no ID.');
      }
      return $form;
    }

    /** @var \Drupal\user\UserInterface|null $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    /** @var \Drupal\user\UserInterface|null $user1 */
    $user1 = $thread->getUser1();
    /** @var \Drupal\user\UserInterface|null $user2 */
    $user2 = $thread->getUser2();

    if (!$current_user_obj || !$user1 || !$user2) {
      $form['error_participants'] = ['#markup' => '<div class="alert alert-danger small p-2">' . $this->t('Participant information could not be loaded.') . '</div>'];
      \Drupal::logger('match_chat')->error('ChatSettingsPopoverForm::buildForm: Failed to load one or more user objects for thread ID @tid. CU: @cu, U1: @u1, U2: @u2', [
        '@tid' => $thread->id(),
        '@cu' => $current_user_obj ? 'OK' : 'Fail',
        '@u1' => $user1 ? 'OK' : 'Fail',
        '@u2' => $user2 ? 'OK' : 'Fail',
      ]);
      return $form;
    }

    /** @var \Drupal\user\UserInterface $other_user_obj */
    $other_user_obj = ($user1->id() === $current_user_obj->id()) ? $user2 : $user1;

    $has_blocked_other = $thread->hasUserBlockedOther($current_user_obj);

    $form['thread_id'] = ['#type' => 'hidden', '#value' => $thread->id()];
    $form['main_message_form_wrapper_id'] = ['#type' => 'hidden', '#value' => 'match-message-form-wrapper-' . $thread->id()];
    $form['this_popover_form_wrapper_id'] = ['#type' => 'hidden', '#value' => $form_internal_wrapper_id];

    $form['toggle_block'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Block @username', ['@username' => $other_user_obj->getDisplayName()]),
      '#default_value' => $has_blocked_other,
      '#ajax' => [
        'callback' => '::ajaxBlockToggleCallback',
        'event' => 'change',
        'wrapper' => $form_internal_wrapper_id,
        'progress' => ['type' => 'throbber', 'message' => NULL],
      ],
      '#weight' => 10,
      '#attributes' => ['class' => ['mb-3']],
    ];

    $current_user_allows_uploads = ($user1->id() === $current_user_obj->id()) ? $thread->getUser1AllowsUploads() : $thread->getUser2AllowsUploads();
    $form['allow_uploads'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I allow file uploads in this chat'),
      '#default_value' => $current_user_allows_uploads,
      '#ajax' => [
        'callback' => '::ajaxUploadsToggleCallback',
        'event' => 'change',
        'wrapper' => $form_internal_wrapper_id,
        'progress' => ['type' => 'throbber', 'message' => NULL],
      ],
      '#weight' => 20,
    ];

    return $form;
  }

  /**
   * AJAX callback for the Block User checkbox.
   */
  public function ajaxBlockToggleCallback(array &$form, FormStateInterface $form_state): AjaxResponse
  {
    $response = new AjaxResponse();
    $thread_id = $form_state->getValue('thread_id');
    $should_be_blocked_new_state = (bool) $form_state->getValue('toggle_block');

    /** @var \Drupal\match_chat\Entity\MatchThreadInterface|null $thread */
    $thread = $thread_id ? $this->entityTypeManager->getStorage('match_thread')->load($thread_id) : NULL;
    /** @var \Drupal\user\UserInterface|null $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    $announce_message = '';
    $other_user_display_name = $this->t('the other user');

    if ($thread && $current_user_obj) {
      $user1_obj = $thread->getUser1();
      $user2_obj = $thread->getUser2();
      $other_user_obj = NULL;

      if ($user1_obj && $user2_obj) {
        if ($user1_obj->id() === $current_user_obj->id()) {
          $other_user_obj = $user2_obj;
        } elseif ($user2_obj->id() === $current_user_obj->id()) {
          $other_user_obj = $user1_obj;
        }
      }

      if ($other_user_obj) {
        $other_user_display_name = $other_user_obj->getDisplayName();
        try {
          $thread->setBlockStatusByUser($current_user_obj, $should_be_blocked_new_state);
          $thread->save();
          if ($should_be_blocked_new_state) {
            $this->messenger()->addStatus($this->t('You have blocked @username.', ['@username' => $other_user_display_name]));
            $announce_message = $this->t('User @username has been blocked.', ['@username' => $other_user_display_name]);
          } else {
            $this->messenger()->addStatus($this->t('You have unblocked @username.', ['@username' => $other_user_display_name]));
            $announce_message = $this->t('User @username has been unblocked.', ['@username' => $other_user_display_name]);
          }
        } catch (\Exception $e) {
          $this->messenger()->addError($this->t('An error occurred updating block status for @username.', ['@username' => $other_user_display_name]));
          \Drupal::logger('match_chat')->error('ajaxBlockToggleCallback: Exception for thread @tid: @message', ['@tid' => $thread_id, '@message' => $e->getMessage()]);
        }
      } else {
        $this->messenger()->addError($this->t('Could not identify other participant. Block action failed.'));
        \Drupal::logger('match_chat')->error('ajaxBlockToggleCallback: Failed to determine other_user_obj for thread @tid.', ['@tid' => $thread_id]);
      }
    } else {
      $this->messenger()->addError($this->t('Essential chat information is missing. Block action failed.'));
      \Drupal::logger('match_chat')->error('ajaxBlockToggleCallback: Thread (ID: @tid) or current user (ID: @cuid) not loaded.', ['@tid' => $thread_id, '@cuid' => $this->currentUser->id()]);
    }

    // Rebuild this popover form.
    $this_popover_form_wrapper_id = $form_state->getValue('this_popover_form_wrapper_id');
    if (empty($this_popover_form_wrapper_id) && isset($form['#ajax']['wrapper'])) {
      $this_popover_form_wrapper_id = $form['#ajax']['wrapper'];
    }
    // If $thread became NULL due to an error, pass NULL to getForm to avoid further issues.
    // $rebuilt_popover_form = \Drupal::formBuilder()->getForm(static::class, $thread);
    // RebuildForm is generally safer with AJAX as it preserves more $form_state context if needed for the build.
    $rebuilt_popover_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    if (!empty($this_popover_form_wrapper_id)) {
      $response->addCommand(new ReplaceCommand('#' . $this_popover_form_wrapper_id, $rebuilt_popover_form));
    }

    // Rebuild main message form.
    $main_message_form_wrapper_id = $form_state->getValue('main_message_form_wrapper_id');
    if ($main_message_form_wrapper_id) {
      if ($thread instanceof \Drupal\match_chat\Entity\MatchThreadInterface && $thread->id()) {
        try {
          $main_message_form = \Drupal::formBuilder()->getForm(\Drupal\match_chat\Form\MatchMessageForm::class, $thread);
          $response->addCommand(new ReplaceCommand('#' . $main_message_form_wrapper_id, $main_message_form));
        } catch (\Throwable $e) {
          \Drupal::logger('match_chat')->error('ajaxBlockToggleCallback: Exception getting MatchMessageForm for thread @tid: @msg. File: @file Line: @line', [
            '@tid' => $thread->id(),
            '@msg' => $e->getMessage(),
            '@file' => $e->getFile(),
            '@line' => $e->getLine()
          ]);
          $this->messenger()->addError($this->t('Error updating main chat form. Please refresh.'));
        }
      } else {
        \Drupal::logger('match_chat')->error('ajaxBlockToggleCallback: Skipped MatchMessageForm rebuild; thread invalid. Type: @type', ['@type' => gettype($thread)]);
      }
    }

    if (!empty($announce_message)) {
      $response->addCommand(new AnnounceCommand($announce_message));
    }
    return $response;
  }


  /**
   * AJAX callback for the Allow File Uploads checkbox.
   */
  public function ajaxUploadsToggleCallback(array &$form, FormStateInterface $form_state): AjaxResponse
  {
    $response = new AjaxResponse();
    $thread_id = $form_state->getValue('thread_id');
    $uploads_allowed_new_state = (bool) $form_state->getValue('allow_uploads');

    /** @var \Drupal\match_chat\Entity\MatchThreadInterface|null $thread */
    $thread = $thread_id ? $this->entityTypeManager->getStorage('match_thread')->load($thread_id) : NULL;
    /** @var \Drupal\user\UserInterface|null $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    $announce_message = '';
    if ($thread && $current_user_obj) {
      $user1 = $thread->getUser1();
      $user2 = $thread->getUser2();

      if ($user1 && $user2) { // Ensure participants are loaded
        try {
          if ($user1->id() === $current_user_obj->id()) {
            $thread->setUser1AllowsUploads($uploads_allowed_new_state);
          } elseif ($user2->id() === $current_user_obj->id()) {
            $thread->setUser2AllowsUploads($uploads_allowed_new_state);
          } else {
            \Drupal::logger('match_chat')->warning('ajaxUploadsToggleCallback: Current user @uid not participant of thread @tid.', ['@uid' => $current_user_obj->id(), '@tid' => $thread_id]);
            $this->messenger()->addWarning($this->t('Could not update upload preference: not a participant.'));
            goto end_of_uploads_logic; // Skip save and normal messages
          }
          $thread->save();
          $action_text = $uploads_allowed_new_state ? $this->t('enabled') : $this->t('disabled');
          $this->messenger()->addStatus($this->t('File uploads from you are now @state.', ['@state' => $action_text]));
          $announce_message = $this->t('Your file upload preference updated to: @state.', ['@state' => $action_text]);
        } catch (\Exception $e) {
          $this->messenger()->addError($this->t('An error occurred updating upload preference.'));
          \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: Exception for thread @tid: @message', ['@tid' => $thread_id, '@message' => $e->getMessage()]);
        }
      } else {
        $this->messenger()->addError($this->t('Chat participant data is incomplete. Cannot update upload preference.'));
        \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: User1 or User2 is null for thread @tid.', ['@tid' => $thread_id]);
      }
    } else {
      $this->messenger()->addError($this->t('Essential information missing. Upload preference not updated.'));
      \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: Thread (ID: @tid) or current user (ID: @cuid) not loaded.', ['@tid' => $thread_id, '@cuid' => $this->currentUser->id()]);
    }

    end_of_uploads_logic:

    // Rebuild this popover form.
    $this_popover_form_wrapper_id = $form_state->getValue('this_popover_form_wrapper_id', $form['#ajax']['wrapper'] ?? '');
    // $rebuilt_popover_form = \Drupal::formBuilder()->getForm(static::class, $thread);
    $rebuilt_popover_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    if (!empty($this_popover_form_wrapper_id)) {
      $response->addCommand(new ReplaceCommand('#' . $this_popover_form_wrapper_id, $rebuilt_popover_form));
    }

    // Rebuild main message form.
    $main_message_form_wrapper_id = $form_state->getValue('main_message_form_wrapper_id');
    if ($main_message_form_wrapper_id) {
      if ($thread instanceof \Drupal\match_chat\Entity\MatchThreadInterface && $thread->id()) {
        try {
          $main_message_form = \Drupal::formBuilder()->getForm(\Drupal\match_chat\Form\MatchMessageForm::class, $thread);
          $response->addCommand(new ReplaceCommand('#' . $main_message_form_wrapper_id, $main_message_form));
        } catch (\Throwable $e) {
          \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: Exception getting MatchMessageForm for thread @tid: @msg. File: @file Line: @line', [
            '@tid' => $thread->id(),
            '@msg' => $e->getMessage(),
            '@file' => $e->getFile(),
            '@line' => $e->getLine()
          ]);
          $this->messenger()->addError($this->t('Error updating main chat form. Please refresh.'));
        }
      } else {
        \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: Skipped MatchMessageForm rebuild; thread invalid. Type: @type', ['@type' => gettype($thread)]);
      }
    }

    if (!empty($announce_message)) {
      $response->addCommand(new AnnounceCommand($announce_message));
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    // All logic is handled by the AJAX callbacks of the checkboxes.
  }
}
