<?php

namespace ProcessWire;

class MarkdownEditor
{
  public static function pageFromProcess(HookEvent $event): ?Page
  {
    if (!method_exists($event->object, 'getPage')) {
      return null;
    }

    return self::page($event->object->getPage());
  }

  public static function pageFromArgs(HookEvent $event): ?Page
  {
    return self::page($event->arguments(0));
  }

  public static function hashField(Page $page): string
  {
    return MarkdownSyncer::getHashFieldName($page);
  }

  public static function rememberHash(Page $page): void
  {
    $hashes = MarkdownSyncer::languageFileHashes($page);
    MarkdownSyncer::rememberFileHash($page, $hashes);
  }

  protected static function page($page): ?Page
  {
    if (!$page instanceof Page) {
      return null;
    }

    if (!MarkdownSyncer::supportsPage($page)) {
      return null;
    }

    return $page;
  }
}
