<?php

namespace ProcessWire;

use Symfony\Component\Yaml\Yaml;

class MarkdownDocumentParser extends MarkdownUtilities
{
  protected static function splitDocument(string $document): array
  {
    $document = ltrim($document, "\xEF\xBB\xBF");

    if (!preg_match('/\A---[ \t]*\r?\n/', $document)) {
      return ['', ltrim($document, "\r\n")];
    }

    if (preg_match('/\A---[ \t]*\r?\n(.*?)\r?\n[ \t]*---[ \t]*(?:\r?\n|\z)(.*)\z/s', $document, $match)) {
      return [rtrim($match[1], "\r\n"), ltrim($match[2], "\r\n")];
    }

    return ['', ltrim($document, "\r\n")];
  }

  protected static function composeDocument(
    array $frontmatter,
    string $body,
  ): string {
    $frontRaw = self::buildFrontmatterRaw($frontmatter);

    return self::composeDocumentWithFrontmatterRaw($frontRaw, $body);
  }

  protected static function composeDocumentWithFrontmatterRaw(
    string $frontRaw,
    string $body,
  ): string {
    $frontRaw = rtrim($frontRaw, "\r\n");

    $document = "---\n";
    if ($frontRaw !== '') {
      $document .= $frontRaw . "\n";
    }
    $document .= "---\n";

    $body = ltrim($body, "\r\n");
    if ($body !== '') {
      $document .= "\n" . rtrim($body, "\r\n");
    }

    return rtrim($document, "\r\n") . "\n";
  }

  protected static function parseFrontmatterRaw(?string $raw): array
  {
    if ($raw === null) {
      return [];
    }

    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }

    $parsed = Yaml::parse($raw);
    return is_array($parsed) ? $parsed : [];
  }

  protected static function buildFrontmatterRaw(array $frontmatter): string
  {
    if (!$frontmatter) {
      return '';
    }

    return rtrim(Yaml::dump($frontmatter, 10, 2), "\r\n");
  }
}
