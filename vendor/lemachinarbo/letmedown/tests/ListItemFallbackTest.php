<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

/**
 * @testdox List item text fallback for image-only items
 */
class ListItemFallbackTest extends TestCase
{
    public function testImageOnlyListItemFallsBackToHtml()
    {
        $md = "<!-- section:hello -->\n# Hello\n\n<!-- list -->\n- ![foo](foo.jpg)\n- ![bar](bar.jpg)\n";

        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($md);

        $list = $content->hello->blocks[0]->lists[0];

        $this->assertIsArray($list->data['items']);
        $this->assertStringContainsString('<img', $list->data['items'][0]);
        $this->assertStringContainsString('foo.jpg', $list->data['items'][0]);
    }
}
