<?php
/**
 * @source: fig-content-with-lists-images-paragraphs-links.md
 */
namespace ProcessWire;
  $content = $page->content();

  $block = $content->section[0]->blocks[0];
  $lists = $block->lists;

  $first = $lists[0];

  $text = $first->text;
  $html = $first->html;
  $type = $first->type;
  $items = $first->items;
?>
