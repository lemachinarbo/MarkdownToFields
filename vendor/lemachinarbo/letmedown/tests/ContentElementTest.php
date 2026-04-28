<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use LetMeDown\ContentElement;

class ContentElementTest extends TestCase
{
    /** @testdox ContentElement — __toString returns text property */
    public function test_to_string_returns_text_property()
    {
        $element = new ContentElement(
            text: 'Hello World',
            html: '<p>Hello World</p>',
        );

        $this->assertSame('Hello World', (string) $element);
    }

    /** @testdox ContentElement — __get returns value from data array */
    public function test_get_returns_value_from_data_array()
    {
        $element = new ContentElement(
            text: 'Sample',
            html: '<p>Sample</p>',
            data: [
                'type' => 'link',
                'url' => 'https://example.com'
            ],
        );

        $this->assertSame('link', $element->type);
        $this->assertSame('https://example.com', $element->url);
    }

    /** @testdox ContentElement — __get returns null for missing key */
    public function test_get_returns_null_for_missing_key()
    {
        $element = new ContentElement(
            text: 'Sample',
            html: '<p>Sample</p>',
            data: [
                'type' => 'image',
            ],
        );

        $this->assertNull($element->non_existent_key);
    }
}
