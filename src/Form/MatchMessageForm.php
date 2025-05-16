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
      '#required' => FALSE, // Message not required if image is uploaded
      '#attributes' => ['placeholder' => $this->t('Type your message...'), 'style' => 'resize: none;'],
      '#resizable' => NULL,
      '#rows' => 3,
    ];

    // New file upload field
    $form['chat_images'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Attach image'),
      '#upload_location' => 'private://match_chat_images/', // Ensure this path is valid & writable
      '#upload_validators' => [
        'file_validate_extensions' => ['png gif jpg jpeg'],
        'file_validate_size' => [2 * 1024 * 1024], // 2MB in bytes
        // 'file_validate_image_resolution' => ['4000x4000'], // Max resolution
        // 'file_validate_image_resolution' => ['50x50'] // Min resolution
      ],
      '#description' => $this->t('Allowed extensions: png, gif, jpg, jpeg. Max 2MB per file.'),
      // '#cardinality' => 3, // The widget doesn't directly use this, field definition does.
      // We'll validate cardinality in validateForm.
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
        'wrapper' => 'match-message-form-wrapper', // Consider replacing the whole form for file field reset
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
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);

    $message_value = $form_state->getValue('message');
    $image_fids = $form_state->getValue('chat_images');

    if (empty(trim($message_value)) && empty($image_fids)) {
      $form_state->setErrorByName('message', $this->t('You must enter a message or upload at least one image.'));
    }

    if (!empty($image_fids) && count($image_fids) > 3) {
      $form_state->setErrorByName('chat_images', $this->t('You can upload a maximum of 1 image.'));
    }
  }

  /**
   * AJAX submit callback.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();

    // Check for validation errors FIRST.
    if ($form_state->hasAnyErrors()) {
      \Drupal::logger('match_chat')->debug('AJAX callback: Form has validation errors.');

      // Option 1: Let Drupal re-render the form with inline errors.
      // The $form array should now contain error markup if the theme supports it well for AJAX.
      // This command replaces the form's HTML with its new state (including error classes/messages).
      $response->addCommand(new ReplaceCommand('#match-message-form-wrapper', $form));

      // Option 2: Explicitly add error messages to the Drupal messages area.
      // This is often more reliable for ensuring the user sees the message,
      // especially if inline errors within the form are subtle or not styled prominently by the theme.
      // We can combine this with Option 1.
      $errors = $form_state->getErrors();
      foreach ($errors as $field_name => $error_message) {
        // The MessageCommand will add the error to the standard Drupal messages region.
        $response->addCommand(new MessageCommand($error_message, NULL, ['type' => 'error']));
        // Log which error is being sent
        \Drupal::logger('match_chat')->debug('AJAX MessageCommand: Added error for field "@field": @message', ['@field' => $field_name, '@message' => $error_message]);
      }

      return $response; // IMPORTANT: Return immediately when there are errors.
    }

    // If no errors, proceed with successful submission logic:
    $form_state->setRebuild(TRUE);

    // Update the messages list
    if ($this->thread && $this->thread->id()) {
      $thread_id = $this->thread->id();
      $thread = $this->entityTypeManager->getStorage('match_thread')->load($thread_id);
      if ($thread) {
        $controller = \Drupal::classResolver(MatchChatController::class)->create(\Drupal::getContainer());
        $build = $controller->renderMessages($thread);
        $messages_html_output = \Drupal::service('renderer')->renderRoot($build['messages_list']);
        $response->addCommand(new ReplaceCommand('#match-chat-messages-wrapper', $messages_html_output));
      }
    }

    // Rebuild and replace the form (for clearing fields on success)
    $rebuilt_form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    $response->addCommand(new ReplaceCommand('#match-message-form-wrapper', $rebuilt_form));

    // Scroll to bottom
    $response->addCommand(new InvokeCommand('.chat-messages-scroll-container', 'matchChatScrollToBottom'));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // If form is rebuilt due to AJAX, and there were errors, don't submit.
    if ($form_state->hasAnyErrors()) {
      return;
    }

    $values = $form_state->getValues();
    $fids = $values['chat_images']; // Array of file IDs

    try {
      /** @var \Drupal\match_chat\Entity\MatchMessageInterface $message_entity */
      $message_entity = $this->entityTypeManager->getStorage('match_message')->create([
        'sender' => $this->currentUser->id(),
        'thread_id' => $values['thread_id'],
        'message' => $values['message'],
        'chat_images' => $fids, // Assign the array of FIDs
      ]);
      $message_entity->save();

      // File usage is handled in hook_match_message_presave etc.

      /** @var \Drupal\match_chat\Entity\MatchThreadInterface $thread */
      $thread = $this->entityTypeManager->getStorage('match_thread')->load($values['thread_id']);
      if ($thread) {
        $thread->setChangedTime(\Drupal::time()->getRequestTime());
        $thread->save();
      }

      // --- Add/Modify these lines for clearing form state ---
      // Clear the processed values from the form state.
      // This is a good practice for AJAX forms that rebuild.
      $form_state->setValue('message', '');
      $form_state->setValue('chat_images', []); // For managed_file, an empty array is appropriate.

      // Also, clear the specific user inputs so they don't repopulate on rebuild.
      $user_input = $form_state->getUserInput();
      unset($user_input['message']);
      unset($user_input['chat_images']);
      // You might also need to unset other form controls if they cause issues,
      // e.g., $user_input['op'] (the submit button value), $user_input['form_build_id'], etc.,
      // but usually this is sufficient for field values.
      $form_state->setUserInput($user_input);
      // --- End of clearing form state modifications ---

      $request = $this->getRequest();
      if (!$request->isXmlHttpRequest()) {
        $this->messenger()->addStatus($this->t('Message sent.'));
      }
      // Note: $form_state->setRebuild(TRUE) is primarily handled in the ajaxSubmitCallback
      // for AJAX submissions to trigger the form rebuild there.

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred while sending the message: @error', ['@error' => $e->getMessage()]));
      \Drupal::logger('match_chat')->error('Error sending message: @error. Trace: @trace', ['@error' => $e->getMessage(), '@trace' => $e->getTraceAsString()]);
      // If an error occurs, prevent the form from being marked for rebuild if it was set by AJAX.
      $form_state->setRebuild(FALSE);
    }
  }
}
