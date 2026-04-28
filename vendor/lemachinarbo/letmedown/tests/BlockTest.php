<?php

namespace LetMeDown\Tests;

use LetMeDown\Block;
use LetMeDown\ContentElement;
use LetMeDown\ContentElementCollection;
use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

class BlockTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Ensure the monolithic source file is loaded so sibling classes are declared.
        class_exists(LetMeDown::class);
    }

    private function makeBlock(array $images = [], array $lists = [], array $children = []): Block
    {
        $block = (new \ReflectionClass(Block::class))->newInstanceWithoutConstructor();
        $block->images = new ContentElementCollection($images);
        $block->lists = new ContentElementCollection($lists);
        $block->children = $children;
        return $block;
    }

    /** @testdox Block — getAllLists collects and deduplicates lists across children */
    public function test_get_all_lists_collects_and_deduplicates()
    {
        $list1 = (object)['text' => 'list1'];
        $list2 = (object)['text' => 'list2'];

        $child = $this->makeBlock([], [$list1, $list2]);
        $block = $this->makeBlock([], [$list1], [$child]);

        $this->assertCount(2, $block->getAllLists());
    }

    /** @testdox Block — getAllImages returns empty collection when no images */
    public function test_get_all_images_returns_empty_collection_when_no_images()
    {
        $block = $this->makeBlock();
        $this->assertCount(0, $block->getAllImages());
    }

    /** @testdox Block — getAllImages collects direct images */
    public function test_get_all_images_collects_direct_images()
    {
        $img1 = new ContentElement('text1', 'html1');
        $img2 = new ContentElement('text2', 'html2');
        $block = $this->makeBlock([$img1, $img2]);

        $images = $block->getAllImages();
        $this->assertCount(2, $images);
        $this->assertSame($img1, $images[0]);
        $this->assertSame($img2, $images[1]);
    }

    /** @testdox Block — getAllImages collects from children recursively */
    public function test_get_all_images_collects_from_children_recursively()
    {
        $img1 = new ContentElement('text1', 'html1');
        $img2 = new ContentElement('text2', 'html2');
        $img3 = new ContentElement('text3', 'html3');

        $child2 = $this->makeBlock([$img3]);
        $child1 = $this->makeBlock([$img2], [], [$child2]);
        $parent = $this->makeBlock([$img1], [], [$child1]);

        $images = $parent->getAllImages();
        $this->assertCount(3, $images);
        $this->assertSame($img1, $images[0]);
        $this->assertSame($img2, $images[1]);
        $this->assertSame($img3, $images[2]);
    }

    /** @testdox Block — getAllImages deduplicates the same object instance */
    public function test_get_all_images_deduplicates_same_object()
    {
        $img = new ContentElement('text1', 'html1');

        $child = $this->makeBlock([$img]);
        $parent = $this->makeBlock([$img], [], [$child]);

        $images = $parent->getAllImages();
        $this->assertCount(1, $images, 'Should deduplicate the exact same object instance');
        $this->assertSame($img, $images[0]);
    }

    /** @testdox Block — getAllImages keeps different objects with identical content */
    public function test_get_all_images_keeps_different_objects_with_same_content()
    {
        $img1 = new ContentElement('text1', 'html1');
        $img2 = new ContentElement('text1', 'html1');

        $child = $this->makeBlock([$img2]);
        $parent = $this->makeBlock([$img1], [], [$child]);

        $images = $parent->getAllImages();
        $this->assertCount(2, $images, 'Should not deduplicate different instances even if content is identical');
        $this->assertSame($img1, $images[0]);
        $this->assertSame($img2, $images[1]);
    }
}
