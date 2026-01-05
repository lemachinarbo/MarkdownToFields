<?php

namespace ProcessWire;

class MarkdownEditor
{
  /** Extract page from process object if available. */
  public static function pageFromProcess(HookEvent $event): ?Page
  {
    if (!method_exists($event->object, 'getPage')) {
      return null;
    }

    return self::page($event->object->getPage());
  }

  /** Extract page from event arguments. */
  public static function pageFromArgs(HookEvent $event): ?Page
  {
    return self::page($event->arguments(0));
  }

  /** Get hash field name for the page. */
  public static function hashField(Page $page): string
  {
    return MarkdownHashTracker::getHashFieldName($page);
  }

  /** Store current file hashes in session. */
  public static function rememberHash(Page $page): void
  {
    $hashes = MarkdownHashTracker::languageFileHashes($page);
    MarkdownHashTracker::rememberFileHash($page, $hashes);
  }

  protected static function page($page): ?Page
  {
    if (!$page instanceof Page) {
      return null;
    }

    if (!MarkdownConfig::supportsPage($page)) {
      return null;
    }

    return $page;
  }
}
