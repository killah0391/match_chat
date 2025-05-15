<?php

namespace Drupal\match_chat\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface; // Owner is the sender

/**
 * Provides an interface for defining Match Message entities.
 */
interface MatchMessageInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface
{

  /**
   * Gets the Match Message creation timestamp.
   *
   * @return int
   * Creation timestamp of the Match Message.
   */
  public function getCreatedTime();

  /**
   * Sets the Match Message creation timestamp.
   *
   * @param int $timestamp
   * The Match Message creation timestamp.
   *
   * @return \Drupal\match_chat\Entity\MatchMessageInterface
   * The called Match Message entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Gets the message content.
   *
   * @return string
   * The message content.
   */
  public function getMessage();

  /**
   * Sets the message content.
   *
   * @param string $message
   * The message content.
   *
   * @return \Drupal\match_chat\Entity\MatchMessageInterface
   * The called Match Message entity.
   */
  public function setMessage($message);

  /**
   * Gets the parent thread ID.
   *
   * @return int|null
   * The parent MatchThread entity ID.
   */
  public function getThreadId();

  /**
   * Sets the parent thread ID.
   *
   * @param int $thread_id
   * The parent MatchThread entity ID.
   *
   * @return \Drupal\match_chat\Entity\MatchMessageInterface
   * The called Match Message entity.
   */
  public function setThreadId($thread_id);

  /**
   * Gets the chat images.
   *
   * @return \Drupal\file\FileInterface[]
   * An array of file entities.
   */
  public function getChatImages();

  /**
   * Sets the chat images.
   *
   * @param int[] $fids
   * An array of file IDs.
   *
   * @return \Drupal\match_chat\Entity\MatchMessageInterface
   * The called Match Message entity.
   */
  public function setChatImages(array $fids);

}
