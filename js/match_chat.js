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

  Drupal.behaviors.matchChatThreadLoader = {
    attach: function (context, settings) {
      // Use 'once' to ensure the event handler is attached only once.
      $(once('match-chat-thread-loader', '.chat-thread-link', context)).each(function () {
        $(this).on('click', function (e) {
          e.preventDefault();
          const threadUuid = $(this).data('thread-uuid');
          const ajaxUrl = Drupal.url('chat/load-thread/' + threadUuid);

          if (threadUuid) {
            // Remove 'active' class from all links and add to the clicked one.
            $('.match-threads-list .list-group-item').removeClass('active');
            $(this).addClass('active');

            // Show a loading indicator in the conversation area.
            $('#chat-conversation-area').html('<div class="card-body d-flex align-items-center justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');

            // Make an AJAX request to load the thread content.
            $.ajax({
              url: ajaxUrl,
              type: 'GET',
              dataType: 'json',
              success: function (response) {
                // Manually process the Drupal AJAX commands in the JSON response.
                // The Drupal.Ajax object needs a URL in its settings to initialize correctly.
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
      });
    }
  };

  Drupal.behaviors.matchChatThreadLoader = {
    attach: function (context, settings) {
      // Use 'once' to ensure the event handler is attached only once.
      $(once('match-chat-thread-loader', '.chat-thread-link', context)).each(function () {
        $(this).on('click', function (e) {
          e.preventDefault();
          const threadUuid = $(this).data('thread-uuid');
          const ajaxUrl = Drupal.url('chat/load-thread/' + threadUuid);

          if (threadUuid) {
            // Remove 'active' class from all links and add to the clicked one.
            $('.match-threads-list .list-group-item').removeClass('active');
            $(this).addClass('active');

            // Show a loading indicator in the conversation area.
            $('#chat-conversation-area').html('<div class="card-body d-flex align-items-center justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');

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
      });
    }
  };

})(jQuery, Drupal, once);
