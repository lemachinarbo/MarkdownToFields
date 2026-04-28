<?php

use LetMeDown\LetMeDown;
use PHPUnit\Framework\TestCase;

class LoadTest extends TestCase
{
    public function test_load_throws_exception_when_file_not_found()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Markdown file not found: non-existent-file.md');

        $parser = new LetMeDown(__DIR__ . '/fixtures');
        $parser->load('non-existent-file.md');
    }

    public function test_load_throws_exception_on_path_traversal()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path traversal detected');

        // We use the current directory as the base path.
        $parser = new LetMeDown(__DIR__ . '/fixtures');
        // We attempt to traverse outside the base path using `../`
        $parser->load(__DIR__ . '/fixtures/../LoadTest.php');
    }
}
