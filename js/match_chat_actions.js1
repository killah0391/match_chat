(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.matchChatActions = {
    attach: function (context, settings) {
      once('match-chat-toggle-block', '.match-chat-toggle-block-user', context).forEach(function (element) {
        $(element).on('click', function (e) {
          e.preventDefault();

          var $link = $(this);
          var threadUuid = $link.data('thread-uuid');
          var targetUserId = $link.data('target-user-id');
          var actionUrl = $link.data('action-url');
          var csrfToken = drupalSettings.matchChat.csrfToken; // Get from drupalSettings

          // Simple confirmation for blocking, can be enhanced
          var currentText = $link.text().trim();
          var isBlockingAction = currentText.toLowerCase().startsWith('block');
          if (isBlockingAction) {
            if (!confirm(Drupal.t('Are you sure you want to block this user? They will not be able to message you, and you will not be able to message them.'))) {
              return;
            }
          }


          $.ajax({
            url: actionUrl,
            type: 'POST',
            dataType: 'json',
            headers: {
              'X-CSRF-Token': csrfToken
            },
            beforeSend: function() {
              $link.addClass('is-loading').css('pointer-events', 'none'); // Basic loading indicator
              $link.text(Drupal.t('Processing...'));
            },
            success: function (response) {
              if (response.success) {
                $link.text(response.action_text);

                // Display success message within the chat area
                var messageHtml = '<div class="messages messages--status" role="status" aria-label="Status message">' + Drupal.checkPlain(response.message) + '</div>';
                $('#match-chat-global-messages-' + threadUuid).html(messageHtml).show();
                setTimeout(function() {
                    $('#match-chat-global-messages-' + threadUuid).fadeOut(500, function() { $(this).empty(); });
                }, 5000);


                // Potentially disable/enable the message form
                // This requires the form to have an ID or for MatchMessageForm to be reloaded/updated
                // For now, we can reload the page for simplicity to reflect form state change, or update specific elements.
                // Or, better, the form should check block status on build. If a full reload is too much:
                var $messageForm = $('#match-message-form-wrapper'); // Wrapper of MatchMessageForm
                var $messageTextarea = $messageForm.find('textarea[name="message"]');
                var $submitButton = $messageForm.find('input[type="submit"][value="' + Drupal.t('Send') + '"]');
                var $uploadSettings = $messageForm.find('#upload-settings-wrapper-' + drupalSettings.matchChat.threadId); // Assuming threadId is passed for wrapper

                if (response.is_blocked) {
                  $messageTextarea.prop('disabled', true).attr('placeholder', Drupal.t('User blocked. Cannot send messages.'));
                  $submitButton.prop('disabled', true);
                  if ($uploadSettings.length) {
                     $uploadSettings.find('input, textarea, button').prop('disabled', true);
                     $uploadSettings.find('.form-item-chat-images').hide(); // Hide upload field specifically
                     $uploadSettings.find('#edit-allow-uploads-toggle').prop('disabled', true);
                  }
                  // Add a visual cue/message if not already present by form rebuild
                  if ($messageForm.find('.chat-blocked-message').length === 0) {
                    $messageForm.prepend('<div class="messages messages--warning chat-blocked-message">' + Drupal.t('This user is blocked. You cannot send messages.') + '</div>');
                  }

                } else {
                  // This part is trickier without a form rebuild, as the form's own logic
                  // on PHP side handles the #disabled state based on both users' upload preferences etc.
                  // A page reload might be the most robust way to ensure form state is correct after unblock.
                  // For a less disruptive UX, the form needs more granular AJAX updates.
                  // For now, let's just enable the basic message sending.
                  $messageTextarea.prop('disabled', false).attr('placeholder', Drupal.t('Type your message...'));
                  $submitButton.prop('disabled', false);
                  $messageForm.find('.chat-blocked-message').remove();
                   // If you want to refresh the form state more completely without full page reload:
                   // You might need an AJAX command to rebuild & replace the form.
                }


              } else {
                var errorMessage = response.message || Drupal.t('An error occurred.');
                var errorHtml = '<div class="messages messages--error" role="alert" aria-label="Error message">' + Drupal.checkPlain(errorMessage) + '</div>';
                $('#match-chat-global-messages-' + threadUuid).html(errorHtml).show();
                $link.text(currentText); // Revert text on error
              }
            },
            error: function (xhr, status, error) {
              var errorMessage = Drupal.t('An error occurred while trying to update block status: !error', {'!error': error });
              if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMessage = xhr.responseJSON.message;
              }
              var errorHtml = '<div class="messages messages--error" role="alert" aria-label="Error message">' + Drupal.checkPlain(errorMessage) + '</div>';
              $('#match-chat-global-messages-' + threadUuid).html(errorHtml).show();
              $link.text(currentText); // Revert text on error
            },
            complete: function () {
              $link.removeClass('is-loading').css('pointer-events', '');
            }
          });
        });
      });
    }
  };

}(jQuery, Drupal, drupalSettings, once));
