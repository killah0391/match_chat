// js/match_chat_popover.js
(function ($, Drupal, drupalSettings, bootstrap) {
  'use strict';

  Drupal.behaviors.matchChatPopover = {
    attach: function (context, settings) {
      $(once('match-chat-popover-init', '.match-chat-thread-wrapper', context)).each(function () {
        const wrapper = this;
        const threadId = settings.match_chat && settings.match_chat.thread_id
          ? settings.match_chat.thread_id
          : $(wrapper).find('[id^="chat-settings-popover-trigger-"]').attr('id')?.split('-').pop();

        if (!threadId) {
          console.warn('Match Chat JS: Could not determine thread ID for popover.');
          return;
        }

        const popoverTriggerEl = document.getElementById('chat-settings-popover-trigger-' + threadId); // Renamed ID
        const popoverContentOuterContainerEl = document.getElementById('chat-settings-popover-content-container-' + threadId); // Renamed ID

        if (popoverTriggerEl && popoverContentOuterContainerEl) {
          // The popover form now creates its own wrapper: e.g., #chat-settings-popover-form-wrapper-1
          const formInternalWrapperIdTpl = settings.match_chat && settings.match_chat.popover_form_internal_wrapper_id_tpl
            ? settings.match_chat.popover_form_internal_wrapper_id_tpl
            : 'chat-settings-popover-form-wrapper-'; // Default if not in drupalSettings
          const formInternalWrapperSelector = '#' + formInternalWrapperIdTpl + threadId;
          const formWrapperEl = popoverContentOuterContainerEl.querySelector(formInternalWrapperSelector);

          if (formWrapperEl) {
            const existingPopover = bootstrap.Popover.getInstance(popoverTriggerEl);
            if (existingPopover) {
              existingPopover.dispose();
            }

            const popover = new bootstrap.Popover(popoverTriggerEl, {
              html: true,
              title: Drupal.t('Chat Settings'), // Updated title
              content: formWrapperEl,
              sanitize: false,
              placement: 'left',
              trigger: 'click',
              customClass: 'match-chat-settings-popover', // Updated class
            });

            popoverTriggerEl.addEventListener('shown.bs.popover', function () {
              if (popover.tip) {
                const popoverBody = popover.tip.querySelector('.popover-body');
                if (popoverBody) {
                  Drupal.attachBehaviors(popoverBody, settings);
                }
              }
            });
          } else {
            console.warn('Match Chat JS: Popover FORM content for thread ' + threadId + ' (expected ' + formInternalWrapperSelector + ') is missing within its container. Container HTML: "' + popoverContentOuterContainerEl.innerHTML + '"');
          }
        } else if (popoverTriggerEl && !popoverContentOuterContainerEl) {
          console.warn('Match Chat JS: Popover content OUTER CONTAINER element (#chat-settings-popover-content-container-' + threadId + ') NOT FOUND for thread ' + threadId + '.');
        }
      });
    }
  };
})(jQuery, Drupal, drupalSettings, bootstrap);
