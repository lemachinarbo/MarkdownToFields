<?php

namespace ProcessWire;

use LetMeDown\ContentData;

// Load dependencies and module classes early so they are available before init hooks run
$__moduleVendor = __DIR__ . '/vendor/autoload.php';
if (is_file($__moduleVendor)) {
  require_once $__moduleVendor;
}
require_once __DIR__ . '/MarkdownContent.php';
require_once __DIR__ . '/MarkdownSyncer.php';
require_once __DIR__ . '/MarkdownEditor.php';
require_once __DIR__ . '/MarkdownSyncHooks.php';

/**
 * MarkdownToFields – Because you have a right to have Markdown files as your content source of truth
 *
 * Parse markdown into a ProcessWire-style content API and sync it bidirectionally
 * with ProcessWire fields.
 *
 * @property array  $templates     Configured templates with markdown sync enabled
 * @property string $markdownField Default field name for markdown content
 * @property string $contentPath   Base path for markdown files
 */

class MarkdownToFields extends WireData implements Module
{
  public static function getModuleInfo()
  {
    return [
      'title' => 'Markdown to fields',
      'version' => '1.0.0',
      'summary' => 'Markdown files as your content source of truth',
      'description' =>
        'Parse markdown into structured content API, sync bidirectionally with fields.',
      'author' => 'Lemachi Narbo',
      'icon' => 'pencil',
      'requires' => 'PHP>=8.0',
      'autoload' => true,
      'singular' => true,
      'href' => '',
    ];
  }

  public function init()
  {
    // Register hooks
    $this->addHook(
      'ProcessPageEdit::buildForm',
      MarkdownSyncHooks::class . '::prepareEditForm',
    );
    $this->addHook(
      'ProcessPageEdit::buildFormContent',
      MarkdownSyncHooks::class . '::appendHashField',
    );
    $this->addHook(
      'Pages::saveReady',
      MarkdownSyncHooks::class . '::handleSaveReady',
    );
    $this->addHookAfter(
      'Modules::refresh',
      MarkdownSyncHooks::class . '::handleModulesRefresh',
    );
  }

  public function install()
  {
    // For later
  }

  public function uninstall()
  {
    // For later
  }

  /**
   * Static shortcut to MarkdownSyncer methods
   */
  public static function sync(Page $page): array
  {
    return MarkdownSyncer::syncFromMarkdown($page);
  }

  public static function load(Page $page, $language = null): ?ContentData
  {
    return MarkdownSyncer::loadMarkdown($page, $language);
  }

  public static function save(Page $page, $language = null): void
  {
    MarkdownSyncer::syncToMarkdown($page, null, null);
  }
}
