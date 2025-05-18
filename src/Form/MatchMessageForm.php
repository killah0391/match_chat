<?php

namespace Drupal\match_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\match_chat\Entity\MatchThreadInterface;
use Drupal\match_chat\Controller\MatchChatController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for sending a new chat message.
 */
class MatchMessageForm extends FormBase
{

  protected EntityTypeManagerInterface $entityTypeManager;
  protected AccountInterface $currentUser;
  protected ?MatchThreadInterface $thread = NULL;

  /**
   * Define a consistent selector for AJAX messages.
   * Ensure this container exists in your HTML and is stable (not replaced by form AJAX).
   * Example: In your main chat template, you might have <div class="chat-ajax-messages"></div>
   * For now, using the one from your example.
   */
  const AJAX_MESSAGES_CONTAINER_SELECTOR = '.chat-status-messages'; // Or a more dedicated message div

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
      $other_user_obj_display = ($user1->id() === $current_user_id) ? $user2->getDisplayName() : $user1->getDisplayName();
      $form['block_status_message'] = [
        '#markup' => $this->t('You have blocked @username. Unblock via chat settings to send messages.', ['@username' => $other_user_obj_display]),
        '#prefix' => '<div class="messages messages--warning small p-2">',
        '#suffix' => '</div>',
        '#weight' => -20,
      ];
    } elseif ($is_blocked_by_other) {
      $other_user_obj_display = ($user1->id() === $current_user_id) ? $user2->getDisplayName() : $user1->getDisplayName();
      $form['block_status_message'] = [
        '#markup' => $this->t('@username has blocked you. You cannot send messages.', ['@username' => $other_user_obj_display]),
        '#prefix' => '<div class="messages messages--status small p-2">', // Should this be warning or error?
        '#suffix' => '</div>',
        '#weight' => -20,
      ];
    }

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#title_display' => 'invisible',
      '#required' => FALSE, // Validation logic will make it effectively required if no images
      '#attributes' => ['placeholder' => $this->t('Type your message...'), 'style' => 'resize: none;'],
      '#rows' => 3,
      '#disabled' => $form_disabled_due_to_block,
      '#weight' => -10,
    ];

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
      '#access' => TRUE, // Access is controlled by #disabled and validation
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
        'disable-refocus' => TRUE, // To prevent main window scroll
        'effect' => 'fade',       // You can set to 'none' if fading causes issues
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
      // This is a general error not specific to a field.
      $form_state->setErrorByName('', $this->t('Current user could not be loaded.'));
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
    $thread_id_from_state = $form_state->getValue('thread_id');
    // Fallback for thread ID if somehow not in form_state from hidden field (should be rare)
    if (empty($thread_id_from_state) && $this->thread) {
      $thread_id_from_state = $this->thread->id();
    }
    $form_wrapper_id = 'match-message-form-wrapper-' . $thread_id_from_state;


    if ($form_state->hasAnyErrors()) {
      // Transfer FormState errors to messenger to use the deleteAll() pattern.
      foreach ($form_state->getErrors() as $error_message_text) {
        $this->messenger()->addError($error_message_text);
      }

      $ajax_error_messages = \Drupal::messenger()->deleteAll(); // Retrieve and clear them.
      if (!empty($ajax_error_messages)) {
        $messages_render_array = [
          '#theme'        => 'status_messages',
          '#message_list' => $ajax_error_messages,
          '#status_headings' => [ // Optional, for accessibility.
            'error' => $this->t('Error message'),
            'status' => $this->t('Status message'),
            'warning' => $this->t('Warning message'),
          ],
        ];
        // Clear previous AJAX messages from the target container.
        $response->addCommand(new HtmlCommand(static::AJAX_MESSAGES_CONTAINER_SELECTOR, ''));
        $response->addCommand(new AppendCommand(static::AJAX_MESSAGES_CONTAINER_SELECTOR, $messages_render_array));
      }
      // Still replace the form, which will show inline errors if theme supports it for setErrorByName()
      $response->addCommand(new ReplaceCommand('#' . $form_wrapper_id, $form));
      return $response;
    }

    // If we reach here, validation passed. submitForm() will be called by Form API.
    // After submitForm() (implicitly), we update UI.

    /** @var \Drupal\match_chat\Entity\MatchThreadInterface|null $thread_entity */
    $thread_entity = $thread_id_from_state ? $this->entityTypeManager->getStorage('match_thread')->load($thread_id_from_state) : NULL;
    if ($this->thread && $this->thread->id() == $thread_id_from_state) { // Prefer injected thread if consistent
      $thread_entity = $this->thread;
    }

    if ($thread_entity) {
      /** @var \Drupal\match_chat\Controller\MatchChatController|null $controller */
      $controller = NULL;
      try {
        $controller = \Drupal::classResolver()->getInstanceFromDefinition(MatchChatController::class);
      } catch (\Exception $e) {
        \Drupal::logger('match_chat')->error('MatchMessageForm::ajaxSubmitCallback - Failed to instantiate MatchChatController: @message', ['@message' => $e->getMessage()]);
        $this->messenger()->addError($this->t('Error updating message list. Please try again.'));
        // This error will be picked up by the message handling block below.
      }

      /** @var \Drupal\user\UserInterface|null $current_user_obj */
      $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

      if ($controller && $current_user_obj) {
        $has_blocked_other = $thread_entity->hasUserBlockedOther($current_user_obj);
        $is_blocked_by_other = $thread_entity->isUserBlockedByOther($current_user_obj);
        $build = $controller->renderMessages($thread_entity, $current_user_obj, $has_blocked_other, $is_blocked_by_other);
        $messages_html_output = \Drupal::service('renderer')->renderRoot($build['messages_list']);
        $response->addCommand(new ReplaceCommand('#match-chat-messages-wrapper', $messages_html_output));
      } else {
        if (!$controller) \Drupal::logger('match_chat')->error('MatchMessageForm::ajaxSubmitCallback - Controller not loaded.');
        if (!$current_user_obj) \Drupal::logger('match_chat')->error('MatchMessageForm::ajaxSubmitCallback - Current user object not loaded.');
        // Optionally add a user-facing error if message list couldn't be updated.
        // $this->messenger()->addError($this->t('Could not refresh messages at this time.'));
      }
    } else {
      \Drupal::logger('match_chat')->error('MatchMessageForm::ajaxSubmitCallback - Thread entity not loaded for ID: @tid', ['@tid' => $thread_id_from_state]);
      $this->messenger()->addError($this->t('Error: Chat thread could not be loaded to refresh messages.'));
    }

    // Rebuild and replace this form.
    // submitForm() (called by Form API before this if no validation errors) should have cleared form values.
    $rebuilt_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    if (!empty($form_wrapper_id) && strpos($form_wrapper_id, 'match-message-form-wrapper-') === 0) {
      $response->addCommand(new ReplaceCommand('#' . $form_wrapper_id, $rebuilt_form));
    }

    // Add success message IF no errors occurred during the whole process.
    // submitForm() itself might set $form_state errors if an exception occurs during save.
    if (!$form_state->hasAnyErrors()) {
      $this->messenger()->addStatus($this->t('Message sent.'));
    }

    // Display any Drupal messages (status or errors that might have occurred after initial validation).
    $ajax_messages = \Drupal::messenger()->deleteAll();
    if (!empty($ajax_messages)) {
      $messages_render_array = [
        '#theme'        => 'status_messages',
        '#message_list' => $ajax_messages,
        '#status_headings' => [
          'error' => $this->t('Error message'),
          'status' => $this->t('Status message'),
          'warning' => $this->t('Warning message'),
        ],
      ];
      // Clear previous AJAX messages from the target container.
      $response->addCommand(new HtmlCommand(static::AJAX_MESSAGES_CONTAINER_SELECTOR, ''));
      $response->addCommand(new AppendCommand(static::AJAX_MESSAGES_CONTAINER_SELECTOR, $messages_render_array));
    }

    $response->addCommand(new InvokeCommand('.chat-messages-scroll-container', 'matchChatScrollToBottom'));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    // This check is mostly for non-AJAX submissions or if AJAX somehow bypassed validation.
    if ($form_state->hasAnyErrors()) {
      return;
    }

    $values = $form_state->getValues();
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface|null $thread */
    $thread = $this->entityTypeManager->getStorage('match_thread')->load($values['thread_id']);

    if (!$thread) {
      // For non-AJAX, this is fine. For AJAX, ajaxSubmitCallback should catch this.
      $form_state->setErrorByName('', $this->t('Chat thread not found. Message not sent.'));
      $this->messenger()->addError($this->t('Chat thread not found. Message not sent.'));
      return;
    }

    /** @var \Drupal\user\UserInterface|null $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if ($current_user_obj && ($thread->hasUserBlockedOther($current_user_obj) || $thread->isUserBlockedByOther($current_user_obj))) {
      $form_state->setErrorByName('', $this->t('Message not sent due to an active block in this chat.'));
      $this->messenger()->addError($this->t('Message not sent due to an active block in this chat.'));
      // $form_state->setRebuild(TRUE); // Not needed if error is set, form won't proceed.
      return;
    }

    $fids = $values['chat_images'] ?? [];
    if (!empty($fids) && !$thread->bothParticipantsAllowUploads()) {
      // This case should ideally be caught by validation, but as a fallback:
      $form_state->setErrorByName('chat_images', $this->t('File uploads are not permitted by both participants. Message sent without files.'));
      $this->messenger()->addWarning($this->t('File uploads are not permitted by both participants. Message sent without files.'));
      $fids = []; // Do not save files.
    }

    try {
      /** @var \Drupal\match_chat\Entity\MatchMessageInterface $message_entity */
      $message_entity = $this->entityTypeManager->getStorage('match_message')->create([
        'sender' => $this->currentUser->id(),
        'thread_id' => $values['thread_id'],
        'message' => $values['message'],
        'chat_images' => $fids, // Use the potentially modified $fids
      ]);
      $message_entity->save();

      $thread->setChangedTime(\Drupal::time()->getRequestTime());
      $thread->save();

      // Clear form values for the next message on AJAX rebuild.
      $form_state->setValue('message', '');
      $form_state->setValue('chat_images', []); // Clear selected files.
      // Crucial for managed_file to reset properly after AJAX.
      $user_input = $form_state->getUserInput();
      unset($user_input['message']);
      // For managed_file, the 'chat_images[fids]' might be what needs unsetting if issues persist.
      // Or just clearing the whole 'chat_images' array from user input.
      unset($user_input['chat_images']);
      $form_state->setUserInput($user_input);
      $form_state->setRebuild(TRUE); // Ensure form is rebuilt fresh.


      // Success message for non-AJAX. AJAX success is handled in ajaxSubmitCallback.
      if (!$this->getRequest()->isXmlHttpRequest()) {
        $this->messenger()->addStatus($this->t('Message sent.'));
      }
    } catch (\Exception $e) {
      $error_message_for_user = $this->t('An error occurred while sending the message. Please try again.');
      // Set error on form state so ajaxSubmitCallback can pick it up if this method is called within AJAX flow.
      $form_state->setErrorByName('', $error_message_for_user);
      // Also add to messenger for non-AJAX or as a fallback.
      $this->messenger()->addError($error_message_for_user);
      \Drupal::logger('match_chat')->error('Error sending message: @error. Trace: @trace', ['@error' => $e->getMessage(), '@trace' => $e->getTraceAsString()]);
    }
  }
}
