<?php

namespace ProcessWire;

class MarkdownDocumentParser extends MarkdownUtilities
{
  protected static function splitDocument(string $document): array
  {
    $document = ltrim($document, "\xEF\xBB\xBF");

    if (strncmp($document, '---', 3) !== 0) {
      return ['', ltrim($document, "\r\n")];
    }

    $length = strlen($document);
    $cursor = 3;
    $frontLines = [];

    while ($cursor < $length) {
      $newlinePos = strpos($document, "\n", $cursor);
      if ($newlinePos === false) {
        $line = substr($document, $cursor);
        $cursor = $length;
      } else {
        $line = substr($document, $cursor, $newlinePos - $cursor);
        $cursor = $newlinePos + 1;
      }

      $normalizedLine = rtrim($line, "\r");

      if (preg_match('/^\s*---\s*$/', $normalizedLine)) {
        $body = substr($document, $cursor);
        $frontRaw = implode("\n", $frontLines);

        return [rtrim($frontRaw, "\r\n"), ltrim((string) $body, "\r\n")];
      }

      $inlineClosingPos = strpos($line, '---');
      if ($inlineClosingPos !== false) {
        $frontPart = substr($line, 0, $inlineClosingPos);
        $frontPartTrimmed = trim($frontPart);
        if ($frontPartTrimmed !== '') {
          $frontLines[] = $frontPartTrimmed;
        }

        $bodyRemainder = substr($line, $inlineClosingPos + 3);
        $body = $bodyRemainder;
        if ($cursor < $length) {
          $body .= substr($document, $cursor);
        }

        $frontRaw = implode("\n", $frontLines);

        return [rtrim($frontRaw, "\r\n"), ltrim((string) $body, "\r\n")];
      }

      $frontLines[] = ltrim($normalizedLine);
    }

    return ['', ltrim($document, "\r\n")];
  }

  protected static function composeDocument(
    array $frontmatter,
    string $body,
  ): string {
    $frontRaw = self::buildFrontmatterRaw($frontmatter);

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

    $lines = preg_split('/\r?\n/', $raw) ?: [];
    $data = [];
    $currentKey = null;

    foreach ($lines as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || self::startsWith($trimmed, '#')) {
        continue;
      }

      if (preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $line, $match)) {
        $key = (string) $match[1];
        $value = (string) $match[2];

        if ($value === '') {
          $data[$key] = '';
          $currentKey = $key;
          continue;
        }

        $data[$key] = self::parseFrontmatterScalar($value);
        $currentKey = $key;
        continue;
      }

      if ($currentKey !== null && preg_match('/^\s*-\s*(.*)$/', $line, $item)) {
        if (!isset($data[$currentKey]) || !is_array($data[$currentKey])) {
          $data[$currentKey] = [];
        }

        $data[$currentKey][] = self::parseFrontmatterScalar((string) $item[1]);
        continue;
      }

      $currentKey = null;
    }

    return $data;
  }

  protected static function buildFrontmatterRaw(array $frontmatter): string
  {
    if (!$frontmatter) {
      return '';
    }

    $lines = [];
    foreach ($frontmatter as $key => $value) {
      $frontKey = (string) $key;
      if ($frontKey === '') {
        continue;
      }

      if (is_array($value)) {
        $lines[] = $frontKey . ':';
        foreach ($value as $item) {
          if (!is_scalar($item) && $item !== null) {
            continue;
          }

          $lines[] = '  - ' . self::stringifyScalar($item);
        }
        continue;
      }

      if (is_scalar($value) || $value === null) {
        $lines[] = $frontKey . ': ' . self::stringifyScalar($value);
        continue;
      }

      $lines[] = $frontKey . ': ' . self::stringifyValue($value);
    }

    return implode("\n", $lines);
  }

  protected static function parseFrontmatterScalar(string $value)
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $lower = strtolower($value);
    if ($lower === 'true' || $lower === 'false') {
      return $lower === 'true';
    }

    if ($lower === 'null') {
      return null;
    }

    if (is_numeric($value)) {
      return strpos($value, '.') !== false ? (float) $value : (int) $value;
    }

    if (
      (self::startsWith($value, '"') && self::endsWith($value, '"')) ||
      (self::startsWith($value, "'") && self::endsWith($value, "'"))
    ) {
      return substr($value, 1, -1);
    }

    return $value;
  }
}
