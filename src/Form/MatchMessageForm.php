<?php

namespace Drupal\match_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\MessageCommand; // For displaying messages via AJAX
use Drupal\Core\Ajax\AnnounceCommand; // For accessibility
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\match_chat\Entity\MatchThreadInterface;
use Drupal\match_chat\Controller\MatchChatController; // Ensure this is the correct controller if used directly
use Symfony\Component\DependencyInjection\ContainerInterface;
// No need for FileSystemInterface unless used directly in this form for other purposes.
// use Drupal\Core\File\FileSystemInterface; // Not directly used in this version of the form
use Drupal\user\UserInterface as DrupalUserInterface; // Alias for clarity

/**
 * Form for sending a new chat message and managing blocks.
 */
class MatchMessageForm extends FormBase
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
   * The current chat thread.
   *
   * @var \Drupal\match_chat\Entity\MatchThreadInterface|null
   */
  protected $thread;

  /**
   * Constructs a new MatchMessageForm.
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
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'match_chat_message_form';
  }

  /**
   * Sets the current thread for the form.
   *
   * @param \Drupal\match_chat\Entity\MatchThreadInterface $thread
   * The current match thread.
   */
  public function setThread(MatchThreadInterface $thread)
  {
    $this->thread = $thread;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, MatchThreadInterface $match_thread = NULL)
  {
    // --- START DIAGNOSTIC LOGGING ---
    if (\Drupal::request()->isXmlHttpRequest()) {
      $logger = \Drupal::logger('match_chat_ajax');
      $match_thread_id = 'N/A';
      if ($match_thread && method_exists($match_thread, 'id')) {
        $match_thread_id = $match_thread->id() ?? 'ID_METHOD_RETURNED_NULL';
      } elseif ($match_thread) {
        $match_thread_id = 'ID_METHOD_MISSING';
      }

      $this_thread_id = 'N/A';
      if ($this->thread && method_exists($this->thread, 'id')) {
        $this_thread_id = $this->thread->id() ?? 'ID_METHOD_RETURNED_NULL';
      } elseif ($this->thread) {
        $this_thread_id = 'ID_METHOD_MISSING';
      }

      $build_info_args = $form_state->getBuildInfo()['args'] ?? ['BuildInfo args not set'];
      $first_arg_type = 'N/A';
      $first_arg_id = 'N/A';
      if (!empty($build_info_args)) {
        $first_arg = $build_info_args[0];
        $first_arg_type = gettype($first_arg);
        if (is_object($first_arg) && method_exists($first_arg, 'id')) {
          $first_arg_id = $first_arg->id() ?? 'ID_METHOD_RETURNED_NULL';
        } elseif (is_object($first_arg)) {
          $first_arg_id = 'ID_METHOD_MISSING_ON_FIRST_ARG';
        }
      }


      $logger->notice('AJAX buildForm: $match_thread type: @type, id: @id. Current $this->thread id: @this_id. BuildInfo[args][0] type: @first_arg_type, id: @first_arg_id. Request URI: @uri', [
        '@type' => gettype($match_thread),
        '@id' => $match_thread_id,
        '@this_id' => $this_thread_id, // State of property before setThread is called
        '@first_arg_type' => $first_arg_type,
        '@first_arg_id' => $first_arg_id,
        '@uri' => \Drupal::request()->getRequestUri(),
      ]);
    }
    // --- END DIAGNOSTIC LOGGING ---
    if ($match_thread) {
      $this->setThread($match_thread);
    }

    if (!$this->thread) {
      $form['error'] = [
        '#markup' => $this->t('No chat thread specified.'),
      ];
      return $form;
    }

    $user1 = $this->thread->getUser1();
    $user2 = $this->thread->getUser2();
    $current_user_id = $this->currentUser->id();

    if (!$user1 || !$user2) {
      $form['error'] = [
        '#markup' => $this->t('Chat thread participants are not properly configured.'),
      ];
      return $form;
    }

    $user1_id = $user1->id();
    $user2_id = $user2->id();

    if ($current_user_id != $user1_id && $current_user_id != $user2_id) {
      $form['error'] = [
        '#markup' => $this->t('You do not have permission to post in this thread.'),
      ];
      return $form;
    }

    /** @var \Drupal\user\UserInterface $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($current_user_id);
    /** @var \Drupal\user\UserInterface $other_user_obj */
    $other_user_obj = ($user1_id == $current_user_id) ? $user2 : $user1;

    if (!$current_user_obj || !$other_user_obj) {
      $form['error'] = [
        '#markup' => $this->t('Could not load participant user objects.'),
      ];
      return $form;
    }

    $form_wrapper_id = 'match-message-form-wrapper-' . $this->thread->id();
    $form['#prefix'] = '<div id="' . $form_wrapper_id . '">';
    $form['#suffix'] = '</div>';

    $has_blocked_other = $this->thread->hasUserBlockedOther($current_user_obj);
    $is_blocked_by_other = $this->thread->isUserBlockedByOther($current_user_obj);

    if ($has_blocked_other) {
      $form['block_status_message'] = [
        '#markup' => $this->t('You have blocked @username. You cannot send messages unless you unblock them.', ['@username' => $other_user_obj->getDisplayName()]),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
        '#weight' => -20,
      ];
    } elseif ($is_blocked_by_other) {
      $form['block_status_message'] = [
        '#markup' => $this->t('@username has blocked you. You cannot send messages.', ['@username' => $other_user_obj->getDisplayName()]),
        '#prefix' => '<div class="messages messages--status">', // Can also be 'warning'
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
      '#disabled' => $has_blocked_other || $is_blocked_by_other,
      '#weight' => -10,
    ];

    $upload_wrapper_id = 'upload-settings-wrapper-' . $this->thread->id();
    $form['upload_settings_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => $upload_wrapper_id],
      '#weight' => -5,
    ];

    $current_user_allows_uploads = ($user1_id == $current_user_id) ? $this->thread->getUser1AllowsUploads() : $this->thread->getUser2AllowsUploads();

    $form['upload_settings_wrapper']['allow_uploads_toggle'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I allow file uploads in this chat'),
      '#default_value' => $current_user_allows_uploads,
      '#ajax' => [
        'callback' => '::ajaxAllowUploadsToggleCallback',
        'wrapper' => $upload_wrapper_id,
        'event' => 'change',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Updating preference...')],
      ],
      '#disabled' => $has_blocked_other || $is_blocked_by_other,
    ];

    $uploads_globally_disabled = $has_blocked_other || $is_blocked_by_other;
    $both_participants_allow_uploads = $this->thread->bothParticipantsAllowUploads();

    $form['upload_settings_wrapper']['chat_images'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Attach image(s)'),
      '#upload_location' => 'private://match_chat_images/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png gif jpg jpeg'],
        'file_validate_size' => [2 * 1024 * 1024], // 2MB
      ],
      '#description' => $this->t('Allowed extensions: png, gif, jpg, jpeg. Max 2MB per file. Max 3 files.'),
      '#disabled' => $uploads_globally_disabled || !$both_participants_allow_uploads,
      '#access' => TRUE,
    ];

    if ($uploads_globally_disabled) {
      $form['upload_settings_wrapper']['chat_images']['#prefix'] = '<div class="messages messages--warning">' . $this->t('File uploads are disabled due to an active block in this chat.') . '</div>';
    } elseif (!$both_participants_allow_uploads) {
      $form['upload_settings_wrapper']['chat_images']['#prefix'] = '<div class="messages messages--warning">' . $this->t('File uploads are currently disabled because both participants need to allow them. You can enable them using the checkbox above.') . '</div>';
    }


    $form['thread_id'] = [
      '#type' => 'hidden',
      '#value' => $this->thread->id(),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['#weight'] = 0;

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      '#disabled' => $has_blocked_other || $is_blocked_by_other,
      '#ajax' => [
        'callback' => '::ajaxSubmitCallback',
        'wrapper' => $form_wrapper_id,
        'disable-refocus' => FALSE,
        'effect' => 'fade',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Sending...')],
      ],
    ];

    if (!$is_blocked_by_other) {
      $form['actions']['block_user'] = [
        '#type' => 'submit',
        '#name' => 'block_user_button',
        '#value' => $has_blocked_other ? $this->t('Unblock @username', ['@username' => $other_user_obj->getDisplayName()]) : $this->t('Block @username', ['@username' => $other_user_obj->getDisplayName()]),
        '#submit' => ['::submitBlockUser'],
        '#ajax' => [
          'callback' => '::ajaxBlockUserCallback',
          'wrapper' => $form_wrapper_id,
          'event' => 'click',
          'progress' => ['type' => 'throbber', 'message' => $this->t('Processing...')],
        ],
        '#limit_validation_errors' => [], // Don't validate message field
        '#weight' => 10, // After send button
      ];
    }

    return $form;
  }

  /**
   * AJAX callback for the allow_uploads_toggle checkbox.
   */
  public function ajaxAllowUploadsToggleCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    $thread_id = $form_state->getValue('thread_id');
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = $this->entityTypeManager->getStorage('match_thread')->load($thread_id);
    $current_user_id = $this->currentUser->id();
    $checkbox_value = (bool) $form_state->getValue('allow_uploads_toggle');

    if ($thread) {
      /** @var \Drupal\user\UserInterface $current_user_obj */
      $current_user_obj = $this->entityTypeManager->getStorage('user')->load($current_user_id);
      $has_blocked_other = $thread->hasUserBlockedOther($current_user_obj);
      $is_blocked_by_other = $thread->isUserBlockedByOther($current_user_obj);
      $uploads_globally_disabled = $has_blocked_other || $is_blocked_by_other;

      if ($thread->getUser1()->id() == $current_user_id) {
        $thread->setUser1AllowsUploads($checkbox_value);
      } elseif ($thread->getUser2()->id() == $current_user_id) {
        $thread->setUser2AllowsUploads($checkbox_value);
      }
      $thread->save();

      $form['upload_settings_wrapper']['chat_images']['#disabled'] = $uploads_globally_disabled || !$thread->bothParticipantsAllowUploads();
      $form['upload_settings_wrapper']['chat_images']['#prefix'] = ''; // Clear prefix first
      if ($uploads_globally_disabled) {
        $form['upload_settings_wrapper']['chat_images']['#prefix'] = '<div class="messages messages--warning">' . $this->t('File uploads are disabled due to an active block in this chat.') . '</div>';
      } elseif (!$thread->bothParticipantsAllowUploads()) {
        $form['upload_settings_wrapper']['chat_images']['#prefix'] = '<div class="messages messages--warning">' . $this->t('File uploads are currently disabled because both participants need to allow them. You can enable them using the checkbox above.') . '</div>';
      }
      $response->addCommand(new ReplaceCommand('#upload-settings-wrapper-' . $thread->id(), $form['upload_settings_wrapper']));
    } else {
      $response->addCommand(new MessageCommand($this->t('Could not update upload preference. Thread not found.'), NULL, ['type' => 'error']));
    }

    return $response;
  }

  /**
   * Submit handler for the block/unblock button.
   */
  public function submitBlockUser(array &$form, FormStateInterface $form_state)
  {
    $logger = \Drupal::logger('match_chat'); // Use your module's channel

    // Diagnostic: Log the state of $this->thread at the beginning of this submit handler.
    $this_thread_id_at_submit = 'N/A';
    if ($this->thread && method_exists($this->thread, 'id')) {
      $this_thread_id_at_submit = $this->thread->id() ?? 'ID_METHOD_RETURNED_NULL';
    } elseif ($this->thread) {
      $this_thread_id_at_submit = 'ID_METHOD_MISSING_OR_THREAD_NOT_FULL_ENTITY';
    }
    $logger->notice('submitBlockUser started. Current $this->thread ID: @id. Form state class: @fs_class', [
      '@id' => $this_thread_id_at_submit,
      '@fs_class' => get_class($form_state),
    ]);

    // Also log what form_state thinks thread_id is, for comparison.
    $thread_id_from_form_state = $form_state->getValue('thread_id');
    if ($thread_id_from_form_state === NULL) {
      $user_input_thread_id = $form_state->getUserInput()['thread_id'] ?? 'NOT_IN_USERINPUT';
      $logger->warning('submitBlockUser: $form_state->getValue(\'thread_id\') is NULL. UserInput[thread_id]: @ui_tid', [
        '@ui_tid' => print_r($user_input_thread_id, TRUE),
      ]);
    }

    // --- Primary Strategy: Use $this->thread (the form object's property) ---
    if (!$this->thread || !($this->thread instanceof MatchThreadInterface) || !$this->thread->id()) {
      $logger->error('submitBlockUser: $this->thread property is not a valid, loaded MatchThread entity. Cannot proceed. $this->thread type: @type, $this->thread id: @id', [
        '@type' => gettype($this->thread),
        '@id' => $this_thread_id_at_submit, // Use the already determined ID for logging
      ]);
      $this->messenger()->addError($this->t('A critical error occurred with the chat session. Please refresh the page and try again.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    // At this point, $this->thread is considered our source of truth for the thread entity.
    $thread_to_modify = $this->thread; // Use the form's property directly.

    /** @var \Drupal\user\UserInterface|null $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    if (!$current_user_obj) {
      $logger->error('submitBlockUser: Could not load current user object (ID: @uid).', ['@uid' => $this->currentUser->id()]);
      $this->messenger()->addError($this->t('Your user session could not be verified. Please try again.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    /** @var \Drupal\user\UserInterface|null $other_user_obj */
    $other_user_obj = NULL;
    $user1 = $thread_to_modify->getUser1();
    $user2 = $thread_to_modify->getUser2();

    if ($user1 && $user1->id() == $current_user_obj->id()) {
      $other_user_obj = $user2;
    } elseif ($user2 && $user2->id() == $current_user_obj->id()) {
      $other_user_obj = $user1;
    }

    if (!$other_user_obj) {
      $logger->error('submitBlockUser: Could not determine the other user in thread ID @tid.', ['@tid' => $thread_to_modify->id()]);
      $this->messenger()->addError($this->t('Could not identify the other participant in this chat. Block action failed.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    try {
      $currently_blocked_by_me = $thread_to_modify->hasUserBlockedOther($current_user_obj);
      $new_block_status = !$currently_blocked_by_me;
      $thread_to_modify->setBlockStatusByUser($current_user_obj, $new_block_status);
      $thread_to_modify->save(); // Save the entity that's a property of the form object.

      if ($new_block_status) {
        $this->messenger()->addStatus($this->t('You have blocked @username.', ['@username' => $other_user_obj->getDisplayName()]));
      } else {
        $this->messenger()->addStatus($this->t('You have unblocked @username.', ['@username' => $other_user_obj->getDisplayName()]));
      }
      $logger->info('submitBlockUser: Successfully toggled block status for user @uid on thread @tid. New status: @status', [
        '@uid' => $current_user_obj->id(),
        '@tid' => $thread_to_modify->id(),
        '@status' => $new_block_status ? 'BLOCKED' : 'UNBLOCKED',
      ]);
    } catch (\Exception $e) {
      $logger->error('submitBlockUser: Exception while toggling block status for thread @tid: @message. Trace: @trace', [
        '@tid' => $thread_to_modify->id(),
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while updating the block status. Please try again.'));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * AJAX callback for the block/unblock button.
   */
  public function ajaxBlockUserCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    // The form needs to be rebuilt by the FormBuilder to reflect new state for the AJAX response.
    // $form here is the old form structure.
    $rebuilt_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    $form_wrapper_id = 'match-message-form-wrapper-' . $form_state->getValue('thread_id'); // Use thread_id from form_state
    $response->addCommand(new ReplaceCommand('#' . $form_wrapper_id, $rebuilt_form));

    // Announce the change for screen readers.
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = $this->entityTypeManager->getStorage('match_thread')->load($form_state->getValue('thread_id'));
    /** @var \Drupal\user\UserInterface $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    /** @var \Drupal\user\UserInterface $other_user_obj */
    $other_user_obj = ($thread && $current_user_obj && $thread->getUser1() && $thread->getUser1()->id() == $current_user_obj->id()) ? ($thread->getUser2() ? $thread->getUser2()->entity : NULL) : ($thread && $thread->getUser1() ? $thread->getUser1()->entity : NULL);


    if ($thread && $current_user_obj && $other_user_obj) {
      if ($thread->hasUserBlockedOther($current_user_obj)) {
        $response->addCommand(new AnnounceCommand($this->t('User @username has been blocked.', ['@username' => $other_user_obj->getDisplayName()])));
      } else {
        $response->addCommand(new AnnounceCommand($this->t('User @username has been unblocked.', ['@username' => $other_user_obj->getDisplayName()])));
      }
    }


    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);

    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element && isset($triggering_element['#name']) && $triggering_element['#name'] === 'block_user_button') {
      return; // Skip validation for block/unblock action.
    }

    $thread_id = $form_state->getValue('thread_id');
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = $this->entityTypeManager->getStorage('match_thread')->load($thread_id);

    if (!$thread) {
      $form_state->setErrorByName('message', $this->t('Chat thread not found.'));
      return;
    }

    /** @var \Drupal\user\UserInterface $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    if ($current_user_obj) {
      if ($thread->hasUserBlockedOther($current_user_obj)) {
        $form_state->setErrorByName('message', $this->t('You have blocked this user. Unblock to send messages.'));
      } elseif ($thread->isUserBlockedByOther($current_user_obj)) {
        $form_state->setErrorByName('message', $this->t('This user has blocked you. You cannot send messages.'));
      }
    }

    // Proceed with message content validation only if no block related errors.
    if (!$form_state->hasAnyErrors()) {
      $message_value = $form_state->getValue('message');
      $image_fids = $form_state->getValue('chat_images');

      if (empty(trim($message_value)) && empty($image_fids)) {
        $form_state->setErrorByName('message', $this->t('You must enter a message or upload at least one image.'));
      }

      $uploads_allowed_by_both = $thread->bothParticipantsAllowUploads();
      if (!empty($image_fids) && !$uploads_allowed_by_both) {
        // This check might be redundant if the field is disabled, but good for robustness.
        $form_state->setErrorByName('chat_images', $this->t('File uploads are not allowed by both participants.'));
      }

      if (!empty($image_fids) && count($image_fids) > 3) {
        $form_state->setErrorByName('chat_images', $this->t('You can upload a maximum of 3 images.'));
      }
    }
  }

  /**
   * AJAX submit callback for sending messages.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    $form_wrapper_id = 'match-message-form-wrapper-' . $form_state->getValue('thread_id');

    if ($form_state->hasAnyErrors()) {
      // Errors should include messages from validateForm (e.g., block status).
      $response->addCommand(new ReplaceCommand('#' . $form_wrapper_id, $form));
      // Display errors using MessageCommand if not already handled by Drupal.
      foreach ($form_state->getErrors() as $error_message) {
        $response->addCommand(new MessageCommand($error_message, NULL, ['type' => 'error']));
      }
      return $response;
    }

    // Normal message sending logic continues if no errors.
    // The actual message saving happens in submitForm.
    // This callback is primarily for updating the UI.
    $form_state->setRebuild(TRUE); // Important for clearing form after successful AJAX submission.

    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread_entity */
    $thread_entity = $this->entityTypeManager->getStorage('match_thread')->load($form_state->getValue('thread_id'));
    if ($thread_entity) {
      // Resolve controller and get messages HTML.
      $controller = \Drupal::classResolver()->getInstanceFromDefinition(MatchChatController::class);
      /** @var \Drupal\user\UserInterface $current_user_obj */
      $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      $has_blocked_other = $thread_entity->hasUserBlockedOther($current_user_obj);
      $is_blocked_by_other = $thread_entity->isUserBlockedByOther($current_user_obj);

      $build = $controller->renderMessages($thread_entity, $current_user_obj, $has_blocked_other, $is_blocked_by_other);
      $messages_html_output = \Drupal::service('renderer')->renderRoot($build['messages_list']);
      $response->addCommand(new ReplaceCommand('#match-chat-messages-wrapper', $messages_html_output));
    }

    $rebuilt_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    $response->addCommand(new ReplaceCommand('#' . $form_wrapper_id, $rebuilt_form));
    $response->addCommand(new InvokeCommand('.chat-messages-scroll-container', 'matchChatScrollToBottom'));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // If block/unblock button was clicked, its specific submit handler took care of it.
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element && isset($triggering_element['#name']) && $triggering_element['#name'] === 'block_user_button') {
      // $form_state->setRebuild(TRUE) was already set in submitBlockUser.
      return;
    }

    // Handle actual message submission.
    // Errors should have been caught by validateForm and handled by AJAX callback.
    if ($form_state->hasAnyErrors()) {
      return;
    }

    $values = $form_state->getValues();
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = $this->entityTypeManager->getStorage('match_thread')->load($values['thread_id']);

    if (!$thread) {
      $this->messenger()->addError($this->t('Chat thread not found. Message not sent.'));
      return;
    }

    // Final server-side check for block status before saving message (belt and braces).
    /** @var \Drupal\user\UserInterface $current_user_obj */
    $current_user_obj = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if ($current_user_obj && ($thread->hasUserBlockedOther($current_user_obj) || $thread->isUserBlockedByOther($current_user_obj))) {
      $this->messenger()->addError($this->t('Message not sent due to an active block in this chat.'));
      $form_state->setRebuild(TRUE); // Rebuild to show disabled state and message.
      return;
    }

    $fids = $values['chat_images'] ?? [];
    if (!empty($fids) && !$thread->bothParticipantsAllowUploads()) {
      $this->messenger()->addError($this->t('File uploads are not permitted by both participants. Message sent without files.'));
      $fids = []; // Do not save files.
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
      unset($user_input['message'], $user_input['chat_images']); // Important for clearing the actual input.
      $form_state->setUserInput($user_input);

      if (!$this->getRequest()->isXmlHttpRequest()) {
        $this->messenger()->addStatus($this->t('Message sent.'));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred while sending the message: @error', ['@error' => $e->getMessage()]));
      \Drupal::logger('match_chat')->error('Error sending message: @error. Trace: @trace', ['@error' => $e->getMessage(), '@trace' => $e->getTraceAsString()]);
      // $form_state->setRebuild(FALSE); // On error, maybe don't rebuild or handle differently.
    }
  }
}
