<?php
/**
 * @source: fig-content-with-subsections.md
 */
namespace ProcessWire;
  $content = $page->content();
  
  $jane = $content->columns->left;
  $john = $content->columns->right;
?>
