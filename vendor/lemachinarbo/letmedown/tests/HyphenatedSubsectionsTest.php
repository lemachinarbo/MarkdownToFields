<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

class HyphenatedSubsectionsTest extends TestCase
{
    public function test_hyphenated_section_names_are_parsed()
    {
        $md = <<<MD
<!-- section:feature-grid -->
### Feature Grid
Some content.
MD;

        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($md);
        $section = $content->section('feature-grid');

        $this->assertNotNull($section);
        $this->assertSame('Feature Grid', trim($section->blocks[0]->heading->text));
    }

    public function test_hyphenated_subsection_names_are_parsed_as_distinct_subsections()
    {
        $md = <<<MD
<!-- section:experiences -->

<!-- sub:culinary -->
### Culinary Experience
Personal sessions with Daniela.

<!-- image -->
![](culinary.jpg)

<!-- sub:language-of-hands -->
### The Language of Hands
A half-day exploration through the visual language of hands.

<!-- image -->
![](hands.jpg)
MD;

        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($md);
        $experiences = $content->section('experiences');

        $this->assertNotNull($experiences);
        $this->assertNotNull($experiences->subsection('culinary'));
        $this->assertNotNull($experiences->subsection('language-of-hands'));

        $culinary = $experiences->subsection('culinary');
        $hands = $experiences->subsection('language-of-hands');

        $this->assertSame('Culinary Experience', trim($culinary->blocks[0]->heading->text));
        $this->assertSame('The Language of Hands', trim($hands->blocks[0]->heading->text));
        $this->assertSame('culinary.jpg', $culinary->field('image')->data['src']);
        $this->assertSame('hands.jpg', $hands->field('image')->data['src']);
    }

    public function test_hyphenated_field_names_are_supported()
    {
        $md = <<<MD
<!-- section:hero -->
<!-- card-title -->
### Hello
MD;

        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $content = $parser->loadFromString($md);
        $hero = $content->section('hero');

        $this->assertNotNull($hero);
        $this->assertNotNull($hero->field('card-title'));
        $this->assertSame('Hello', trim($hero->field('card-title')->text));
    }
}
