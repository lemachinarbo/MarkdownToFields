<?php

use LetMeDown\LetMeDown;
use LetMeDown\ContentElement;
use LetMeDown\FieldData;
use PHPUnit\Framework\TestCase;

class FieldDataTest extends TestCase
{
    public function test_items_method_returns_cached_collection()
    {
        $field = new FieldData(
            name: 'test',
            markdown: '',
            html: '',
            text: '',
            type: 'list',
            data: [['text' => 'Item 1']]
        );

        $items1 = $field->items();
        $items2 = $field->items();

        $this->assertInstanceOf(\LetMeDown\ContentElementCollection::class, $items1);
        $this->assertSame($items1, $items2);
    }

    public function test_items_method_builds_correct_collection_for_lists()
    {
        $field = new FieldData(
            name: 'test',
            markdown: '',
            html: '',
            text: '',
            type: 'list',
            data: [
                ['text' => 'Item 1', 'html' => '<li>Item 1</li>'],
                ['text' => 'Item 2', 'html' => '<li>Item 2</li>']
            ]
        );

        $items = $field->items();

        $this->assertCount(2, $items);
        $this->assertSame('Item 1', $items[0]->text);
        $this->assertSame('<li>Item 1</li>', $items[0]->html);
        $this->assertSame(['text' => 'Item 1', 'html' => '<li>Item 1</li>'], $items[0]->data);

        $this->assertSame('Item 2', $items[1]->text);
        $this->assertSame('<li>Item 2</li>', $items[1]->html);
    }

    public function test_items_method_builds_correct_collection_for_images()
    {
        $field = new FieldData(
            name: 'test',
            markdown: '',
            html: '',
            text: '',
            type: 'images',
            data: [
                ['src' => 'img1.png', 'alt' => 'Alt 1'],
                ['src' => 'img2.jpg', 'alt' => 'Alt 2']
            ]
        );

        $items = $field->items();

        $this->assertCount(2, $items);
        $this->assertSame('Alt 1', $items[0]->text);
        $this->assertSame('<img src="img1.png" alt="Alt 1">', $items[0]->html);
        $this->assertSame(['src' => 'img1.png', 'alt' => 'Alt 1'], $items[0]->data);
    }

    public function test_items_method_builds_correct_collection_for_links()
    {
        $field = new FieldData(
            name: 'test',
            markdown: '',
            html: '',
            text: '',
            type: 'links',
            data: [
                ['href' => 'https://example.com', 'text' => 'Link 1'],
                ['href' => 'https://example.org', 'text' => 'Link 2']
            ]
        );

        $items = $field->items();

        $this->assertCount(2, $items);
        $this->assertSame('Link 1', $items[0]->text);
        $this->assertSame('<a href="https://example.com">Link 1</a>', $items[0]->html);
        $this->assertSame(['href' => 'https://example.com', 'text' => 'Link 1'], $items[0]->data);
    }

    public function test_items_method_builds_empty_collection_for_scalar()
    {
        $field = new FieldData(
            name: 'test',
            markdown: '',
            html: '',
            text: '',
            type: 'heading',
            data: []
        );

        $items = $field->items();
        $this->assertInstanceOf(\LetMeDown\ContentElementCollection::class, $items);
        $this->assertCount(0, $items);
    }

    public function test_iterable_field_data_can_be_iterated_over()
    {
        $markdown = <<<MD
<!-- section:main -->
<!-- list -->
- Item 1
- Item 2
- Item 3
MD;
        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($markdown);

        $listField = $content->section('main')->field('list');
        $this->assertNotNull($listField);
        $this->assertSame('list', $listField->type);

        $iterations = 0;
        $items = [];

        foreach ($listField as $item) {
            $iterations++;
            $this->assertInstanceOf(ContentElement::class, $item);
            $items[] = trim($item->text);
        }

        $this->assertSame(3, $iterations);
        $this->assertSame(['Item 1', 'Item 2', 'Item 3'], $items);
    }

    public function test_scalar_field_data_iteration_yields_no_items()
    {
        $markdown = <<<MD
<!-- section:main -->
<!-- title -->
# My Title
MD;
        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($markdown);

        $titleField = $content->section('main')->field('title');
        $this->assertNotNull($titleField);
        $this->assertSame('heading', $titleField->type);

        $iterations = 0;

        foreach ($titleField as $item) {
            $iterations++;
        }

        $this->assertSame(0, $iterations);
    }

    public function test_items_method_caches_collection()
    {
        $markdown = <<<MD
<!-- section:main -->
<!-- list -->
- Item 1
MD;
        $parser = new LetMeDown();
        $content = $parser->loadFromString($markdown);
        $listField = $content->section('main')->field('list');

        $items1 = $listField->items();
        $items2 = $listField->items();

        $this->assertSame($items1, $items2);
    }

    public function test_items_collection_for_list()
    {
        $markdown = <<<MD
<!-- section:main -->
<!-- list -->
- First item
- Second item
MD;
        $parser = new LetMeDown();
        $content = $parser->loadFromString($markdown);
        $listField = $content->section('main')->field('list');
        $items = $listField->items();

        $this->assertCount(2, $items);
        $this->assertSame('First item', trim($items[0]->text));
        $this->assertSame('Second item', trim($items[1]->text));
    }

    public function test_items_collection_for_images()
    {
        $markdown = <<<MD
<!-- section:main -->
<!-- images -->
![Alt 1](src1.jpg)
![Alt 2](src2.png)
MD;
        $parser = new LetMeDown();
        $content = $parser->loadFromString($markdown);
        $imagesField = $content->section('main')->field('images');
        $items = $imagesField->items();

        $this->assertCount(2, $items);
        $this->assertSame('Alt 1', trim($items[0]->text));
        $this->assertSame('<img src="src1.jpg" alt="Alt 1">', trim($items[0]->html));
        $this->assertSame('Alt 2', trim($items[1]->text));
        $this->assertSame('<img src="src2.png" alt="Alt 2">', trim($items[1]->html));
    }

    public function test_items_collection_for_links()
    {
        $markdown = <<<MD
<!-- section:main -->
<!-- links -->
[Link 1](href1.com)
[Link 2](href2.com)
MD;
        $parser = new LetMeDown();
        $content = $parser->loadFromString($markdown);
        $linksField = $content->section('main')->field('links');
        $items = $linksField->items();

        $this->assertCount(2, $items);
        $this->assertSame('Link 1', trim($items[0]->text));
        $this->assertSame('<a href="href1.com">Link 1</a>', trim($items[0]->html));
        $this->assertSame('Link 2', trim($items[1]->text));
        $this->assertSame('<a href="href2.com">Link 2</a>', trim($items[1]->html));
    }
}
