<?php
/**
 * @source: fig-content-with-lists-images-paragraphs-links.md
 */
namespace ProcessWire;
  $content = $page->content();

  $block = $content->section[0]->blocks[0];
  $links = $block->links;

  $first = $links[0];

  $text = $first->text;
  $html = $first->html;
  $href = $first->href;
?>
