<?php

namespace Drupal\match_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\match_chat\Entity\MatchThreadInterface;
use Drupal\match_chat\Controller\MatchChatController; // Used in ajaxSubmitCallback
use Symfony\Component\DependencyInjection\ContainerInterface;
// use Drupal\Core\File\FileSystemInterface; // Not directly used now

/**
 * Form for sending a new chat message.
 */
class MatchMessageForm extends FormBase
{

  protected EntityTypeManagerInterface $entityTypeManager;
  protected AccountInterface $currentUser;
  protected ?MatchThreadInterface $thread = NULL;

  /**
   * Constructs a new MatchMessageForm.
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
    return 'match_chat_message_form';
  }

  /**
   * Sets the current thread for the form.
   */
  public function setThread(MatchThreadInterface $thread): void
  {
    $this->thread = $thread;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, MatchThreadInterface $match_thread = NULL): array
  {
    if ($match_thread) {
      $this->setThread($match_thread);
    }

    if (!$this->thread || !$this->thread->id()) {
      $form['error_no_thread'] = ['#markup' => $this->t('Chat thread is not available.')];
      if ($match_thread && !$match_thread->id()) {
        \Drupal::logger('match_chat')->warning('MatchMessageForm::buildForm: Passed thread has no ID.');
      }
      return $form;
    }

    /** @var \Drupal\user\UserInterface|null $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    /** @var \Drupal\user\UserInterface|null $user1 */
    $user1 = $this->thread->getUser1();
    /** @var \Drupal\user\UserInterface|null $user2 */
    $user2 = $this->thread->getUser2();

    if (!$current_user_obj || !$user1 || !$user2) {
      $form['error_participants_load'] = ['#markup' => $this->t('Chat error: Could not load participant information.')];
      \Drupal::logger('match_chat')->error('MatchMessageForm::buildForm: Failed to load one or more user objects for thread ID @tid. CU: @cu, U1: @u1, U2: @u2', [
        '@tid' => $this->thread->id(),
        '@cu' => $current_user_obj ? 'OK' : 'Fail',
        '@u1' => $user1 ? 'OK' : 'Fail',
        '@u2' => $user2 ? 'OK' : 'Fail',
      ]);
      return $form;
    }

    $current_user_id = $current_user_obj->id();
    if ($current_user_id !== $user1->id() && $current_user_id !== $user2->id()) {
      $form['error_permission'] = ['#markup' => $this->t('You do not have permission to post in this thread.')];
      return $form;
    }

    $form_wrapper_id = 'match-message-form-wrapper-' . $this->thread->id();
    $form['#prefix'] = '<div id="' . $form_wrapper_id . '">';
    $form['#suffix'] = '</div>';

    $has_blocked_other = $this->thread->hasUserBlockedOther($current_user_obj);
    $is_blocked_by_other = $this->thread->isUserBlockedByOther($current_user_obj);
    $form_disabled_due_to_block = $has_blocked_other || $is_blocked_by_other;

    if ($has_blocked_other) {
      $other_user_obj = ($user1->id() === $current_user_id) ? $user2 : $user1;
      $form['block_status_message'] = [
        '#markup' => $this->t('You have blocked @username. Unblock via chat settings to send messages.', ['@username' => $other_user_obj->getDisplayName()]),
        '#prefix' => '<div class="messages messages--warning small p-2">',
        '#suffix' => '</div>',
        '#weight' => -20,
      ];
    } elseif ($is_blocked_by_other) {
      $other_user_obj = ($user1->id() === $current_user_id) ? $user2 : $user1;
      $form['block_status_message'] = [
        '#markup' => $this->t('@username has blocked you. You cannot send messages.', ['@username' => $other_user_obj->getDisplayName()]),
        '#prefix' => '<div class="messages messages--status small p-2">',
        '#suffix' => '</div>',
        '#weight' => -20,
      ];
    }

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#title_display' => 'invisible',
      '#required' => FALSE,
      '#attributes' => ['placeholder' => $this->t('Type your message...'), 'style' => 'resize: none;'],
      '#rows' => 3,
      '#disabled' => $form_disabled_due_to_block,
      '#weight' => -10,
    ];

