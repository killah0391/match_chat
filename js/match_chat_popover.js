// js/match_chat_popover.js
(function ($, Drupal, drupalSettings, bootstrap) {
  'use strict';

  Drupal.behaviors.matchChatPopover = {
    attach: function (context, settings) {
      // Find the trigger button within the current context.
      // This is more reliable than using a wrapper selector when content is replaced by AJAX.
      const popoverTriggerEl = once('match-chat-popover-trigger', '[id^="chat-settings-popover-trigger-"]', context).shift();

      if (!popoverTriggerEl) {
        return; // No new trigger found in this context.
      }

      // Before initializing a new popover, clean up any orphaned popover tips from previous AJAX calls.
      // This is a common issue when the trigger is removed but the popover tip remains in the body.
      $('.popover.match-chat-settings-popover').remove();

      const threadId = popoverTriggerEl.id.split('-').pop();
      if (!threadId) {
        console.warn('Match Chat JS: Could not determine thread ID for popover from trigger.', popoverTriggerEl);
        return;
      }

      const popoverContentOuterContainerEl = document.getElementById('chat-settings-popover-content-container-' + threadId);

      if (popoverContentOuterContainerEl) {
        // The popover form now creates its own wrapper: e.g., #chat-settings-popover-form-wrapper-1
        const formInternalWrapperIdTpl = settings.match_chat && settings.match_chat.popover_form_internal_wrapper_id_tpl
          ? settings.match_chat.popover_form_internal_wrapper_id_tpl
          : 'chat-settings-popover-form-wrapper-'; // Default if not in drupalSettings
        const formInternalWrapperSelector = '#' + formInternalWrapperIdTpl + threadId;
        const formWrapperEl = popoverContentOuterContainerEl.querySelector(formInternalWrapperSelector);

        if (formWrapperEl) {
          // Dispose of any existing popover instance attached to this specific element.
          const existingPopover = bootstrap.Popover.getInstance(popoverTriggerEl);
          if (existingPopover) {
            existingPopover.dispose();
          }

          const popover = new bootstrap.Popover(popoverTriggerEl, {
            html: true,
            title: Drupal.t('Chat Settings'),
            content: formWrapperEl,
            sanitize: false,
            placement: 'left',
            trigger: 'click',
            customClass: 'match-chat-settings-popover',
          });

          // Re-attach Drupal behaviors to the content when the popover is shown.
          popoverTriggerEl.addEventListener('shown.bs.popover', function () {
            if (popover.tip) {
              const popoverBody = popover.tip.querySelector('.popover-body');
              if (popoverBody) {
                Drupal.attachBehaviors(popoverBody, settings);
              }
            }
          });
        } else {
          console.warn('Match Chat JS: Popover FORM content for thread ' + threadId + ' (expected ' + formInternalWrapperSelector + ') is missing within its container.');
        }
      } else {
        console.warn('Match Chat JS: Popover content OUTER CONTAINER element (#chat-settings-popover-content-container-' + threadId + ') NOT FOUND for thread ' + threadId + '.');
      }
    }
  };
})(jQuery, Drupal, drupalSettings, bootstrap);
