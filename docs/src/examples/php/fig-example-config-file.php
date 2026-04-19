<?php
$config->MarkdownToFields = [

  // templates
  'enabledTemplates' => ['home', 'about'],

  // fields
  'markdownField' => 'md_markdown',
  'hashField' => 'md_markdown_hash',
  'linkSync' => false,

  // content
  'sourcePath' => 'content/',
  'imageBaseUrl' => $config->urls->files . '{pageId}/',
  'imageSourcePaths' => $config->paths->site . 'images/',

  // frontmatter
  'autoSyncFrontmatter' => true,
  'includeFrontmatterFields' => ['name', 'summary', 'bio'],
  'excludeFrontmatterFields' => ['description'],

  // debug
  'debug' => true,
];
