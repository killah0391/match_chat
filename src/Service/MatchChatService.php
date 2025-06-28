<?php

namespace Drupal\match_chat\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for Match Chat related operations.
 */
class MatchChatService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new MatchChatService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Gets the total number of unread messages for a given user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return int
   *   The total number of unread messages.
   */
  public function getTotalUnreadMessages(AccountInterface $account): int {
    if ($account->isAnonymous()) {
      return 0;
    }

    $current_user_id = $account->id();
    $thread_storage = $this->entityTypeManager->getStorage('match_thread');
    $message_storage = $this->entityTypeManager->getStorage('match_message');

    $total_unread_count = 0;

    // Query for all chat threads involving the current user.
    $query = $thread_storage->getQuery()
      ->accessCheck(TRUE); // Ensure access is checked for threads.
    $group = $query->orConditionGroup()
      ->condition('user1', $current_user_id)
      ->condition('user2', $current_user_id);
    $query->condition($group);

    $thread_ids = $query->execute();

    if (empty($thread_ids)) {
      return 0;
    }

    $threads = $thread_storage->loadMultiple($thread_ids);

    foreach ($threads as $thread) {
      // Determine the last seen timestamp for the current user in this thread.
      $last_seen_timestamp = 0;
      if ($current_user_id == $thread->getUser1()->id()) {
        $last_seen_timestamp = $thread->getUser1LastSeenTimestamp() ?? 0;
      } elseif ($current_user_id == $thread->getUser2()->id()) {
        $last_seen_timestamp = $thread->getUser2LastSeenTimestamp() ?? 0;
      }

      // Query for unread messages in this specific thread.
      $unread_query = $message_storage->getQuery()
        ->accessCheck(TRUE) // Ensure access is checked for messages.
        ->condition('thread_id', $thread->id())
        ->condition('sender', $current_user_id, '<>') // Messages not from the current user.
        ->condition('created', $last_seen_timestamp, '>'); // Messages newer than last seen.

      $unread_count_in_thread = (int) $unread_query->count()->execute();
      $total_unread_count += $unread_count_in_thread;
    }

    return $total_unread_count;
  }

}
