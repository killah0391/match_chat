<?php

namespace Drupal\match_chat\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

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
}
