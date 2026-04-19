<?php
/**
 * @source: fig-content-with-lists-images-paragraphs-links.md
 */
namespace ProcessWire;
  $content = $page->content();

  $block = $content->section[0]->blocks[0];
  $images = $block->images;

  $first = $images[0];

  $text = $first->text;
  $html = $first->html;
  $src  = $first->src;
  $alt  = $first->alt;
  $img  = $first->img; 
?>
