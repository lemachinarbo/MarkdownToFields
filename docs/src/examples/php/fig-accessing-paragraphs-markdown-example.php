<?php
/**
 * @source: fig-content-with-lists-images-paragraphs-links.md
 */
namespace ProcessWire;
  $content = $page->content();

  $paragraphs = $content->section[0]->blocks[0]->paragraphs;
  $first = $paragraphs[1];

  $text = $first->text;
  $html = $first->html;
?>
