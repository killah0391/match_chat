<?php

namespace Drupal\match_chat\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\HtmlCommand; // To clear previous messages from the target container
use Drupal\match_chat\Entity\MatchThreadInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a form for chat settings (file uploads) within a popover.
 */
class ChatSettingsPopoverForm extends FormBase
{

  protected EntityTypeManagerInterface $entityTypeManager;
  use StringTranslationTrait;

  /**
   * The MatchAbuseController.
   *
   * @var \Drupal\match_abuse\Controller\MatchAbuseController|null
   */
  protected $matchAbuseController;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  // Target selector for AJAX messages, consistent with MatchMessageForm if desired.
  // Or a more specific one if popover messages need a different location.
  const AJAX_MESSAGES_CONTAINER_SELECTOR = '.chat-status-messages'; // Or another stable selector

 /**
  * @param \Drupal\match_abuse\Controller\MatchAbuseController|null $match_abuse_controller
  *   The match abuse controller.
  */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    ?\Drupal\match_abuse\Controller\MatchAbuseController $match_abuse_controller
  )
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->matchAbuseController = $match_abuse_controller;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('class_resolver')->getInstanceFromDefinition(\Drupal\match_abuse\Controller\MatchAbuseController::class)
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

    // The thread ID (UUID) will be passed as a query parameter to the AJAX block/unblock URLs.
    // This allows the MatchAbuseController to know which MatchMessageForm to refresh.
    $chat_thread_id_for_query_param = $thread->id(); // This is the UUID.

    // Determine the other user in the chat.
    $other_user_in_chat = NULL;
    if ($user1->id() === $current_user_obj->id()) {
      $other_user_in_chat = $user2;
    }
    elseif ($user2->id() === $current_user_obj->id()) {
      $other_user_in_chat = $user1;
    }

    // Add Block/Unblock link if applicable.
    if ($other_user_in_chat &&
        $this->currentUser->id() != $other_user_in_chat->id() &&
        $this->currentUser->hasPermission('block users') &&
        $this->matchAbuseController
    ) {
        // Define the specific options for the chat popover context.
        // These ensure the link triggers the modal and is styled for the popover.
        $chat_popover_link_options = [
            'wrapper_classes' => ['mt-3', 'mb-n4', 'mx-n4', 'js-form-wrapper', 'form-wrapper', 'mb-3'],
            // 'match-abuse-confirm-trigger' is added by MatchAbuseController by default now.
            // 'use-ajax' is omitted as modal handles the action.
            'link_classes_base' => ['js-match-abuse-confirm-action', 'match-abuse-link', 'btn', 'd-block', 'w-100', 'rounded-top-0'],
            'link_classes_block_state' => ['btn-danger', 'text-muted'],
            'link_classes_unblock_state' => ['btn-success', 'text-muted'],
            // Icon will use the default from MatchAbuseController::getBlockLinkRenderArray.
        ];

        $form['block_user_action_wrapper'] = $this->matchAbuseController->getBlockLinkRenderArray(
            $other_user_in_chat,
            $chat_thread_id_for_query_param,
            $chat_popover_link_options // Pass the specific options for chat popover
        );
        // The key 'block_user_action_wrapper' must match the one used in
        // MatchAbuseController for AJAX replacement to work correctly.
        $form['block_user_action_wrapper']['#weight'] = 100; // High weight to push to the bottom

      // Attach libraries required for AJAX link and toast notifications.
      $form['#attached']['library'][] = 'core/drupal.ajax';
      $form['#attached']['library'][] = 'match_abuse/match-abuse-script';
    }

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

    return $form;
  }

  /**
   * Helper function to add AJAX status messages using the preferred Drupal method.
   */
  protected function addDrupalAjaxMessages(AjaxResponse $response, string $target_selector = self::AJAX_MESSAGES_CONTAINER_SELECTOR)
  {
    // Retrieve AND clear messages from the session queue.
    $messages_for_ajax = \Drupal::messenger()->deleteAll();

    // Clear previous messages from the target container to avoid accumulation.
    $response->addCommand(new HtmlCommand($target_selector, ''));

    if (!empty($messages_for_ajax)) {
      $messages_render_array = [
        '#theme' => 'status_messages',
        '#message_list' => $messages_for_ajax,
        '#status_headings' => [
          'status' => $this->t('Status message'),
          'error' => $this->t('Error message'),
          'warning' => $this->t('Warning message'),
        ],
      ];
      $response->addCommand(new AppendCommand($target_selector, $messages_render_array));
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
        $this->addDrupalAjaxMessages($response); // Show this error too.
        \Drupal::logger('match_chat')->error('ajaxUploadsToggleCallback: Exception getting MatchMessageForm for thread @tid: @msg.', ['@tid' => $thread->id(), '@msg' => $e->getMessage()]);
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
