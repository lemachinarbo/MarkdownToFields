<?php

namespace LetMeDown\Tests;

use PHPUnit\Framework\TestCase;
use LetMeDown\ContentElement;
use LetMeDown\ContentElementCollection;

class ContentElementCollectionTest extends TestCase
{
    public function testStringConversion()
    {
        $elements = [
            new ContentElement('text1', '<p>text1</p>'),
            new ContentElement('text2', '<p>text2</p>'),
        ];

        $collection = new ContentElementCollection($elements);

        $this->assertEquals("text1\n\ntext2", (string) $collection);
    }

    public function testStringConversionWithEmptyCollection()
    {
        $collection = new ContentElementCollection();
        $this->assertEquals('', (string) $collection);
    }

    public function testStringConversionWithEmptyElements()
    {
        $elements = [
            new ContentElement('', ''),
            new ContentElement('text2', '<p>text2</p>'),
        ];

        $collection = new ContentElementCollection($elements);

        $this->assertEquals('text2', (string) $collection);
    }

    public function testHtmlGetter()
    {
        $elements = [
            new ContentElement('text1', '<p>text1</p>'),
            new ContentElement('text2', '<p>text2</p>'),
        ];

        $collection = new ContentElementCollection($elements);

        $this->assertEquals('<p>text1</p><p>text2</p>', $collection->html);
    }

    public function testHtmlGetterWithEmptyCollection()
    {
        $collection = new ContentElementCollection();
        $this->assertEquals('', $collection->html);
    }

    public function testHtmlGetterWithEmptyElements()
    {
        $elements = [
            new ContentElement('', ''),
            new ContentElement('text2', '<p>text2</p>'),
        ];

        $collection = new ContentElementCollection($elements);

        $this->assertEquals('<p>text2</p>', $collection->html);
    }

    public function testTextGetter()
    {
        $elements = [
            new ContentElement('text1', '<p>text1</p>'),
            new ContentElement('text2', '<p>text2</p>'),
        ];

        $collection = new ContentElementCollection($elements);

        $this->assertEquals("text1\n\ntext2", $collection->text);
    }

    public function testTextGetterWithEmptyCollection()
    {
        $collection = new ContentElementCollection();
        $this->assertEquals('', $collection->text);
    }

    public function testTextGetterWithEmptyElements()
    {
        $elements = [
            new ContentElement('', ''),
            new ContentElement('text2', '<p>text2</p>'),
        ];

        $collection = new ContentElementCollection($elements);

        $this->assertEquals('text2', $collection->text);
    }

    public function testUnknownGetter()
    {
        $collection = new ContentElementCollection();
        $this->assertNull($collection->unknown);
    }
}
