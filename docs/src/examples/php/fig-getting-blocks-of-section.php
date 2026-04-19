<?php
/**
 * @source: fig-content-with-blocks-and-children.md
 */
namespace ProcessWire;
  $content = $page->content();
  
  $blocks = $content->about->blocks;
  $about = $blocks[0];
  $value = $blocks[1];
?>