    // File upload field
    $both_participants_allow_uploads = $this->thread->bothParticipantsAllowUploads();
    $form['chat_images'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Attach image(s)'),
      '#title_display' => 'invisible',
      '#upload_location' => 'private://match_chat_images/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png gif jpg jpeg'],
        'file_validate_size' => [2 * 1024 * 1024], // 2MB
      ],
      '#description' => $this->t('Allowed: png, gif, jpg, jpeg. Max 2MB/file. Max 3 files.'),
      '#disabled' => $form_disabled_due_to_block || !$both_participants_allow_uploads,
      '#access' => TRUE,
      '#weight' => -5,
    ];

    if ($form_disabled_due_to_block) {
      $form['chat_images']['#prefix'] = '<div class="messages messages--warning small p-2">' . $this->t('File uploads are disabled due to an active block.') . '</div>';
    } elseif (!$both_participants_allow_uploads) {
      $form['chat_images']['#prefix'] = '<div class="messages messages--warning small p-2">' . $this->t('File uploads are disabled. Both participants must allow them in chat settings.') . '</div>';
    }

    $form['thread_id'] = ['#type' => 'hidden', '#value' => $this->thread->id()];

    $form['actions']['#type'] = 'actions';
    $form['actions']['#weight'] = 0;
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      '#disabled' => $form_disabled_due_to_block,
      '#ajax' => [
        'callback' => '::ajaxSubmitCallback',
        'wrapper' => $form_wrapper_id,
        'disable-refocus' => FALSE,
        'effect' => 'fade',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Sending...')],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    parent::validateForm($form, $form_state);

    // This form is now only for sending messages.
    // Block status checks for sending messages:
    $thread_id = $form_state->getValue('thread_id');
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface|null $thread */
    $thread = $thread_id ? $this->entityTypeManager->getStorage('match_thread')->load($thread_id) : NULL;

    if (!$thread) {
      $form_state->setErrorByName('message', $this->t('Chat thread not found.'));
      return;
    }

    /** @var \Drupal\user\UserInterface|null $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    if ($current_user_obj) {
      if ($thread->hasUserBlockedOther($current_user_obj)) {
        $form_state->setErrorByName('message', $this->t('You have blocked this user. Unblock to send messages.'));
      } elseif ($thread->isUserBlockedByOther($current_user_obj)) {
        $form_state->setErrorByName('message', $this->t('This user has blocked you. You cannot send messages.'));
      }
    } else {
      $form_state->setErrorByName('', $this->t('Current user could not be loaded.')); // General error
    }

    // Proceed with message content validation only if no block related errors.
    if (!$form_state->hasAnyErrors()) {
      $message_value = $form_state->getValue('message');
      $image_fids = $form_state->getValue('chat_images');

      if (empty(trim($message_value)) && empty($image_fids)) {
        $form_state->setErrorByName('message', $this->t('You must enter a message or upload at least one image.'));
      }

      if (!empty($image_fids) && !$thread->bothParticipantsAllowUploads()) {
        $form_state->setErrorByName('chat_images', $this->t('File uploads are not allowed by both participants. Enable via chat settings or remove files.'));
      }

      if (!empty($image_fids) && count($image_fids) > 3) {
        $form_state->setErrorByName('chat_images', $this->t('You can upload a maximum of 3 images.'));
      }
    }
  }

  /**
   * AJAX submit callback for sending messages.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state): AjaxResponse
  {
    $response = new AjaxResponse();
    // Construct the wrapper ID carefully, ensure $this->thread is available if $form_state doesn't have thread_id yet
    // However, $form_state should have 'thread_id' as it's a hidden field in the submitted form.
    $thread_id_from_state = $form_state->getValue('thread_id');
    if (empty($thread_id_from_state) && $this->thread) {
      // Fallback if getValue is null for some reason during this phase but property is set
      $thread_id_from_state = $this->thread->id();
    }
    $form_wrapper_id = 'match-message-form-wrapper-' . $thread_id_from_state;


    if ($form_state->hasAnyErrors()) {
      if (!empty($form_wrapper_id) && strpos($form_wrapper_id, 'match-message-form-wrapper-') === 0) { // Basic validation of wrapper_id
        $response->addCommand(new ReplaceCommand('#' . $form_wrapper_id, $form));
      } else {
        // Log an error if wrapper ID is bad, as ReplaceCommand would fail.
        \Drupal::logger('match_chat')->error('MatchMessageForm::ajaxSubmitCallback - Invalid form_wrapper_id for error replacement: @id', ['@id' => $form_wrapper_id]);
      }
      foreach ($form_state->getErrors() as $name => $error_message) {
        $response->addCommand(new MessageCommand($error_message, NULL, ['type' => 'error']));
      }
      return $response;
    }

    $form_state->setRebuild(TRUE);

    /** @var \Drupal\match_chat\Entity\MatchThreadInterface|null $thread_entity */
    // Use $this->thread if it's reliably set by buildForm and represents the current context.
    // $form_state->getValue('thread_id') should also come from the submitted form.
    $thread_entity = $thread_id_from_state ? $this->entityTypeManager->getStorage('match_thread')->load($thread_id_from_state) : NULL;

    // If $this->thread was set by buildForm, it might be more direct.
    // However, reloading ensures we have the latest state if other AJAX actions modified it.
    // For this callback, $this->thread should be the one associated with the form instance.
    // Let's prefer $this->thread if available and seems correct.
    if ($this->thread && $this->thread->id() == $thread_id_from_state) {
      $thread_entity = $this->thread;
    } elseif (!$thread_entity && $this->thread) {
      // Fallback to form property if load failed but property exists
      $thread_entity = $this->thread;
    }


    if ($thread_entity) {
      // --- CORRECTED CONTROLLER INSTANTIATION ---
      /** @var \Drupal\match_chat\Controller\MatchChatController|null $controller */
      $controller = NULL;
      try {
        // Use Drupal's class resolver to get an instance of your controller.
        // This correctly handles dependency injection for the controller.
        $controller = \Drupal::classResolver()->getInstanceFromDefinition(MatchChatController::class);
      } catch (\Exception $e) {
        \Drupal::logger('match_chat')->error('MatchMessageForm::ajaxSubmitCallback - Failed to instantiate MatchChatController: @message', ['@message' => $e->getMessage()]);
      }
      // --- END CORRECTION ---

      /** @var \Drupal\user\UserInterface|null $current_user_obj */
      $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

      if ($controller && $current_user_obj) {
        $has_blocked_other = $thread_entity->hasUserBlockedOther($current_user_obj);
        $is_blocked_by_other = $thread_entity->isUserBlockedByOther($current_user_obj);

        // Call renderMessages on the controller instance.
        $build = $controller->renderMessages($thread_entity, $current_user_obj, $has_blocked_other, $is_blocked_by_other);
        $messages_html_output = \Drupal::service('renderer')->renderRoot($build['messages_list']);
        $response->addCommand(new ReplaceCommand('#match-chat-messages-wrapper', $messages_html_output));
      } else {
        $log_msg = 'MatchMessageForm::ajaxSubmitCallback - Could not re-render messages. ';
        if (!$controller) $log_msg .= 'Controller not loaded. ';
        if (!$current_user_obj) $log_msg .= 'Current user object not loaded.';
        \Drupal::logger('match_chat')->error($log_msg);
      }
    } else {
      \Drupal::logger('match_chat')->error('MatchMessageForm::ajaxSubmitCallback - Thread entity not loaded for ID: @tid', ['@tid' => $thread_id_from_state]);
    }

    // Rebuild and replace this form.
    // $form here is the structure from the last buildForm call for this request.
    // $form_state has the current state, including submitted values (now cleared in submitForm).
    // rebuildForm will call buildForm again, which should give a fresh, empty form.
    $rebuilt_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    if (!empty($form_wrapper_id) && strpos($form_wrapper_id, 'match-message-form-wrapper-') === 0) {
      $response->addCommand(new ReplaceCommand('#' . $form_wrapper_id, $rebuilt_form));
    }
    $response->addCommand(new InvokeCommand('.chat-messages-scroll-container', 'matchChatScrollToBottom'));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    if ($form_state->hasAnyErrors()) {
      return; // Errors should be handled by AJAX callback or caught in validateForm.
    }

    $values = $form_state->getValues();
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface|null $thread */
    $thread = $this->entityTypeManager->getStorage('match_thread')->load($values['thread_id']);

    if (!$thread) {
      $this->messenger()->addError($this->t('Chat thread not found. Message not sent.'));
      return;
    }

    // Final server-side check for block status (belt and braces).
    /** @var \Drupal\user\UserInterface|null $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if ($current_user_obj && ($thread->hasUserBlockedOther($current_user_obj) || $thread->isUserBlockedByOther($current_user_obj))) {
      $this->messenger()->addError($this->t('Message not sent due to an active block in this chat.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $fids = $values['chat_images'] ?? [];
    if (!empty($fids) && !$thread->bothParticipantsAllowUploads()) {
      // This message might be redundant if the field was disabled and validated, but good for direct POST attempts.
      $this->messenger()->addError($this->t('File uploads are not permitted by both participants. Message sent without files.'));
      $fids = [];
    }

    try {
      /** @var \Drupal\match_chat\Entity\MatchMessageInterface $message_entity */
      $message_entity = $this->entityTypeManager->getStorage('match_message')->create([
        'sender' => $this->currentUser->id(),
        'thread_id' => $values['thread_id'],
        'message' => $values['message'],
        'chat_images' => $fids,
      ]);
      $message_entity->save();

      $thread->setChangedTime(\Drupal::time()->getRequestTime());
      $thread->save();

      // Clear values for the next message on AJAX rebuild.
      $form_state->setValue('message', '');
      $form_state->setValue('chat_images', []);
      $user_input = $form_state->getUserInput();
      unset($user_input['message'], $user_input['chat_images']);
      $form_state->setUserInput($user_input);

      if (!$this->getRequest()->isXmlHttpRequest()) {
        $this->messenger()->addStatus($this->t('Message sent.'));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred while sending the message: @error', ['@error' => $e->getMessage()]));
      \Drupal::logger('match_chat')->error('Error sending message: @error. Trace: @trace', ['@error' => $e->getMessage(), '@trace' => $e->getTraceAsString()]);
    }
  }
}
