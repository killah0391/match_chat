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
   * Gets the timestamp when user 1 last saw messages in this thread.
   *
   * @return int|null
   * The timestamp, or NULL if never recorded.
   */
  public function getUser1LastSeenTimestamp(): ?int;

  /**
   * Sets the timestamp when user 1 last saw messages in this thread.
   *
   * @param int $timestamp
   * The timestamp.
   *
   * @return $this
   */
  public function setUser1LastSeenTimestamp(int $timestamp): self;

  /**
   * Gets the timestamp when user 2 last saw messages in this thread.
   *
   * @return int|null
   * The timestamp, or NULL if never recorded.
   */
  public function getUser2LastSeenTimestamp(): ?int;

  /**
   * Sets the timestamp when user 2 last saw messages in this thread.
   *
   * @param int $timestamp
   * The timestamp.
   *
   * @return $this
   */
  public function setUser2LastSeenTimestamp(int $timestamp): self;

  /**
   * Checks if the given user has blocked the other participant in this thread.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to check (must be a participant in the thread).
   *
   * @return bool
   *   TRUE if the user has blocked the other participant, FALSE otherwise.
   */
  public function hasUserBlockedOther(UserInterface $user): bool;

  /**
   * Checks if the given user is blocked by the other participant in this thread.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to check (must be a participant in the thread).
   *
   * @return bool
   *   TRUE if the user is blocked by the other participant, FALSE otherwise.
   */
  public function isUserBlockedByOther(UserInterface $user): bool;
}
