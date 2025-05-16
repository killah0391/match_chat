(function ($, Drupal, once) { // Add 'once' to the function parameters
  'use strict';

  /**
   * Helper function to scroll a jQuery element to its bottom.
   * @param {jQuery} $element The jQuery element to scroll.
   */
  function scrollElementToBottom($element) {
    if ($element && $element.length && $element[0].scrollHeight > $element.innerHeight()) {
      // A small timeout helps ensure the DOM is updated and images (if any)
      // are considered in scrollHeight, especially on page load or after AJAX.
      setTimeout(function () {
        $element.scrollTop($element[0].scrollHeight);
      }, 100); // Adjust timeout if necessary (e.g., 50-150ms).
    }
  }

  /**
   * Custom jQuery plugin to scroll an element to its bottom.
   * This is callable by Drupal's InvokeCommand.
   * Example in PHP: new InvokeCommand('.chat-messages-scroll-container', 'matchChatScrollToBottom')
   */
  $.fn.matchChatScrollToBottom = function () {
    // 'this' refers to the jQuery object (the selected elements).
    return this.each(function () {
      // Inside .each(), 'this' is the raw DOM element.
      scrollElementToBottom($(this));
    });
  };

  /**
   * Drupal behavior to scroll the chat to the bottom on initial page load
   * and potentially for other AJAX updates if not handled by InvokeCommand.
   */
  Drupal.behaviors.matchChatAutoScroll = {
    attach: function (context, settings) {
      // Use the new 'once' utility.
      // It finds all elements matching the selector within the given context
      // that have not yet been processed with the 'match-chat-initial-scroll' ID.
      // 'once' returns an array of raw DOM elements.
      const scrollContainers = once('match-chat-initial-scroll', '.chat-messages-scroll-container', context);

      // Iterate over the newly found, unprocessed elements.
      scrollContainers.forEach(function (element) {
        scrollElementToBottom($(element)); // Pass the jQuery object to our helper
      });
    }
  };

})(jQuery, Drupal, once); // Make sure 'once' is passed as an argument here
