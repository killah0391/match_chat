<?php

namespace Drupal\match_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\match_chat\Entity\MatchThreadInterface;
use Drupal\match_chat\Controller\MatchChatController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Ajax\MessageCommand;

/**
 * Form for sending a new chat message.
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
    if ($match_thread) {
      $this->setThread($match_thread);
    }

    if (!$this->thread) {
      $form['error'] = [
        '#markup' => $this->t('No chat thread specified.'),
      ];
      return $form;
    }

    $user1_id = $this->thread->get('user1')->target_id;
    $user2_id = $this->thread->get('user2')->target_id;
    $current_user_id = $this->currentUser->id();

    if ($current_user_id != $user1_id && $current_user_id != $user2_id) {
      $form['error'] = [
        '#markup' => $this->t('You do not have permission to post in this thread.'),
      ];
      return $form;
    }

    $form['#prefix'] = '<div id="match-message-form-wrapper">';
    $form['#suffix'] = '</div>';

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#title_display' => 'invisible',
      '#required' => FALSE, // Message not required if image is uploaded or uploads allowed
      '#attributes' => ['placeholder' => $this->t('Type your message...'), 'style' => 'resize: none;'],
      '#resizable' => NULL,
      '#rows' => 3,
    ];

    // Section for upload controls, to be targeted by AJAX.
    $form['upload_settings_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'upload-settings-wrapper-' . $this->thread->id()], // Unique ID per thread instance
    ];

    $current_user_allows_uploads = FALSE;
    if ($this->thread->getUser1()->id() == $current_user_id) {
      $current_user_allows_uploads = $this->thread->getUser1AllowsUploads();
    } elseif ($this->thread->getUser2()->id() == $current_user_id) {
      $current_user_allows_uploads = $this->thread->getUser2AllowsUploads();
    }

    $form['upload_settings_wrapper']['allow_uploads_toggle'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I allow file uploads in this chat'),
      '#default_value' => $current_user_allows_uploads,
      '#ajax' => [
        'callback' => '::ajaxAllowUploadsToggleCallback',
        'wrapper' => 'upload-settings-wrapper-' . $this->thread->id(),
        'event' => 'change',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Updating preference...'),
        ],
      ],
    ];

    // File upload field
    $form['upload_settings_wrapper']['chat_images'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Attach image(s)'),
      '#upload_location' => 'private://match_chat_images/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png gif jpg jpeg'],
        'file_validate_size' => [2 * 1024 * 1024], // 2MB
      ],
      '#description' => $this->t('Allowed extensions: png, gif, jpg, jpeg. Max 2MB per file. Max 3 files.'),
      // '#multiple' => TRUE, // For the widget to allow multiple, though cardinality is on field def.
      // The '#access' property controls if the field is rendered at all.
      // We use #disabled to show it but make it unusable if uploads are not permitted by both.
      '#disabled' => !$this->thread->bothParticipantsAllowUploads(),
      '#access' => TRUE, // Always show the field, but disable if needed.
      // Provide a message if uploads are disabled.
      '#prefix' => !$this->thread->bothParticipantsAllowUploads() ? '<div class="messages messages--warning">' . $this->t('File uploads are currently disabled because both participants need to allow them. You can enable them using the checkbox above.') . '</div>' : '',
    ];


    $form['thread_id'] = [
      '#type' => 'hidden',
      '#value' => $this->thread->id(),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      '#ajax' => [
        'callback' => '::ajaxSubmitCallback',
        'wrapper' => 'match-message-form-wrapper',
        'disable-refocus' => FALSE,
        'effect' => 'fade',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Sending...'),
        ],
      ],
    ];

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
      if ($thread->getUser1()->id() == $current_user_id) {
        $thread->setUser1AllowsUploads($checkbox_value);
      } elseif ($thread->getUser2()->id() == $current_user_id) {
        $thread->setUser2AllowsUploads($checkbox_value);
      }
      $thread->save();

      // Update the form elements based on the new state.
      // We need to rebuild the relevant part of the form.
      $form['upload_settings_wrapper']['chat_images']['#disabled'] = !$thread->bothParticipantsAllowUploads();
      $form['upload_settings_wrapper']['chat_images']['#prefix'] = !$thread->bothParticipantsAllowUploads() ? '<div class="messages messages--warning">' . $this->t('File uploads are currently disabled because both participants need to allow them. You can enable them using the checkbox above.') . '</div>' : '';


      $response->addCommand(new ReplaceCommand('#upload-settings-wrapper-' . $thread->id(), $form['upload_settings_wrapper']));
    } else {
      $response->addCommand(new MessageCommand($this->t('Could not update upload preference. Thread not found.'), NULL, ['type' => 'error']));
    }

    return $response;
  }


  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);

    $message_value = $form_state->getValue('message');
    $image_fids = $form_state->getValue('chat_images'); // This will be an array of fids.

    $thread_id = $form_state->getValue('thread_id');
    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = $this->entityTypeManager->getStorage('match_thread')->load($thread_id);

    if (!$thread) {
      $form_state->setErrorByName('message', $this->t('Chat thread not found.'));
      return;
    }

    $uploads_allowed_by_both = $thread->bothParticipantsAllowUploads();

    if (empty(trim($message_value)) && empty($image_fids)) {
      $form_state->setErrorByName('message', $this->t('You must enter a message or upload at least one image.'));
    }

    if (!empty($image_fids) && !$uploads_allowed_by_both) {
      $form_state->setErrorByName('chat_images', $this->t('File uploads are not allowed by both participants. Please enable them or remove the files.'));
    }

    // Validate cardinality for chat_images
    if (!empty($image_fids) && count($image_fids) > 3) {
      // Note: The MatchMessage entity definition has cardinality 3.
      // This check should ideally be aligned with that.
      $form_state->setErrorByName('chat_images', $this->t('You can upload a maximum of 3 images.'));
    }
  }

  /**
   * AJAX submit callback.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      $response->addCommand(new ReplaceCommand('#match-message-form-wrapper', $form));
      $errors = $form_state->getErrors();
      foreach ($errors as $error_message) {
        $response->addCommand(new MessageCommand($error_message, NULL, ['type' => 'error']));
      }
      return $response;
    }

    $form_state->setRebuild(TRUE); // Important for clearing form after successful AJAX submission.

    if ($this->thread && $this->thread->id()) {
      $thread_entity = $this->entityTypeManager->getStorage('match_thread')->load($this->thread->id());
      if ($thread_entity) {
        // The controller for MatchChatController might need to be retrieved from the container
        // if it has dependencies, or ensure MatchChatController::create can be called statically
        // if you resolve it via \Drupal::classResolver.
        // $controller = MatchChatController::create(\Drupal::getContainer());
        $controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(MatchChatController::class);

        $build = $controller->renderMessages($thread_entity);
        $messages_html_output = \Drupal::service('renderer')->renderRoot($build['messages_list']);
        $response->addCommand(new ReplaceCommand('#match-chat-messages-wrapper', $messages_html_output));
      }
    }

    // Rebuild and replace the form to clear it and update upload settings state.
    // Passing $form here to rebuildForm ensures it rebuilds with the latest values/states.
    $rebuilt_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    $response->addCommand(new ReplaceCommand('#match-message-form-wrapper', $rebuilt_form));

    $response->addCommand(new InvokeCommand('.chat-messages-scroll-container', 'matchChatScrollToBottom'));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    if ($form_state->hasAnyErrors()) {
      return; // Errors handled by AJAX callback.
    }

    $values = $form_state->getValues();
    $fids = $values['chat_images'] ?? []; // Default to empty array if not set.

    /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
    $thread = $this->entityTypeManager->getStorage('match_thread')->load($values['thread_id']);

    if (!$thread) {
      $this->messenger()->addError($this->t('Chat thread not found. Message not sent.'));
      return;
    }

    // Final check if uploads are allowed, in case validation was bypassed or state changed.
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

      if ($thread) {
        $thread->setChangedTime(\Drupal::time()->getRequestTime());
        $thread->save();
      }

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
      $form_state->setRebuild(FALSE); // Prevent rebuild on error if it's an AJAX request
    }
  }
}
