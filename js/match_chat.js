(function ($, Drupal, once) {
  'use strict';

  function scrollElementToBottom($element) {
    if ($element && $element.length && $element[0].scrollHeight > $element.innerHeight()) {
      setTimeout(function () {
        $element.scrollTop($element[0].scrollHeight);
      }, 100);
    }
  }

  $.fn.matchChatScrollToBottom = function () {
    return this.each(function () {
      scrollElementToBottom($(this));
    });
  };

  Drupal.behaviors.matchChatAutoScroll = {
    attach: function (context, settings) {
      const scrollContainers = once('match-chat-initial-scroll', '.chat-messages-scroll-container', context);
      scrollContainers.forEach(function (element) {
        scrollElementToBottom($(element));
      });
    }
  };

  Drupal.behaviors.matchChatInteraction = {
    attach: function (context, settings) {
      const $chatContainerRow = $('.match-chat-container .row').first();

      // Handle clicking on a thread in the sidebar.
      $(once('match-chat-thread-loader', '.chat-thread-link', context)).on('click', function (e) {
        e.preventDefault();

        // Before loading a new thread, find and hide any currently open popover.
        // This ensures its content is moved back to the original container before
        // the container is destroyed by the AJAX replacement.
        const openPopoverTrigger = document.querySelector('[id^="chat-settings-popover-trigger-"][aria-describedby]');
        if (openPopoverTrigger) {
          bootstrap.Popover.getInstance(openPopoverTrigger)?.hide();
        }

        const $link = $(this);
        const threadUuid = $(this).data('thread-uuid');
        const ajaxUrl = Drupal.url('chat/load-thread/' + threadUuid);
        const newBrowserUrl = Drupal.url('chat/my-threads/' + threadUuid);

        if (threadUuid) {
          // Update the active state in the sidebar.
          $('.match-threads-list .list-group-item').removeClass('active');
          $link.addClass('active');

          // Show loading indicator.
          $('#chat-conversation-area').html('<div class="card-body d-flex align-items-center justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');

          // Add class to show conversation on mobile.
          $chatContainerRow.addClass('conversation-active');

          // Update browser URL for better history and bookmarking.
          history.pushState({ path: newBrowserUrl }, '', newBrowserUrl);

          // Make an AJAX request to load the thread content.
          $.ajax({
            url: ajaxUrl,
            type: 'GET',
            dataType: 'json',
            success: function (response) {
              var ajax = new Drupal.Ajax(false, false, { url: ajaxUrl });
              ajax.success(response, 'success');
            },
            error: function (xhr, status, error) {
              console.error('Error loading chat thread:', error);
              $('#chat-conversation-area').html('<div class="card-body text-danger">' + Drupal.t('Failed to load chat. Please try again.') + '</div>');
            }
          });
        }
      });

      // Handle clicking the mobile back button.
      $(once('chat-back-button-handler', '.chat-back-button', context)).on('click', function (e) {
        e.preventDefault();
        const mainThreadsUrl = Drupal.url('chat/my-threads');

        // Remove the class to show the sidebar on mobile.
        $chatContainerRow.removeClass('conversation-active');

        // Update the URL back to the main list view.
        history.pushState({ path: mainThreadsUrl }, '', mainThreadsUrl);

        // De-select any active thread in the list.
        $('.match-threads-list .list-group-item').removeClass('active');
      });
    }
  };
})(jQuery, Drupal, once);
