<?php

namespace ProcessWire;

/**
 * MarkdownContent Trait
 * 
 * Provides markdown loading functionality with sensible defaults.
 * Can be used by any Page class.
 * 
 * ## Folder Structure
 * 
 * **Single-language site:**
 * ```
 * site/content/
 *   home.md
 *   about.md
 * ```
 * 
 * **Multilingual site:**
 * ```
 * site/content/
 *   en/
 *     home.md
 *     about.md
 *   es/
 *     home.md
 *     about.md
 * ```
 * 
 * The syncer automatically detects your site's language setup and resolves paths.
 * 
 * ## Customization
 * 
 * All properties can be overridden in your page class:
 * 
 * ```php
 * class CustomPage extends Page {
 *   use MarkdownContent;
 *   protected string $sourcePath = '/var/markdown/';  // path to markdown files
 *   protected string $sourcePageField = 'md_ref';     // page reference field name
 *   protected string $markdownField = 'md';           // markdown content field
 *   protected string $htmlField = 'html';             // rendered HTML field
 *   protected string $hashField = 'md_hash';          // hash tracking field
 * }
 * ```
 * 
 * Or define per-page source:
 * 
 * ```php
 * class HomePage extends Page {
 *   use MarkdownContent;
 *   
 *   public function getContentSource(): string {
 *     return 'home.md';  // Syncer handles language folders automatically
 *   }
 * }
 * 
 * ## Basic Usage
 * 
 * ```php
 * $content = $page->loadContent();
 * echo $content->hero->text;
 * ```
 */
trait MarkdownContent {
  
  // Defaults (override by setting the property in your class)
  protected string $sourcePath = '';  // empty = site/content/, or set to any path
  protected string $sourcePageField = 'markdown_source';
  protected string $markdownField = 'markdown';
  protected string $htmlField = 'body';
  protected string $hashField = 'markdown_hash';
  
  /**
   * Ensure MarkdownSyncer class is loaded
   */
  protected static function ensureMarkdownSyncer(): void {
    if (class_exists('\\ProcessWire\\MarkdownSyncer', false)) {
      return;
    }
    $config = wire('config');
    $path = $config->paths->siteModules . 'MarkdownToFields/MarkdownSyncer.php';
    if (is_file($path)) {
      require_once $path;
    }
  }

  /**
   * Get markdown sync configuration
   * Override to customize paths or settings
   */
  public function getMarkdownSyncMap(): array {
    $config = $this->wire('config');
    $path = $this->sourcePath ?: $config->paths->site . 'content/';
    
    return [
      'source' => [
        'path' => $path,
        'pageField' => $this->sourcePageField,
        'fallback' => $this->getContentSource(),
      ],
      'markdownField' => $this->markdownField,
      'htmlField' => $this->htmlField,
      'hashField' => $this->hashField,
    ];
  }

  /**
   * Load markdown content for this page
   * Automatically handles language if multilingual
   */
  public function loadContent(?string $source = null, ?string $language = null) {
    self::ensureMarkdownSyncer();
    $source = $source ?? $this->getContentSource();
    $syncerClass = '\\ProcessWire\\MarkdownSyncer';
    $lang = $syncerClass::getLanguageCode($this);
    return $syncerClass::loadMarkdown($this, $source, $lang);
  }

  /**
   * Default markdown source: page name + .md
   * Override in your page class to customize
   */
  public function getContentSource(): string {
    // Sensible default: use page name
    // Override in a Page class if needed.
    return $this->name . '.md';
  }

  /**
   * Load content for this page
   * Convenience wrapper: loadContent($this->getContentSource())
   */
  public function content() {
    return $this->loadContent($this->getContentSource());
  }

  /**
   * Template data boundary - the canonical view contract
   * Default: return loaded content
   * Override in your page class to shape/normalize data for templates
   * 
   * This is the only method templates should call.
   * Subclasses transform raw content into view-ready structure here.
   */
  public function templateData() {
    return $this->content();
  }
}
