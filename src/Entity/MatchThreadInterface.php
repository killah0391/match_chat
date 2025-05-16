<?php

namespace Drupal\match_chat\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;

/**
 * Provides an interface for defining Match Thread entities.
 */
interface MatchThreadInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface
{

  /**
   * Gets the Match Thread creation timestamp.
   *
   * @return int
   * Creation timestamp of the Match Thread.
   */
  public function getCreatedTime();

  /**
   * Sets the Match Thread creation timestamp.
   *
   * @param int $timestamp
   * The Match Thread creation timestamp.
   *
   * @return \Drupal\match_chat\Entity\MatchThreadInterface
   * The called Match Thread entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Gets the user 1 of the thread.
   *
   * @return \Drupal\user\UserInterface|null
   * The user 1 entity.
   */
  public function getUser1();

  /**
   * Sets the user 1 of the thread.
   *
   * @param int $uid
   * The user ID of user 1.
   *
   * @return \Drupal\match_chat\Entity\MatchThreadInterface
   * The called Match Thread entity.
   */
  public function setUser1($uid);

  /**
   * Gets the user 2 of the thread.
   *
   * @return \Drupal\user\UserInterface|null
   * The user 2 entity.
   */
  public function getUser2();

  /**
   * Sets the user 2 of the thread.
   *
   * @param int $uid
   * The user ID of user 2.
   *
   * @return \Drupal\match_chat\Entity\MatchThreadInterface
   * The called Match Thread entity.
   */
  public function setUser2($uid);

  /**
   * Gets the Match Thread UUID.
   *
   * @return string
   * UUID of the Match Thread.
   */
  public function getUuid();

  /**
   * Checks if the given user is a participant in this thread.
   *
   * @param \Drupal\user\UserInterface $account
   * The user account to check.
   *
   * @return bool
   * TRUE if the user is a participant, FALSE otherwise.
   */
  public function isParticipant(UserInterface $account);

  /**
   * Gets whether user 1 allows uploads in this thread.
   *
   * @return bool
   * TRUE if user 1 allows uploads, FALSE otherwise.
   */
  public function getUser1AllowsUploads(): bool;

  /**
   * Sets whether user 1 allows uploads in this thread.
   *
   * @param bool $allow
   * TRUE to allow uploads, FALSE otherwise.
   *
   * @return \Drupal\match_chat\Entity\MatchThreadInterface
   * The called Match Thread entity.
   */
  public function setUser1AllowsUploads(bool $allow): self;

  /**
   * Gets whether user 2 allows uploads in this thread.
   *
   * @return bool
   * TRUE if user 2 allows uploads, FALSE otherwise.
   */
  public function getUser2AllowsUploads(): bool;

  /**
   * Sets whether user 2 allows uploads in this thread.
   *
   * @param bool $allow
   * TRUE to allow uploads, FALSE otherwise.
   *
   * @return \Drupal\match_chat\Entity\MatchThreadInterface
   * The called Match Thread entity.
   */
  public function setUser2AllowsUploads(bool $allow): self;

  /**
   * Checks if both participants allow uploads in this thread.
   *
   * @return bool
   * TRUE if both participants allow uploads, FALSE otherwise.
   */
  public function bothParticipantsAllowUploads(): bool;

  /**
   * Checks if the $blocker user has blocked the other participant in this thread.
   *
   * For example, if $blocker is User1, this checks user1_blocked_user2.
   *
   * @param \Drupal\user\UserInterface $blocker
   * The user to check if they have initiated a block.
   *
   * @return bool
   * TRUE if $blocker has blocked the other participant, FALSE otherwise.
   */
  public function hasUserBlockedOther(UserInterface $blocker): bool;

  /**
   * Checks if the $user is blocked by the other participant in this thread.
   *
   * For example, if $user is User1, this checks user2_blocked_user1.
   *
   * @param \Drupal\user\UserInterface $user
   * The user to check if they are blocked.
   *
   * @return bool
   * TRUE if $user is blocked by the other participant, FALSE otherwise.
   */
  public function isUserBlockedByOther(UserInterface $user): bool;

  /**
   * Sets the block status initiated by the $blocker user towards the other participant.
   *
   * If $blocker is User1, this sets user1_blocked_user2.
   * If $blocker is User2, this sets user2_blocked_user1.
   *
   * @param \Drupal\user\UserInterface $blocker
   * The user initiating the block/unblock action.
   * @param bool $status
   * TRUE to block, FALSE to unblock.
   *
   * @return $this
   */
  public function setBlockStatusByUser(UserInterface $blocker, bool $status): self;
}
