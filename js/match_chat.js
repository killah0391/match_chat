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

})(jQuery, Drupal, once);
