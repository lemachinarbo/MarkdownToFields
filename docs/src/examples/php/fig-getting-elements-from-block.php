<?php
/**
 * @source: fig-content-with-lists-images-paragraphs-links.md
 */
namespace ProcessWire;
  $content = $page->content();

  $block = $content->section[0]->blocks[0];

  $heading = $block->heading;
  $paragraphs = $block->paragraphs;
  $lists = $block->lists;
  $images = $block->images;
  $links = $block->links;
?>
