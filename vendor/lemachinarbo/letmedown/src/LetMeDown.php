<?php

namespace LetMeDown;

use Parsedown;

/**
 * LetMeDown: Handles loading and parsing Markdown content files
 *
 * Provides flexible parsing for different content structures, with support for
 * extracting headings, paragraphs, images and lists from natural Markdown.
 */
class LetMeDown
{
  private const MARKER_NAME_PATTERN = '[A-Za-z0-9_-]+';
  private Parsedown $parsedown;
  private ?string $basePath;

  public function __construct(?string $basePath = null, bool $allowRawHtml = false)
  {
    $this->basePath = $basePath ? realpath($basePath) : null;
    $this->parsedown = new Parsedown();
    // Treat single newlines as hard line breaks to match editor expectations.
    $this->parsedown->setBreaksEnabled(true);
    // Raw HTML is opt-in and should only be enabled for trusted content sources.
    $this->parsedown->setSafeMode(!$allowRawHtml);
  }

  /**
   * Load and parse a Markdown file with default extraction rules
   *
   * @param string $filePath Full path to the markdown file
   * @return ContentData Standardized content structure with defaults
   */
  public function load(string $filePath): ContentData
  {
    if ($this->basePath !== null) {
      // Allow passing absolute paths if they are inside the base path.
      // Also allow relative paths relative to basePath.
      $potentialPath = $this->basePath . DIRECTORY_SEPARATOR . $filePath;
      $resolvedPath = realpath($potentialPath) ?: realpath($filePath);

      if ($resolvedPath === false || !file_exists($resolvedPath)) {
        throw new \RuntimeException("Markdown file not found: {$filePath}");
      }
      $basePathWithSep = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      $resolvedPathWithSep = rtrim($resolvedPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      if (!str_starts_with($resolvedPathWithSep, $basePathWithSep)) {
        throw new \RuntimeException("Path traversal detected: {$filePath}");
      }
    } else {
      $filePath = basename($filePath);
      $resolvedPath = realpath($filePath);
      if ($resolvedPath === false || !file_exists($resolvedPath)) {
        throw new \RuntimeException("Markdown file not found: {$filePath}");
      }
    }

    $rawMarkdown = file_get_contents($resolvedPath);
    return $this->parseMarkdown($rawMarkdown);
  }

  /**
   * Parse raw markdown string and return ContentData. This allows callers to
   * provide already-processed markdown without relying on filesystem temp
   * files. The behaviour mirrors load(), just accepting raw markdown instead of
   * a file path.
   */
  public function loadFromString(string $rawMarkdown): ContentData
  {
    return $this->parseMarkdown($rawMarkdown);
  }

  /**
   * Internal shared parsing logic for raw markdown content.
   */
  private function parseMarkdown(string $rawMarkdown): ContentData
  {
    $markerName = self::MARKER_NAME_PATTERN;
    $frontmatterInfo = $this->separateFrontmatter($rawMarkdown);
    $markdownBody = $frontmatterInfo['content'];
    $frontmatterRaw = $frontmatterInfo['raw'];
    $frontmatter = $this->parseFrontmatter($frontmatterRaw);

    // Split markdown into sections by named or unnamed section markers
    $sections = [];
    $currentIndex = 0;

    // Match both <!-- section --> (unnamed) and <!-- section:name --> (named)
    preg_match_all(
      '/<!--\s*section(?::(' . $markerName . '))?\s*-->/m',
      $markdownBody,
      $matches,
      PREG_OFFSET_CAPTURE,
    );

    if (empty($matches[0])) {
      // No section markers found, treat entire content as one section
      $sections[] = ['name' => null, 'content' => $markdownBody];
    } else {
      // If there is content before the first section marker, treat it as an unnamed leading section
      $firstMatchPos = $matches[0][0][1] ?? 0;
      if ($firstMatchPos > 0) {
        $leadingContent = substr($markdownBody, 0, $firstMatchPos);
        if ($leadingContent !== '') {
          $sections[] = ['name' => null, 'content' => $leadingContent];
        }
      }

      foreach ($matches[0] as $i => $match) {
        // Get the captured group (section name) - use null if not captured
        $sectionName = !empty($matches[1][$i][0]) ? $matches[1][$i][0] : null;
        $startPos = $match[1] + strlen($match[0]);

        // Find the end position (start of next section or end of file)
        $endPos = isset($matches[0][$i + 1])
          ? $matches[0][$i + 1][1]
          : strlen($markdownBody);

        $content = substr($markdownBody, $startPos, $endPos - $startPos);
        $sections[] = ['name' => $sectionName, 'content' => $content];
      }
    }

    $contentData = $this->extractDefaults($sections);
    $contentData->setMarkdown($markdownBody);
    $contentData->setFrontmatter($frontmatter, $frontmatterRaw);

    return $contentData;
  }

  /**
   * Split markdown into optional YAML frontmatter and body content.
   *
   * @param string $markdown Raw markdown including optional frontmatter
   * @return array{content: string, raw: ?string}
   */
  private function separateFrontmatter(string $markdown): array
  {
    $markdownWithoutBom = preg_replace('/^\xEF\xBB\xBF/', '', $markdown);

    if (!preg_match('/^---\s*\R/s', $markdownWithoutBom)) {
      return [
        'content' => ltrim($markdownWithoutBom, "\r\n"),
        'raw' => null,
      ];
    }

    if (
      !preg_match('/^---\s*\R(.*?)\R---\s*\R?/s', $markdownWithoutBom, $matches)
    ) {
      return [
        'content' => ltrim($markdownWithoutBom, "\r\n"),
        'raw' => null,
      ];
    }

    $frontmatterBlock = $matches[1] ?? '';
    $contentStart = strlen($matches[0] ?? '');
    $body = substr($markdownWithoutBom, $contentStart) ?: '';

    return [
      'content' => ltrim($body, "\r\n"),
      'raw' => rtrim($frontmatterBlock, "\r\n"),
    ];
  }

  /**
   * Parse a YAML frontmatter string when possible.
   *
   * @param string|null $frontmatterRaw Raw frontmatter string without markers
   * @return array|string|null Parsed frontmatter array when possible, otherwise string or null
   */
  private function parseFrontmatter(?string $frontmatterRaw): array|string|null
  {
    if ($frontmatterRaw === null) {
      return null;
    }

    $normalized = trim($frontmatterRaw);

    if ($normalized === '') {
      return [];
    }

    $pairs = $this->extractFrontmatterPairs($frontmatterRaw);

    if ($pairs !== null) {
      return $pairs;
    }

    return $frontmatterRaw;
  }

  /**
   * Parse simple key/value frontmatter pairs using indentation-aware rules.
   *
   * @param string $frontmatter Raw frontmatter source without --- fences
   * @return array<string, array|string>|null Structured pairs or null when the format is unknown
   */
  private function extractFrontmatterPairs(string $frontmatter): ?array
  {
    $lines = preg_split("/(?:\r\n|\r|\n)/", $frontmatter) ?: [];

    $result = [];
    $currentKey = null;
    $buffer = [];

    foreach ($lines as $line) {
      if (preg_match('/^\s*#/', $line)) {
        if ($currentKey !== null) {
          $buffer[] = $line;
        }
        continue;
      }

      if (preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $matches)) {
        if ($currentKey !== null) {
          $result[$currentKey] = $this->finalizeFrontmatterValue($buffer);
        }

        $currentKey = $matches[1];
        $buffer = [];
        if ($matches[2] !== '') {
          $buffer[] = $matches[2];
        }
        continue;
      }

      if ($currentKey === null) {
        return null;
      }

      $buffer[] = $line;
    }

    if ($currentKey !== null) {
      $result[$currentKey] = $this->finalizeFrontmatterValue($buffer);
    }

    return $result;
  }

  /**
   * Collapse buffered lines into a scalar or list value, preserving Markdown semantics.
   *
   * @param array<int, string> $lines Buffer captured for the current key
   * @return array|string Normalised field value
   */
  private function finalizeFrontmatterValue(array $lines): array|string
  {
    if ($lines === []) {
      return '';
    }

    $normalizedLines = $this->dedentFrontmatterLines($lines);

    if ($normalizedLines === []) {
      return '';
    }

    $listValue = $this->parseFrontmatterListValue($normalizedLines);
    if ($listValue !== null) {
      return $listValue;
    }

    $firstLine = $normalizedLines[0];
    if ($firstLine === '|' || $firstLine === '>') {
      array_shift($normalizedLines);
      $normalizedLines = $this->dedentFrontmatterLines($normalizedLines);
    }

    $valueMarkdown = implode("\n", $normalizedLines);
    $html = $this->parsedown->text($valueMarkdown);
    $text = trim(strip_tags($html));

    return $text !== '' ? $text : trim($valueMarkdown);
  }

  /**
   * Remove uniform indentation while keeping intentional blank lines intact.
   *
   * @param array<int, string> $lines Lines to normalise
   * @return array<int, string> Dedented lines
   */
  private function dedentFrontmatterLines(array $lines): array
  {
    while ($lines !== [] && trim($lines[0]) === '') {
      array_shift($lines);
    }

    while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
      array_pop($lines);
    }

    if ($lines === []) {
      return [];
    }

    $indent = null;
    foreach ($lines as $line) {
      if (trim($line) === '') {
        continue;
      }

      preg_match('/^\s*/', $line, $match);
      $length = isset($match[0]) ? strlen($match[0]) : 0;

      if ($indent === null || $length < $indent) {
        $indent = $length;
      }
    }

    if ($indent === null || $indent === 0) {
      return $lines;
    }

    $pattern = '/^\s{0,' . $indent . '}/';
    foreach ($lines as $index => $line) {
      $lines[$index] = preg_replace($pattern, '', $line, 1) ?? $line;
    }

    return $lines;
  }

  /**
   * Parse a block-style list into plain-text items when every line looks like a bullet.
   *
   * @param array<int, string> $lines Dedented lines for the candidate list field
   * @return array<int, string>|null List of items or null when the snippet is not a list
   */
  private function parseFrontmatterListValue(array $lines): ?array
  {
    $items = [];

    foreach ($lines as $line) {
      if (trim($line) === '') {
        continue;
      }

      if (!preg_match('/^[-*+]\s+(.*)$/', $line, $matches)) {
        return null;
      }

      $itemMarkdown = $matches[1];
      $itemHtml = $this->parsedown->text($itemMarkdown);
      $itemText = trim(strip_tags($itemHtml));
      $items[] = $itemText !== '' ? $itemText : trim($itemMarkdown);
    }

    return $items;
  }

  /**
   * Parse field markers within a section's markdown content
   *
   * Extracts content tagged with <!-- fieldname --> or <!-- fieldname... --> markers
   * Regular fields (<!-- fieldname -->) stop at the first blank line
   * Extended fields (<!-- fieldname... -->) bleed until <!-- / --> or next marker
   * Only keeps the FIRST occurrence of each field name to prevent sub-block
   * fields from overwriting top-level fields with the same name.
   *
   * @param string $markdown Section markdown content
   * @return array Associative array of field names to FieldData objects
   */
  private function parseFieldMarkers(string $markdown): array
  {
    $fields = [];
    $seenFieldNames = [];

    // Find all HTML comment markers
    $markers = $this->findAllMarkers($markdown);

    if (empty($markers)) {
      return $fields;
    }

    // Build field ranges using stack-based matching
    $fieldRanges = $this->buildFieldRanges($markers, strlen($markdown));

    // Extract content for each field range
    foreach ($fieldRanges as $range) {
      if (isset($seenFieldNames[$range['name']])) {
        continue; // Skip duplicate field names
      }

      $fieldContent = trim(
        substr($markdown, $range['start'], $range['end'] - $range['start']),
      );

      if (empty($fieldContent)) {
        continue;
      }

      // Bindings (<!--field:name-->) extract atomic value from first emphasized text
      if (!empty($range['is_binding'])) {
        $atomicValue = null;
        if (preg_match('/\*+([^*]+)\*+/', $fieldContent, $match)) {
          $atomicValue = trim($match[1]);
        }
        $fieldHtml = $this->parsedown->text($fieldContent);
        $fieldText = $this->htmlToText($fieldHtml);
        
        $fields[$range['name']] = new FieldData(
          name: $range['name'],
          markdown: $fieldContent,
          html: trim($fieldHtml),
          text: $fieldText,
          type: 'binding',
          data: ['atomicValue' => $atomicValue],
        );
        $seenFieldNames[$range['name']] = true;
        continue;
      }

      // Containers (<!--name...-->) are parsed as structural content with blocks
      if ($range['is_container']) {
        $fieldHtml = $this->parsedown->text($fieldContent);
        $blocks = $this->parseBlocks($fieldHtml, $fieldContent);
        $fieldText = $this->htmlToText($fieldHtml);
        
        $fields[$range['name']] = new FieldContainer(
          name: $range['name'],
          markdown: $fieldContent,
          html: $fieldHtml,
          text: $fieldText,
          blocks: $blocks,
        );
        $seenFieldNames[$range['name']] = true;
        continue;
      }

      // Regular fields: limit to first block and infer type
      $fieldParts = preg_split(
        '/(?:\r\n|\n)\s*(?:\r\n|\n)/',
        $fieldContent,
        2,
      );
      $fieldContent = $fieldParts[0];

      $fieldHtml = $this->parsedown->text($fieldContent);
      $fieldText = trim(strip_tags($fieldHtml));
      $fieldData = $this->extractFieldData(
        $fieldContent,
        $fieldHtml,
        $fieldText,
      );

      $fields[$range['name']] = new FieldData(
        name: $range['name'],
        markdown: $fieldContent,
        html: trim($fieldHtml),
        text: $fieldText,
        type: $fieldData['type'],
        data: $fieldData['data'],
      );

      $seenFieldNames[$range['name']] = true;
    }

    return $fields;
  }

  /**
   * Find all HTML comment markers and classify them
   *
   * Extracts and classifies all <!-- ... --> markers into field openers,
   * field closers, or other markers (section, subsection, etc.)
   *
   * @param string $markdown Markdown content to scan
   * @return array Array of classified markers with position, type, and metadata
   */
  private function findAllMarkers(string $markdown): array
  {
    $markers = [];

    // Find all HTML comments
    preg_match_all(
      '/<!-- (.*?) -->/m',
      $markdown,
      $matches,
      PREG_OFFSET_CAPTURE,
    );

    if (empty($matches[0])) {
      return $markers;
    }

    foreach ($matches[0] as $i => $match) {
      $fullMatch = $match[0];
      $position = $match[1];
      $content = trim($matches[1][$i][0]);

      $markerType = $this->classifyMarker($content);

      if ($markerType !== null) {
        $markers[] = [
          'type' => $markerType['type'],
          'name' => $markerType['name'] ?? null,
          'is_container' => $markerType['is_container'] ?? false,
          'is_binding' => $markerType['is_binding'] ?? false,
          'position' => $position,
          'length' => strlen($fullMatch),
          'index' => $i,
        ];
      }
    }

    return $markers;
  }

  /**
   * Classify a marker's content to determine its type
   *
   * @param string $content The content inside <!-- ... -->
   * @return array|null Array with 'type', and optionally 'name' and 'is_container', or null if not a field marker
   */
  private function classifyMarker(string $content): ?array
  {
    $markerName = self::MARKER_NAME_PATTERN;
    // Field binding: "field:name" - extracts atomic value from emphasized text
    if (preg_match('/^field:(' . $markerName . ')$/', $content, $m)) {
      return [
        'type' => 'field_opener',
        'name' => $m[1],
        'is_binding' => true,
      ];
    }

    // Field opener: "fieldname" or "fieldname..." (container)
    if (preg_match('/^(' . $markerName . ')(\.{3})?$/', $content, $m)) {
      return [
        'type' => 'field_opener',
        'name' => $m[1],
        'is_container' => !empty($m[2]), // ... signals structural intent
      ];
    }

    // Named field closer: "/fieldname"
    if (preg_match('/^\/(' . $markerName . ')$/', $content, $m)) {
      return [
        'type' => 'field_closer',
        'name' => $m[1],
      ];
    }

    // Universal closer: "/"
    if ($content === '/') {
      return [
        'type' => 'universal_closer',
      ];
    }

    // Subsection opener: "sub:name"
    if (preg_match('/^sub:(' . $markerName . ')$/', $content, $m)) {
      return [
        'type' => 'subsection_opener',
        'name' => $m[1],
      ];
    }

    // Subsection closer: "/sub" or "/sub:name"
    if (preg_match('/^\/sub(?::(' . $markerName . '))?$/', $content, $m)) {
      return [
        'type' => 'subsection_closer',
        'name' => $m[1] ?? null,
      ];
    }

    // Section marker: "section" or "section:name"
    if (preg_match('/^section(?::(' . $markerName . '))?$/', $content, $m)) {
      return [
        'type' => 'section',
        'name' => $m[1] ?? null,
      ];
    }

    // Not a recognized marker
    return null;
  }

  /**
   * Build field ranges using stack-based matching of openers and closers
   *
   * @param array $markers Array of classified markers
   * @param int $markdownLength Total length of markdown content
   * @return array Array of field ranges with name, start, end, is_container
   */
  private function buildFieldRanges(array $markers, int $markdownLength): array
  {
    $openStack = [];
    $fieldRanges = [];

    foreach ($markers as $marker) {
      // Handle field openers
      if ($marker['type'] === 'field_opener') {
        $openStack[] = [
          'name' => $marker['name'],
          'start' => $marker['position'] + $marker['length'],
          'is_container' => $marker['is_container'],
          'is_binding' => $marker['is_binding'] ?? false,
          'index' => $marker['index'],
        ];
        continue;
      }

      // Handle named field closers
      if ($marker['type'] === 'field_closer') {
        if (!empty($openStack)) {
          // Find and close the specific field with this name
          for ($i = count($openStack) - 1; $i >= 0; $i--) {
            if ($openStack[$i]['name'] === $marker['name']) {
              $opener = array_splice($openStack, $i, 1)[0];
              $fieldRanges[] = [
                'name' => $opener['name'],
                'start' => $opener['start'],
                'end' => $marker['position'],
                'is_container' => $opener['is_container'],
                'is_binding' => $opener['is_binding'] ?? false,
              ];
              break;
            }
          }
        }
        continue;
      }

      // Handle universal closers
      if ($marker['type'] === 'universal_closer') {
        if (!empty($openStack)) {
          // Close most recent field
          $opener = array_pop($openStack);
          $fieldRanges[] = [
            'name' => $opener['name'],
            'start' => $opener['start'],
            'end' => $marker['position'],
            'is_container' => $opener['is_container'],
            'is_binding' => $opener['is_binding'] ?? false,
          ];
        }
        continue;
      }

      // Ignore other marker types (sections, subsections) - they don't affect field parsing
    }

    // Handle unclosed fields - extend to next field opener or end of content
    while (!empty($openStack)) {
      $opener = array_pop($openStack);
      $nextOpenerPos = null;

      // Find the next field opener after this one
      foreach ($markers as $marker) {
        if (
          $marker['type'] === 'field_opener' &&
          $marker['index'] > $opener['index'] &&
          $marker['position'] > $opener['start']
        ) {
          $nextOpenerPos = $marker['position'];
          break;
        }
      }

      $fieldRanges[] = [
        'name' => $opener['name'],
        'start' => $opener['start'],
        'end' => $nextOpenerPos ?? $markdownLength,
        'is_container' => $opener['is_container'],
        'is_binding' => $opener['is_binding'] ?? false,
      ];
    }

    return $fieldRanges;
  }

  /**
   * Extract structured data from field content based on type detection
   *
   * Uses DOM queries for HTML extraction (consistent with extractBlockContent)
   * Falls back to regex for markdown-only detection
   *
   * @param string $markdown Raw markdown content
   * @param string $html Parsed HTML content
   * @param string $text Plain text content
   * @return array Array with 'type' and 'data' keys
   */
  private function extractFieldData(
    string $markdown,
    string $html,
    string $text,
  ): array {
    // Parse HTML into DOM for consistent extraction
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8"?><root>' . $html . '</root>');
    libxml_use_internal_errors(false);

    $xpath = new \DOMXPath($dom);

    // Detect headings from markdown (no HTML representation)
    if (preg_match('/^#+\s+/m', $markdown)) {
      return [
        'type' => 'heading',
        'data' => [],
      ];
    }

    // Extract lists via DOM (both ul and ol)
    $listNodes = $xpath->query('//ul | //ol');
    if ($listNodes->length > 0) {
      $items = [];
      foreach ($listNodes as $listNode) {
        /** @var \DOMElement $listNode */
        $listItems = $xpath->query('.//li', $listNode);
        foreach ($listItems as $liNode) {
          /** @var \DOMElement $liNode */

          $liHtml = $this->serializeNode($liNode);
          $liText = trim(strip_tags($liHtml));

          $links = [];
          $linkNodes = $xpath->query('.//a[@href]', $liNode);
          foreach ($linkNodes as $linkNode) {
            /** @var \DOMElement $linkNode */
            $links[] = [
              'text' => trim($linkNode->textContent ?? ''),
              'href' => $linkNode->getAttribute('href') ?? '',
            ];
          }

          $images = [];
          $imageNodes = $xpath->query('.//img', $liNode);
          foreach ($imageNodes as $imageNode) {
            /** @var \DOMElement $imageNode */
            $images[] = [
              'src' => $imageNode->getAttribute('src') ?? '',
              'alt' => $imageNode->getAttribute('alt') ?? '',
            ];
          }

          $items[] = [
            'html' => $liHtml,
            'text' => $liText,
            'links' => $links,
            'images' => $images,
          ];
        }
      }
      return [
        'type' => 'list',
        'data' => $items,
      ];
    }

    // Extract images via DOM (more robust than regex)
    $imgNodes = $xpath->query('//img');
    if ($imgNodes->length > 0) {
      $images = [];
      foreach ($imgNodes as $img) {
        /** @var \DOMElement $img */
        $images[] = [
          'src' => $img->getAttribute('src') ?? '',
          'alt' => $img->getAttribute('alt') ?? '',
        ];
      }

      if (count($images) > 1) {
        return [
          'type' => 'images',
          'data' => $images,
        ];
      }
      return [
        'type' => 'image',
        'data' => $images[0] ?? [],
      ];
    }

    // Extract links via DOM (more robust than regex)
    $linkNodes = $xpath->query('//a[@href]');
    if ($linkNodes->length > 0) {
      $links = [];
      foreach ($linkNodes as $link) {
        /** @var \DOMElement $link */
        $links[] = [
          'text' => trim(strip_tags($link->textContent ?? '')),
          'href' => $link->getAttribute('href') ?? '',
        ];
      }

      if (count($links) > 1) {
        return [
          'type' => 'links',
          'data' => $links,
        ];
      }
      return [
        'type' => 'link',
        'data' => $links[0] ?? [],
      ];
    }

    // Default to text
    return [
      'type' => 'text',
      'data' => [],
    ];
  }

  private function parseSectionContent(string $sectionMarkdown): array
  {
    $markerName = self::MARKER_NAME_PATTERN;
    // This will contain the core logic from the original extractDefaults loop
    $fields = $this->parseFieldMarkers($sectionMarkdown);

    // Remove ALL markers: fields, closers, subsections
    // Order matters: match extended fields (with ...) before regular fields
    $sectionMarkdownClean = preg_replace(
      '/<!--\s*(' . $markerName . '(?:\.{3})?|\/sub:' . $markerName . '|\/' . $markerName . '|sub:' . $markerName . '|\/sub|' . $markerName . '|\/)\s*-->/m',
      '',
      $sectionMarkdown,
    );
    $html = $this->parsedown->text($sectionMarkdownClean);

    // ... extract contentHtml and plainText ...
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8"?><root>' . $html . '</root>');
    libxml_use_internal_errors(false);

    $contentHtml = '';
    foreach (
      $dom->getElementsByTagName('root')->item(0)?->childNodes ?? []
      as $node
    ) {
      $contentHtml .= $this->serializeNode($node);
    }
    $plainText = $this->htmlToText($contentHtml);

    $blocks = $this->parseBlocks($contentHtml, $sectionMarkdown);

    return [
      'html' => trim($contentHtml),
      'text' => $plainText,
      'blocks' => $blocks,
      'fields' => $fields,
      'markdown' => $sectionMarkdown,
    ];
  }

  /**
   * Extract default content elements from parsed Markdown sections
   *
   * @param array $sections Array of section data with 'name' and 'content' keys
   * @return ContentData Standardized content structure with defaults
   */
  private function extractDefaults(array $sections): ContentData
  {
    $markerName = self::MARKER_NAME_PATTERN;
    // Ordered, deduplicated list of sections (canonical)
    $sectionsList = [];
    // Named lookup for sections (first occurrence wins)
    $sectionsByName = [];
    foreach ($sections as $section) {
      $sectionMarkdown = $section['content'];
      $sectionName = $section['name'];

      if (empty(trim($sectionMarkdown))) {
        continue;
      }

      $subsectionsData = [];
      $mainSectionMarkdown = $sectionMarkdown;

      // Match subsection openers and closers
      // Note: <!-- / --> is NOT matched here - it's only for fields
      preg_match_all(
        '/<!--\s*(?:sub:(' . $markerName . ')|\/sub(?::(' . $markerName . '))?)\s*-->/m',
        $sectionMarkdown,
        $allMatches,
        PREG_OFFSET_CAPTURE,
      );

      if (!empty($allMatches[0])) {
        $subsectionRanges = [];
        $openStack = [];

        foreach ($allMatches[0] as $i => $match) {
          $fullMatch = $match[0];
          $position = $match[1];

          // Check if it's an opener (sub:name)
          if (!empty($allMatches[1][$i][0])) {
            $subName = $allMatches[1][$i][0];
            $openStack[] = [
              'name' => $subName,
              // position of the opener comment itself
              'opener' => $position,
              // start of subsection content (after the opener)
              'start' => $position + strlen($fullMatch),
              'index' => $i,
            ];
          }
          // Check if it's a closer (/sub or /sub:name)
          // Note: <!-- / --> is NOT handled here, only explicit subsection closers
          elseif (preg_match('/<!--\s*\/sub(?::(' . $markerName . '))?\s*-->/', $fullMatch)) {
            if (!empty($openStack)) {
              // Check if it's <!-- /sub:name --> (named subsection closer)
              if (!empty($allMatches[2][$i][0])) {
                $closerName = $allMatches[2][$i][0];
                // Find and close the specific subsection with this name
                for (
                  $stackIdx = count($openStack) - 1;
                  $stackIdx >= 0;
                  $stackIdx--
                ) {
                  if ($openStack[$stackIdx]['name'] === $closerName) {
                    $opener = array_splice($openStack, $stackIdx, 1)[0];
                    $subsectionRanges[] = [
                      'name' => $opener['name'],
                      'opener' => $opener['opener'],
                      'start' => $opener['start'],
                      'end' => $position,
                    ];
                    break;
                  }
                }
              } else {
                // Explicit closer <!-- /sub --> - close most recent subsection
                $opener = array_pop($openStack);
                $subsectionRanges[] = [
                  'name' => $opener['name'],
                  'opener' => $opener['opener'],
                  'start' => $opener['start'],
                  'end' => $position,
                ];
              }
            }
            // If no opener to close, silently ignore the closer
          }
        }

        // Handle unclosed subsections (extend to next sub or end of section)
        while (!empty($openStack)) {
          $opener = array_pop($openStack);
          $nextOpenerPos = null;

          // Find the next subsection opener after this one
          foreach ($allMatches[0] as $j => $match) {
            if (
              $j > $opener['index'] &&
              !empty($allMatches[1][$j][0]) &&
              $match[1] > $opener['start']
            ) {
              $nextOpenerPos = $match[1];
              break;
            }
          }

          $subsectionRanges[] = [
            'name' => $opener['name'],
            'opener' => $opener['opener'] ?? $opener['start'],
            'start' => $opener['start'],
            'end' => $nextOpenerPos ?? strlen($sectionMarkdown),
          ];
        }

        // Build main section markdown by removing subsection ranges (strict boundaries)
        if (!empty($subsectionRanges)) {
          usort($subsectionRanges, fn($a,$b) => $a['start'] <=> $b['start']);
          $pos = 0;
          $kept = '';
          foreach ($subsectionRanges as $range) {
            // Use the opener position to cut main markdown so the opener comment itself
            // is excluded from the main section (but subsection content still starts
            // at 'start', which is after the opener).
            $cutAt = $range['opener'] ?? $range['start'];
            if ($cutAt > $pos) {
              $kept .= substr($sectionMarkdown, $pos, $cutAt - $pos);
            }
            $pos = $range['end'];
          }
          if ($pos < strlen($sectionMarkdown)) {
            $kept .= substr($sectionMarkdown, $pos);
          }
          $mainSectionMarkdown = $kept;
        }

        // Now extract content for each subsection range
        foreach ($subsectionRanges as $range) {
          $subSectionContent = substr(
              $sectionMarkdown,
              $range['start'],
              $range['end'] - $range['start'],
            );

          $parsedSubContent = $this->parseSectionContent($subSectionContent);

          $subsectionsData[$range['name']] = new Section(
            html: $parsedSubContent['html'],
            text: $parsedSubContent['text'],
            markdown: $parsedSubContent['markdown'],
            blocks: $parsedSubContent['blocks'],
            fields: $parsedSubContent['fields'],
            subsections: [],
          );
        }
      }

      $parsedMainContent = $this->parseSectionContent($mainSectionMarkdown);

      $sectionObj = new Section(
        html: $parsedMainContent['html'],
        text: $parsedMainContent['text'],
        markdown: $parsedMainContent['markdown'],
        blocks: $parsedMainContent['blocks'],
        fields: $parsedMainContent['fields'],
        subsections: $subsectionsData,
      );

      // Append to ordered list
      $sectionsList[] = $sectionObj;

      // Store by name if provided (first occurrence wins)
      if ($sectionName && !isset($sectionsByName[$sectionName])) {
        $sectionsByName[$sectionName] = $sectionObj;
      }
    }

    // sectionsList is already unique and ordered
    $fullHtml = implode("\n", array_map(function($s){ return $s->html; }, $sectionsList));
    $fullText = implode("\n", array_map(function($s){ return $s->text; }, $sectionsList));
    return new ContentData([
      'text' => $fullText,
      'html' => $fullHtml,
      'sections' => $sectionsList,
      'sectionsByName' => $sectionsByName,
    ]);
  }

  /**
   * Parse section HTML into hierarchical blocks using DOM parsing
   *
   * Walks the DOM tree to identify heading levels and builds block structures,
   * extracting images, links, lists, and paragraphs from each block's content.
   *
   * @param string $html HTML content to parse
   * @param string|null $markdown Optional: original markdown for field extraction
   * @return array Array of Block objects organized hierarchically
   */
  private function parseBlocks(string $html, ?string $markdown = null): array
  {
    $headingMarkdownEntries = [];
    $preHeadingMarkdown = '';

    if ($markdown !== null) {
      $headingMatches = [];
      preg_match_all(
        '/^(#{1,6})\s+(.*)$/m',
        $markdown,
        $headingMatches,
        PREG_OFFSET_CAPTURE,
      );

      if (!empty($headingMatches[0])) {
        $firstHeadingPos = $headingMatches[0][0][1];
        $preHeadingMarkdown = rtrim(substr($markdown, 0, $firstHeadingPos));

        foreach ($headingMatches[0] as $idx => $match) {
          $start = $match[1];
          $end = $headingMatches[0][$idx + 1][1] ?? strlen($markdown);
          $blockMarkdown = substr($markdown, $start, $end - $start);

          $headingMarkdownEntries[] = [
            'markdown' => rtrim($blockMarkdown, "\r\n"),
          ];
        }
      } else {
        $preHeadingMarkdown = rtrim($markdown, "\r\n");
      }
    }

    $headingMarkdownQueue = $headingMarkdownEntries;

    // Wrap content in a root element for consistent DOM parsing
    $wrappedHtml = '<root>' . $html . '</root>';

    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8"?>' . $wrappedHtml);
    libxml_use_internal_errors(false);

    $xpath = new \DOMXPath($dom);

    // Find all headings (h1-h6) in document order
    $headingNodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');

    if ($headingNodes->length === 0) {
      // No headings found: create a single block so all section content
      // (including field markers/comments) belongs to exactly one block
      $blocks = [];
      $allContent = $xpath->query('//root/*');

      $contentHtmlString = '';
      $blockData = [
        'html' => '',
        'text' => '',
        'images' => new ContentElementCollection(),
        'links' => new ContentElementCollection(),
        'lists' => new ContentElementCollection(),
        'paragraphs' => new ContentElementCollection(),
      ];

      if ($allContent->length > 0) {
        $allContentArray = $this->nodeListToArray($allContent);

        // Serialize content to string to extract fields
        foreach ($allContentArray as $node) {
          $contentHtmlString .= $this->serializeNode($node);
        }

        $blockData = $this->extractBlockContent($allContentArray, $xpath);
      } else {
        // Fallback: use raw section HTML string for field parsing when no element nodes exist
        $contentHtmlString = $html;
      }

      $blocks[] = [
        'heading' => null, // no heading present in this section
        'level' => null,   // level only meaningful when heading exists
        'content' => $blockData['html'],
        'images' => $blockData['images'],
        'links' => $blockData['links'],
        'lists' => $blockData['lists'],
        'paragraphs' => $blockData['paragraphs'],
        'fields' => $this->parseFieldMarkers($markdown !== null ? rtrim($markdown, "\r\n") : $contentHtmlString),
        'text' => $blockData['text'],
        'html' => $blockData['html'],
        'markdown' => $markdown !== null ? rtrim($markdown, "\r\n") : '',
      ];

      return $this->buildHierarchy($blocks);
    }

    $blocks = [];

    // Convert NodeList to array for easier manipulation
    $headingArray = $this->nodeListToArray($headingNodes);

    // Collect all content before the first heading (orphaned content block)
    $preHeadingContent = [];
    $rootElement = $dom->getElementsByTagName('root')->item(0);
    if ($rootElement) {
      $currentNode = $rootElement->firstChild;
      while (
        $currentNode !== null &&
        !(
          $currentNode->nodeType === XML_ELEMENT_NODE &&
          preg_match('/^h[1-6]$/i', $currentNode->nodeName)
        )
      ) {
        if ($currentNode->nodeType === XML_ELEMENT_NODE) {
          $preHeadingContent[] = $currentNode;
        }
        $currentNode = $currentNode->nextSibling;
      }
    }

    // Check if the content starts with h2+ (no h1 at the start)
    // If so, we'll need to create a synthetic root block to wrap them
    $firstHeadingLevel = (int) substr($headingArray[0]->nodeName, 1);
    $needsSyntheticRoot = $firstHeadingLevel > 1;

    // Store pre-heading content for later use (either as orphan block or synthetic root content)
    $syntheticRootContent = $preHeadingContent;

    // For each heading, collect all nodes until the next heading at same or higher level
    for ($i = 0; $i < count($headingArray); $i++) {
      $currentHeading = $headingArray[$i];
      $currentLevel = (int) substr($currentHeading->nodeName, 1);

      // Collect nodes from after this heading until the next heading
      $contentNodes = [];
      $nextNode = $currentHeading->nextSibling;

      while ($nextNode !== null) {
        // Check if this node is a heading of any level (stop condition)
        if (
          $nextNode->nodeType === XML_ELEMENT_NODE &&
          preg_match('/^h[1-6]$/i', $nextNode->nodeName)
        ) {
          break;
        }

        if ($nextNode->nodeType === XML_ELEMENT_NODE) {
          $contentNodes[] = $nextNode;
        }

        $nextNode = $nextNode->nextSibling;
      }

      // Build the heading HTML using DOM serialization
      $headingHtml = $this->serializeNode($currentHeading);

      // Serialize content nodes to HTML string to extract field markers BEFORE losing comments
      $contentHtmlString = '';
      foreach ($contentNodes as $node) {
        $contentHtmlString .= $this->serializeNode($node);
      }

      $blockMarkdown = '';
      if (!empty($headingMarkdownQueue)) {
        $blockMarkdownEntry = array_shift($headingMarkdownQueue);
        $blockMarkdown = $blockMarkdownEntry['markdown'];
      }

      $normalizedBlockMarkdown =
        $blockMarkdown !== '' ? rtrim($blockMarkdown, "\r\n") : '';

      // Extract field markers from markdown if available, otherwise from HTML
      $blockFields =
        $normalizedBlockMarkdown !== ''
          ? $this->parseFieldMarkers($normalizedBlockMarkdown)
          : $this->parseFieldMarkers($contentHtmlString);

      // Extract content from collected nodes
      $blockData = $this->extractBlockContent($contentNodes, $xpath);

      $blocks[] = [
        'heading' => $headingHtml,
        'level' => $currentLevel,
        'content' => $blockData['html'],
        'images' => $blockData['images'],
        'links' => $blockData['links'],
        'lists' => $blockData['lists'],
        'paragraphs' => $blockData['paragraphs'],
        'fields' => $blockFields,
        'text' => $this->htmlToText($headingHtml . $blockData['html']),
        'html' => $headingHtml . $blockData['html'],
        'markdown' => $normalizedBlockMarkdown,
      ];
    }

    // Create a block for pre-heading content (orphaned content) when first heading is h1
    // Also create it if there are only field markers/comments (captured in markdown)
    if (!$needsSyntheticRoot && (!empty($preHeadingContent) || $preHeadingMarkdown !== '')) {
      $orphanBlockMarkdown = rtrim($preHeadingMarkdown, "\r\n");
      $orphanBlockFields = [];
      $orphanBlockData = [];

      if (!empty($preHeadingContent)) {
        // Serialize content to string to extract fields
        $orphanHtmlString = '';
        foreach ($preHeadingContent as $node) {
          $orphanHtmlString .= $this->serializeNode($node);
        }
        $orphanBlockFields =
          $orphanBlockMarkdown !== ''
            ? $this->parseFieldMarkers($orphanBlockMarkdown)
            : $this->parseFieldMarkers($orphanHtmlString);

        $orphanBlockData = $this->extractBlockContent(
          $preHeadingContent,
          $xpath,
        );
      } elseif ($orphanBlockMarkdown !== '') {
        $orphanBlockFields = $this->parseFieldMarkers($orphanBlockMarkdown);
      }

      // If the pre-heading markdown contains only simple field markers (and no other content),
      // attach those fields to the *first* following block instead of creating an orphan block.
      $shouldAttachLeadingMarkers = false;
      if ($orphanBlockMarkdown !== '' && empty($preHeadingContent) && !empty($blocks)) {
        // Ensure the markdown contains only comments (no other text)
        $withoutComments = preg_replace('/<!--.*?-->/s', '', $orphanBlockMarkdown);
        if (trim($withoutComments) === '') {
          $markers = $this->findAllMarkers($orphanBlockMarkdown);
          if (!empty($markers)) {
            $allFieldOpenersNonContainer = true;
            foreach ($markers as $m) {
              if ($m['type'] !== 'field_opener' || !empty($m['is_container'])) {
                $allFieldOpenersNonContainer = false;
                break;
              }
            }
            if ($allFieldOpenersNonContainer) {
              $shouldAttachLeadingMarkers = true;
            }
          }
        }
      }

      if ($shouldAttachLeadingMarkers) {
        $leadingFields = $this->parseFieldMarkers($orphanBlockMarkdown);
        // Merge into the first block, preserving existing fields (first-occurrence wins)
        foreach ($leadingFields as $name => $fieldData) {
          if (!isset($blocks[0]['fields'][$name])) {
            $blocks[0]['fields'][$name] = $fieldData;
          }
        }
      } else {
        // Prepend orphan block with heading = null (no heading)
        array_unshift($blocks, [
          'heading' => null,
          'level' => null,
          'content' => $orphanBlockData['html'] ?? '',
          'images' =>
            $orphanBlockData['images'] ?? new ContentElementCollection(),
          'links' =>
            $orphanBlockData['links'] ?? new ContentElementCollection(),
          'lists' =>
            $orphanBlockData['lists'] ?? new ContentElementCollection(),
          'paragraphs' =>
            $orphanBlockData['paragraphs'] ?? new ContentElementCollection(),
          'fields' => $orphanBlockFields,
          'text' => $orphanBlockData['text'] ?? '',
          'html' => $orphanBlockData['html'] ?? '',
          'markdown' => $orphanBlockMarkdown,
        ]);
      }
    }

    // If we created a synthetic root, prepend it to the blocks
    // so that all the h2+ blocks become its children
    if ($needsSyntheticRoot && !empty($blocks)) {
      $syntheticBlockData = [];
      $syntheticBlockFields = [];
      $syntheticBlockMarkdown = rtrim($preHeadingMarkdown, "\r\n");
      if (!empty($syntheticRootContent)) {
        // Serialize content to string to extract fields
        $syntheticHtmlString = '';
        foreach ($syntheticRootContent as $node) {
          $syntheticHtmlString .= $this->serializeNode($node);
        }
        $syntheticBlockFields =
          $syntheticBlockMarkdown !== ''
            ? $this->parseFieldMarkers($syntheticBlockMarkdown)
            : $this->parseFieldMarkers($syntheticHtmlString);

        $syntheticBlockData = $this->extractBlockContent(
          $syntheticRootContent,
          $xpath,
        );
      } elseif ($syntheticBlockMarkdown !== '') {
        $syntheticBlockFields = $this->parseFieldMarkers(
          $syntheticBlockMarkdown,
        );
      }

      // Do not arbitrarily shift heading levels. Preserve original heading levels
      // so that an H2 remains level 2 even when wrapped by a synthetic root at level 1.
      // (Previous behavior added +1, which caused H2 to become 3.)

      // Create the synthetic root block at level 1
      array_unshift($blocks, [
        'heading' => '',
        'level' => 1,
        'content' => $syntheticBlockData['html'] ?? '',
        'images' =>
          $syntheticBlockData['images'] ?? new ContentElementCollection(),
        'links' =>
          $syntheticBlockData['links'] ?? new ContentElementCollection(),
        'lists' =>
          $syntheticBlockData['lists'] ?? new ContentElementCollection(),
        'paragraphs' =>
          $syntheticBlockData['paragraphs'] ?? new ContentElementCollection(),
        'fields' => $syntheticBlockFields,
        'text' => $syntheticBlockData['text'] ?? '',
        'html' => $syntheticBlockData['html'] ?? '',
        'markdown' => $syntheticBlockMarkdown,
      ]);
    }

    // Build hierarchical structure
    return $this->buildHierarchy($blocks);
  }

  /**
   * Convert DOMNodeList to array for easier iteration
   *
   * @param \DOMNodeList $nodeList
   * @return array Array of nodes
   */
  private function nodeListToArray(\DOMNodeList $nodeList): array
  {
    $array = [];
    foreach ($nodeList as $node) {
      $array[] = $node;
    }
    return $array;
  }

  /**
   * Extract content from DOM nodes within a block
   *
   * @param array $nodes Array of DOMElement nodes
   * @param \DOMXPath $xpath XPath evaluator for querying
   * @return array Array with 'html', 'text', 'images', 'links', 'lists', 'paragraphs', 'headings' keys
   */
  private function extractBlockContent(array $nodes, \DOMXPath $xpath): array
  {
    $html = '';
    $images = [];
    $links = [];
    $lists = [];
    $paragraphs = [];
    $headings = [];

    // Use associative arrays to track uniqueness where it matters (not for links)
    $seenImages = [];
    $seenLists = [];
    $seenParagraphs = [];

    foreach ($nodes as $node) {
      // Skip heading nodes - they belong to child blocks, not this block's direct content
      if (
        $node->nodeType === XML_ELEMENT_NODE &&
        preg_match('/^h[1-6]$/i', $node->nodeName)
      ) {
        // Still include the heading in HTML for structure, but don't extract its content
        $html .= $this->serializeNode($node);
        continue;
      }

      $html .= $this->serializeNode($node); // Extract images from this node using XPath (deduplicated by src)
      $nodeImages = $xpath->query('.//img', $node);
      foreach ($nodeImages as $imgNode) {
        /** @var \DOMElement $imgNode */
        $key = spl_object_id($imgNode);
        if (!isset($seenImages[$key])) {
          $seenImages[$key] = true;
          $src = $imgNode->getAttribute('src') ?? '';
          $alt = $imgNode->getAttribute('alt') ?? '';
          $images[] = new ContentElement(
            text: "[$alt]",
            html: '<img src="' .
              htmlspecialchars($src) .
              '" alt="' .
              htmlspecialchars($alt) .
              '">',
            data: ['src' => $src, 'alt' => $alt],
          );
        }
      }

      // Extract links from this node using XPath (preserve every occurrence)
      $nodeLinks = $xpath->query('.//a[@href]', $node);
      foreach ($nodeLinks as $linkNode) {
        /** @var \DOMElement $linkNode */
        $href = $linkNode->getAttribute('href') ?? '';
        $linkHtml = $this->serializeNode($linkNode);
        $linkText = trim(strip_tags($linkHtml));

        // Do not deduplicate: every source occurrence becomes an element
        $links[] = new ContentElement(
          text: $linkText,
          html: $linkHtml,
          data: ['href' => $href],
        );
      }

      // Extract lists from this node using XPath
      // Check if node itself is a list, or search within it
      $nodeLists = [];
      if (
        $node->nodeType === XML_ELEMENT_NODE &&
        ($node->nodeName === 'ul' || $node->nodeName === 'ol')
      ) {
        $nodeLists = [$node];
      } else {
        $nodeListsQuery = $xpath->query('.//ul | .//ol', $node);
        $nodeLists = iterator_to_array($nodeListsQuery);
      }

      foreach ($nodeLists as $listNode) {
        /** @var \DOMElement $listNode */
        $listHtml = $this->serializeNode($listNode);
        $listType = $listNode->nodeName;

        // Extract list items
        $items = [];
        $listItems = $xpath->query('.//li', $listNode);
        foreach ($listItems as $liNode) {
          $liHtml = $this->serializeNode($liNode);
          $liText = trim(strip_tags($liHtml));
          // Fallback to raw HTML when stripped text is empty (e.g., image-only list items)
          $items[] = $liText !== '' ? $liText : $liHtml;
        }

        // Deduplicate by HTML content (lists are unique by their full content)
        $listKey = md5($listHtml);
        if (!isset($seenLists[$listKey])) {
          $seenLists[$listKey] = true;
          $lists[] = new ContentElement(
            text: strip_tags($listHtml),
            html: $listHtml,
            data: [
              'type' => $listType,
              'items' => $items,
            ],
          );
        }
      }

      // Extract paragraphs from this node using XPath
      // Check if node itself is a paragraph, or search within it
      $nodeParagraphs = [];
      if ($node->nodeType === XML_ELEMENT_NODE && $node->nodeName === 'p') {
        $nodeParagraphs = [$node];
      } else {
        $nodeParagraphsQuery = $xpath->query('.//p', $node);
        $nodeParagraphs = iterator_to_array($nodeParagraphsQuery);
      }

      foreach ($nodeParagraphs as $pNode) {
        $pHtml = $this->serializeNode($pNode);

        // Deduplicate by HTML content
        $pKey = md5($pHtml);
        if (!isset($seenParagraphs[$pKey])) {
          $seenParagraphs[$pKey] = true;
          $paragraphs[] = new ContentElement(
            text: trim(strip_tags($pHtml)),
            html: $pHtml,
            data: [],
          );
        }
      }

      // NOTE: We do NOT extract headings as content elements here.
      // Headings are structural markers that create blocks/children,
      // not content elements like paragraphs or images.
      // If you need to access a block's heading, use $block->heading
      // If you need all headings in a hierarchy, use $block->allHeadings or $section->headings
    }

    return [
      'html' => $html,
      'text' => $this->htmlToText($html),
      'images' => new ContentElementCollection($images),
      'links' => new ContentElementCollection($links),
      'lists' => new ContentElementCollection($lists),
      'paragraphs' => new ContentElementCollection($paragraphs),
    ];
  }

  /**
   * Serialize a DOM node to HTML string
   *
   * Note: DOMDocument::saveHTML() normalizes HTML entities. For example:
   * - Smart quotes become &quot; and &apos;
   * - Ampersands become &amp;
   * - Special characters get numeric entity encoding
   *
   * This normalization is generally beneficial for rendering consistency
   * and security, but should be considered when comparing serialized output
   * to raw HTML strings. It does NOT affect content meaning or display, only
   * representation.
   *
   * @param \DOMNode $node The node to serialize
   * @return string HTML representation of the node with normalized entities
   */
  private function serializeNode(\DOMNode $node): string
  {
    $dom = new \DOMDocument();
    $dom->appendChild($dom->importNode($node, true));
    $html = $dom->saveHTML();

    // For text nodes, return the text content directly
    if ($node->nodeType === XML_TEXT_NODE) {
      return $node->textContent;
    }

    // For element nodes (like <p>, <ul>, etc.), preserve the full tag including attributes
    // Remove only the XML declaration wrapper, keep the element and its content
    $html = preg_replace('/^<\?xml[^?]*?\?>/', '', $html);
    $html = preg_replace('/<root>|<\/root>/', '', $html);

    return trim($html);
  }

  /**
   * Build hierarchical block structure based on heading levels
   */
  private function buildHierarchy(array $blocks): array
  {
    if (empty($blocks)) {
      return [];
    }

    $root = [];
    $stack = []; // Stack to track parent blocks

    foreach ($blocks as $blockData) {
      // Create Block object without children first
      $block = new Block(
        heading: $blockData['heading'],
        level: $blockData['level'],
        content: $blockData['content'],
        html: $blockData['html'],
        text: $blockData['text'],
        markdown: $blockData['markdown'],
        paragraphs: $blockData['paragraphs'],
        images: $blockData['images'],
        links: $blockData['links'],
        lists: $blockData['lists'],
        children: [],
        fields: $blockData['fields'],
      );

      // Find the appropriate parent
      // Blocks with null level are always top-level (no heading = no hierarchy)
      while (!empty($stack) && $block->level !== null && end($stack)->level !== null && end($stack)->level >= $block->level) {
        array_pop($stack);
      }

      if (empty($stack)) {
        // Top-level block
        $root[] = $block;
      } else {
        // Child block - add to parent
        $parent = end($stack);
        $parent->children[] = $block;
      }

      // Push current block onto stack
      $stack[] = $block;
    }

    // Update html and text for all blocks to include children
    $this->updateBlockHtml($root);

    return $root;
  }

  /**
   * Recursively update block html and text to include all children
   */
  private function updateBlockHtml(array $blocks): void
  {
    foreach ($blocks as $block) {
      if (!empty($block->children)) {
        // First, update children recursively
        $this->updateBlockHtml($block->children);

        // Collect all children HTML
        $childrenHtml = '';
        $childrenText = '';
        $childrenMarkdown = '';
        foreach ($block->children as $child) {
          $childrenHtml .= $child->html;
          $childrenText .= "\n" . $child->text;
          $childMarkdown = $child->getMarkdown();
          if ($childMarkdown !== '') {
            $childrenMarkdown .= "\n\n" . $childMarkdown;
          }
        }

        // Update this block's html and text to include children's aggregated content
        // Skip updating html/text for synthetic root blocks (level 1, empty heading) and orphan blocks (level = null)
        if (!($block->level === null || ($block->level === 1 && $block->heading !== null && empty($block->heading->text)))) {
          $block->html .= $childrenHtml;
          $block->text .= $childrenText;
          if ($childrenMarkdown !== '') {
            $combinedMarkdown = $block->markdown . $childrenMarkdown;
            $combinedMarkdown = ltrim($combinedMarkdown, "\r\n");
            $combinedMarkdown = rtrim($combinedMarkdown);
            $block->markdown = $combinedMarkdown;
          }
        }
      }
    }
  }

  /**
   * Convert HTML to readable text with proper line breaks between block elements
   */
  private function htmlToText(string $html): string
  {
    // Add line breaks between block elements for better text readability
    $htmlForText = preg_replace(
      '~</(p|h[1-6]|ul|ol|blockquote)>~i',
      "$0\n\n",
      $html,
    );
    $htmlForText = preg_replace('~</li>~i', "$0\n", $htmlForText ?? '');
    $plainText = trim(strip_tags($htmlForText ?? ''));
    // Normalize excessive line breaks to max 2 consecutive
    $plainText = preg_replace('~\n{3,}~', "\n\n", $plainText);
    return $plainText;
  }
}

/**
 * ContentData: Data container for parsed Markdown content
 *
 * Provides both array and object access to extracted content elements
 */
class ContentData
{
  public string $text;
  public string $html;
  /**
   * Ordered, deduplicated list of sections (numeric keys)
   * Use $content->section(0) or $content->section[0] to access by index.
   */
  public array $sections;
  /**
   * Map of section name => Section for O(1) lookup (first occurrence wins)
   * Accessible via $content->section('name') or via magic property $content->name
   */
  public array $sectionsByName;
  /**
   * Convenience numeric accessor (alias of sections) to allow $content->section[0]
   */
  public array $section;
  public string $markdown;
  public ?string $key = null;
  protected array|string|null $frontmatter;
  protected ?string $frontmatterRaw;

  public function __construct(array $data = [])
  {
    $this->text = $data['text'] ?? '';
    $this->html = $data['html'] ?? '';

    // Expect sections to be a numeric, deduplicated list
    $this->sections = $data['sections'] ?? [];

    // Named lookup map (first occurrence wins)
    $this->sectionsByName = $data['sectionsByName'] ?? [];

    // Convenience alias for numeric access: $content->section[0]
    $this->section = $this->sections;

    $this->markdown = $data['markdown'] ?? '';
    $this->frontmatter = $data['frontmatter'] ?? null;
    $this->frontmatterRaw = $data['frontmatterRaw'] ?? null;
  }

  public function __get($name)
  {
    // Magic property access: named sections first
    if (isset($this->sectionsByName[$name])) {
      return $this->withNodeIdentity($this->sectionsByName[$name], (string) $name);
    }

    return match ($name) {
      'headings' => $this->getHeadings(),
      'blocks' => $this->getBlocks(),
      'images' => $this->getImages(),
      'links' => $this->getLinks(),
      'lists' => $this->getLists(),
      'paragraphs' => $this->getParagraphs(),
      'frontmatter' => $this->getFrontmatter(),
      'frontmatterRaw' => $this->getFrontmatterRaw(),
      'rawDocument' => $this->getRawDocument(),
      default => null,
    };
  }

  public function setMarkdown(string $markdown): void
  {
    $this->markdown = $markdown;
  }

  public function getMarkdown(): string
  {
    return $this->markdown;
  }

  public function data(): array
  {
    return PlainDataProjector::content($this);
  }

  public function setFrontmatter(
    array|string|null $frontmatter,
    ?string $raw = null,
  ): void {
    $this->frontmatter = $frontmatter;
    $this->frontmatterRaw = $raw;
  }

  public function getFrontmatter(): array|string|null
  {
    return $this->frontmatter;
  }

  public function getFrontmatterRaw(): ?string
  {
    return $this->frontmatterRaw;
  }

  public function getRawDocument(): string
  {
    if ($this->frontmatterRaw === null) {
      return $this->markdown;
    }

    $frontmatterContent = rtrim($this->frontmatterRaw, "\r\n");
    $frontmatterBlock =
      $frontmatterContent === ''
        ? "---\n---\n"
        : "---\n{$frontmatterContent}\n---\n";

    $body = ltrim($this->markdown, "\r\n");

    if ($body === '') {
      return $frontmatterBlock;
    }

    return $frontmatterBlock . "\n" . $body;
  }

  public function section(string|int $name): ?Section
  {
    // Allow numeric index or named lookup
    if (is_int($name) || (is_string($name) && ctype_digit($name))) {
      $idx = (int) $name;
      return $this->sections[$idx] ?? null;
    }

    $section = $this->sectionsByName[$name] ?? null;
    return $section ? $this->withNodeIdentity($section, (string) $name) : null;
  }

  private function withNodeIdentity(object $node, string $key): object
  {
    if (property_exists($node, 'key')) {
      $node->key = $key;
    }
    return $node;
  }

  /**
   * Get a deduplicated list of sections.
   * Sections can be present multiple times in the sections array (by numeric index and by name).
   * This method returns an array of unique section objects.
   */
  private function getUniqueSections(): array
  {
    // Sections are stored as an ordered, deduplicated list already.
    return $this->sections;
  }

  // Helper methods for flattened content
  private function getHeadings(): array
  {
    $headings = [];
    $seen = [];

    foreach ($this->getUniqueSections() as $section) {
      foreach ($section->blocks as $block) {
        $this->collectHeadingsFromBlock($block, $headings, $seen);
      }
    }
    return $headings;
  }

  private function getBlocks(): array
  {
    $blocks = [];
    foreach ($this->getUniqueSections() as $section) {
      foreach ($section->blocks as $block) {
        $blocks[] = $block;
      }
    }
    return $blocks;
  }

  private function getImages(): ContentElementCollection
  {
    $images = [];
    foreach ($this->getUniqueSections() as $section) {
      $items = $section->images->getArrayCopy();
      foreach ($items as $item) {
        $images[] = $item;
      }
    }
    return new ContentElementCollection($images);
  }

  private function getLinks(): ContentElementCollection
  {
    $links = [];
    foreach ($this->getUniqueSections() as $section) {
      $items = $section->links->getArrayCopy();
      foreach ($items as $item) {
        $links[] = $item;
      }
    }
    return new ContentElementCollection($links);
  }

  private function getLists(): ContentElementCollection
  {
    $lists = [];
    foreach ($this->getUniqueSections() as $section) {
      $items = $section->lists->getArrayCopy();
      foreach ($items as $item) {
        $lists[] = $item;
      }
    }
    return new ContentElementCollection($lists);
  }

  private function getParagraphs(): ContentElementCollection
  {
    $paragraphs = [];
    foreach ($this->getUniqueSections() as $section) {
      $items = $section->paragraphs->getArrayCopy();
      foreach ($items as $item) {
        $paragraphs[] = $item;
      }
    }
    return new ContentElementCollection($paragraphs);
  }

  private function collectHeadingsFromBlock(
    Block $block,
    array &$headings,
    array &$seen,
  ): void {
    // Add the block's own heading (avoid duplicates)
    if ($block->heading && $block->heading->text !== '') {
      $key = $block->heading->text . '|' . $block->level;
      if (!isset($seen[$key])) {
        $seen[$key] = true;
        $headings[] = new ContentElement(
          text: $block->heading->text,
          html: $block->heading->html,
          data: ['level' => $block->level],
        );
      }
    }

    // Recursively collect from children
    foreach ($block->children as $child) {
      $this->collectHeadingsFromBlock($child, $headings, $seen);
    }
  }

  private function collectHeadingsFromBlockChildrenOnly(
    Block $block,
    array &$headings,
    array &$seen,
  ): void {
    // Only collect from children, skip the block's own heading
    foreach ($block->children as $child) {
      $this->collectHeadingsFromBlock($child, $headings, $seen);
    }
  }
}

/**
 * ContentElement: Simple container for content with text/html access
 */
#[\AllowDynamicProperties]
class ContentElement
{
  public function __construct(
    public string $text,
    public string $html,
    public array $data = [],
  ) {}

  public function __toString(): string
  {
    return $this->text; // Default to text when used as string
  }

  public function __get($name)
  {
    return $this->data[$name] ?? null;
  }
}

/**
 * HeadingElement: Container for heading with both HTML and text extraction
 *
 * Allows access to heading in multiple forms:
 * - (string) cast or ->html: Returns full HTML like "<h3>foo</h3>"
 * - ->text: Returns extracted text like "foo"
 * - ->innerHtml: Returns inner HTML like "foo"
 */
class HeadingElement
{
  public readonly string $text;
  public readonly string $innerHtml;
  public string $html;

  public function __construct(string $heading)
  {
    $this->html = $heading;
    // Extract text by stripping HTML tags and decoding entities
    $this->text = html_entity_decode(strip_tags($heading));
    // Extract inner HTML by removing outer tags
    preg_match('/^<[^>]+>(.*)<\/[^>]+>$/s', $heading, $matches);
    $this->innerHtml = $matches[1] ?? '';
  }

  public function __toString(): string
  {
    return $this->html; // Default to HTML for backward compatibility
  }
}

/**
 * Block: Container for content between headings
 */
class Block
{
  public HeadingElement|string|null $heading;

  public function __construct(
    HeadingElement|string|null $heading,
    public int|null $level,
    public string $content,
    public string $html,
    public string $text,
    public string $markdown,
    public ContentElementCollection $paragraphs,
    public ContentElementCollection $images,
    public ContentElementCollection $links,
    public ContentElementCollection $lists,
    public array $children = [],
    public array $fields = [],
  ) {
    // Ensure heading is a HeadingElement or null
    if (is_string($heading)) {
      $this->heading = new HeadingElement($heading);
    } else {
      $this->heading = $heading; // Can be HeadingElement or null
    }
  }

  public function __get($name)
  {
    // 1. Check for a field with the given name
    if (isset($this->fields[$name])) {
      return $this->fields[$name];
    }

    // 2. Fall back to generic content collections
    return match ($name) {
      'headings' => $this->getAllHeadings(),
      'allHeadings' => $this->getAllHeadings(),
      'allImages' => $this->getAllImages(),
      'allLinks' => $this->getAllLinks(),
      'allLists' => $this->getAllLists(),
      'allParagraphs' => $this->getAllParagraphs(),
      default => null,
    };
  }

  public function getMarkdown(): string
  {
    return $this->markdown;
  }

  public function getFrontmatter(): array|string|null
  {
    return null;
  }

  /**
   * Get a field by name
   *
   * @param string $name Field name
   * @return FieldData|null Field data or null if not found
   */
  public function field(string $name): ?FieldData
  {
    return $this->fields[$name] ?? null;
  }

  private function getAllHeadings(): array
  {
    $headings = [];
    $seen = [];

    // Add our own heading if we have one
    if ($this->heading && $this->heading->text !== '') {
      $key = $this->heading->text . '|' . $this->level;
      if (!isset($seen[$key])) {
        $seen[$key] = true;
        $headings[] = new ContentElement(
          text: $this->heading->text,
          html: $this->heading->html,
          data: ['level' => $this->level],
        );
      }
    }

    // Recursively collect from children
    foreach ($this->children as $child) {
      $this->collectHeadingsFromChildren($child, $headings, $seen);
    }

    return $headings;
  }

  private function collectHeadingsFromChildren(
    Block $block,
    array &$headings,
    array &$seen,
  ): void {
    // Add the block's own heading (avoid duplicates)
    if ($block->heading && $block->heading->text !== '') {
      $key = $block->heading->text . '|' . $block->level;
      if (!isset($seen[$key])) {
        $seen[$key] = true;
        $headings[] = new ContentElement(
          text: $block->heading->text,
          html: $block->heading->html,
          data: ['level' => $block->level],
        );
      }
    }

    // Recursively collect from children
    foreach ($block->children as $child) {
      $this->collectHeadingsFromChildren($child, $headings, $seen);
    }
  }

  public function getAllImages(): ContentElementCollection
  {
    $images = [];
    $seen = [];

    // Add direct images from this block
    foreach ($this->images as $img) {
      $key = spl_object_id($img); // Use object identity to avoid deduplicating user's intentional duplicates
      if (!isset($seen[$key])) {
        $seen[$key] = true;
        $images[] = $img;
      }
    }

    // Recursively collect from children
    foreach ($this->children as $child) {
      foreach ($child->getAllImages() as $img) {
        $key = spl_object_id($img);
        if (!isset($seen[$key])) {
          $seen[$key] = true;
          $images[] = $img;
        }
      }
    }

    return new ContentElementCollection($images);
  }

  public function getAllLinks(): ContentElementCollection
  {
    $links = [];

    // Add direct links from this block (preserve order, no dedup)
    foreach ($this->links as $link) {
      $links[] = $link;
    }

    // Recursively collect from children in document order
    foreach ($this->children as $child) {
      foreach ($child->getAllLinks() as $link) {
        $links[] = $link;
      }
    }

    return new ContentElementCollection($links);
  }

  public function getAllLists(): ContentElementCollection
  {
    $lists = [];
    $seen = [];

    // Add direct lists from this block
    foreach ($this->lists as $list) {
      $key = spl_object_id($list);
      if (!isset($seen[$key])) {
        $seen[$key] = true;
        $lists[] = $list;
      }
    }

    // Recursively collect from children
    foreach ($this->children as $child) {
      foreach ($child->getAllLists() as $list) {
        $key = spl_object_id($list);
        if (!isset($seen[$key])) {
          $seen[$key] = true;
          $lists[] = $list;
        }
      }
    }

    return new ContentElementCollection($lists);
  }

  public function getAllParagraphs(): ContentElementCollection
  {
    $paragraphs = [];
    $seen = [];

    // Add direct paragraphs from this block
    foreach ($this->paragraphs as $para) {
      $key = spl_object_id($para);
      if (!isset($seen[$key])) {
        $seen[$key] = true;
        $paragraphs[] = $para;
      }
    }

    // Recursively collect from children
    foreach ($this->children as $child) {
      foreach ($child->getAllParagraphs() as $para) {
        $key = spl_object_id($para);
        if (!isset($seen[$key])) {
          $seen[$key] = true;
          $paragraphs[] = $para;
        }
      }
    }

    return new ContentElementCollection($paragraphs);
  }
}

/**
 * HasBlockCollections: Shared collection extraction for classes with a $blocks array
 */
trait HasBlockCollections
{
  private function getHeadings(): array
  {
    $headings = [];
    $seen = [];

    foreach ($this->blocks as $block) {
      $this->collectHeadingsFromBlock($block, $headings, $seen);
    }

    return $headings;
  }

  private function collectHeadingsFromBlock(
    Block $block,
    array &$headings,
    array &$seen,
  ): void {
    if ($block->heading && $block->heading->text !== '') {
      $key = $block->heading->text . '|' . $block->level;
      if (!isset($seen[$key])) {
        $seen[$key] = true;
        $headings[] = new ContentElement(
          text: $block->heading->text,
          html: $block->heading->html,
          data: ['level' => $block->level],
        );
      }
    }

    foreach ($block->children as $child) {
      $this->collectHeadingsFromBlock($child, $headings, $seen);
    }
  }

  private function getImages(): ContentElementCollection
  {
    $images = [];
    foreach ($this->blocks as $block) {
      $items = $block->getAllImages()->getArrayCopy();
      foreach ($items as $item) {
        $images[] = $item;
      }
    }
    return new ContentElementCollection($images);
  }

  private function getLinks(): ContentElementCollection
  {
    $links = [];
    foreach ($this->blocks as $block) {
      $items = $block->getAllLinks()->getArrayCopy();
      foreach ($items as $item) {
        $links[] = $item;
      }
    }
    return new ContentElementCollection($links);
  }

  private function getLists(): ContentElementCollection
  {
    $lists = [];
    foreach ($this->blocks as $block) {
      $items = $block->getAllLists()->getArrayCopy();
      foreach ($items as $item) {
        $lists[] = $item;
      }
    }
    return new ContentElementCollection($lists);
  }

  private function getParagraphs(): ContentElementCollection
  {
    $paragraphs = [];
    foreach ($this->blocks as $block) {
      $items = $block->getAllParagraphs()->getArrayCopy();
      foreach ($items as $item) {
        $paragraphs[] = $item;
      }
    }
    return new ContentElementCollection($paragraphs);
  }
}

/**
 * Section: Container for markdown sections
 */
class Section
{
  use HasBlockCollections;
  public function __construct(
    public string $html,
    public string $text,
    public string $markdown,
    protected array $blocks,
    public array $fields = [],
    public array $subsections = [],
    public ?string $key = null,
  ) {}

  public function __get($name)
  {
    // 1. Check for a subsection with the given name
    if (isset($this->subsections[$name])) {
      return $this->withChildIdentity($this->subsections[$name], (string) $name);
    }

    // 2. Check for a field with the given name
    if (isset($this->fields[$name])) {
      return $this->withChildIdentity($this->fields[$name], (string) $name);
    }

    // 3. Fall back to generic content properties
    return match ($name) {
      'headings' => $this->getHeadings(),
      'images' => $this->getImages(),
      'links' => $this->getLinks(),
      'lists' => $this->getLists(),
      'paragraphs' => $this->getParagraphs(),
      'blocks'
        => $this->getRealBlocks(), // Use the new method to get real blocks
      default => null,
    };
  }

  public function subsection(string $name): ?self
  {
    $subsection = $this->subsections[$name] ?? null;
    return $subsection ? $this->withChildIdentity($subsection, $name) : null;
  }

  public function getMarkdown(): string
  {
    return $this->markdown;
  }

  public function data(): array
  {
    return PlainDataProjector::section($this);
  }

  /**

     * Get a field by name

     *

     * @param string $name Field name

     * @return FieldData|FieldContainer|null Field data, a FieldContainer, or null if not found

     */

  public function field(string $name): FieldData|FieldContainer|null
  {
    $field = $this->fields[$name] ?? null;
    return $field ? $this->withChildIdentity($field, $name) : null;
  }

  private function withChildIdentity(object $node, string $name): object
  {
    if (property_exists($node, 'key')) {
      $node->key = $name;
    }
    return $node;
  }


  public function getRealBlocks(): array
  {
    // Synthetic root: level 1 with empty heading element (created for h2+ start)
    // Not to be confused with null-level blocks (no heading at all)
    if (
      !empty($this->blocks) &&
      $this->blocks[0]->level === 1 &&
      ($this->blocks[0]->heading instanceof HeadingElement) &&
      $this->blocks[0]->heading->text === ''
    ) {
      return $this->blocks[0]->children;
    }

    return $this->blocks;
  }

  /**
   * Return a read-only projection of this section's blocks with named
   * subsections merged into the main block hierarchy.
   *
   * This helper is strictly a projection and does not change parsing or the
   * canonical section structure. It does not recompute or mutate any HTML,
   * text, or markdown values. Subsection blocks are appended (in order) as
   * children of the first top-level block. If there are no blocks or no
   * subsections, the original blocks are returned unchanged.
   *
   * @return Block[] Projection of blocks with subsection blocks merged as children
   */
  public function blocksWithSubsections(): array
  {
    $originalBlocks = $this->getRealBlocks();

    // If nothing to project, return canonical blocks unchanged
    if (empty($originalBlocks) || empty($this->subsections)) {
      return $originalBlocks;
    }

    // Clone top-level blocks to avoid mutating originals
    $clonedBlocks = [];
    foreach ($originalBlocks as $b) {
      $clonedBlocks[] = $this->cloneBlockForProjection($b);
    }

    // Append cloned subsection blocks (preserve order) as children of the first top-level cloned block
    foreach ($this->subsections as $sub) {
      foreach ($sub->getRealBlocks() as $sb) {
        $clonedBlocks[0]->children[] = $this->cloneBlockForProjection($sb);
      }
    }

    return $clonedBlocks;
  }

  /**
   * Clone a Block for projection use (recursively clones children). This
   * helper clones Block objects only to avoid mutating originals; it does not
   * touch or recompute HTML/text/markdown values.
   */
  private function cloneBlockForProjection(Block $block): Block
  {
    $clonedChildren = [];
    foreach ($block->children as $child) {
      $clonedChildren[] = $this->cloneBlockForProjection($child);
    }

    return new Block(
      heading: $block->heading,
      level: $block->level,
      content: $block->content,
      html: $block->html,
      text: $block->text,
      markdown: $block->markdown,
      paragraphs: $block->paragraphs,
      images: $block->images,
      links: $block->links,
      lists: $block->lists,
      children: $clonedChildren,
      fields: $block->fields,
    );
  }
}

/**

   * FieldData: Container for field-tagged content within sections

   */
#[\AllowDynamicProperties]
class FieldData implements \IteratorAggregate
{
  private ?ContentElementCollection $itemsCache = null;
  public string $innerHtml;

  public function __construct(
    public string $name,
    public string $markdown,
    public string $html,
    public string $text,
    public string $type,
    public array $data = [],
    public ?string $key = null,
  ) {
    // Extract inner HTML by removing outer tags when possible
    preg_match('/^<[^>]+>(.*)<\/[^>]+>$/s', $this->html, $matches);
    $this->innerHtml = $matches[1] ?? $this->html;
  }

  public function __toString(): string
  {
    return $this->text;
  }

  public function getMarkdown(): string
  {
    return $this->markdown;
  }

  public function data()
  {
    return PlainDataProjector::fieldData($this);
  }

  public function getFrontmatter(): array|string|null
  {
    return null;
  }

  public function __get($key)
  {
    if ($key === 'items') {
      if ($this->itemsCache === null) {
        $this->itemsCache = $this->buildItemsCollection();
      }
      return $this->itemsCache;
    }

    // For single item fields, allow direct property access
    if (in_array($this->type, ['image', 'link'])) {
      return $this->data[$key] ?? null;
    }

    return $this->data[$key] ?? null;
  }

  /**
   * Get items as an iterable collection
   * 
   * Delegates to ->items. Enables: foreach ($field as $item)
   * @return \Traversable
   */
  public function getIterator(): \Traversable
  {
    return $this->items;
  }

  /**
   * Get all items in this field as a ContentElementCollection
   * 
   * @return ContentElementCollection Always safe to call, never null.
   *                                   Returns empty collection for non-iterable fields (scalar types).
   *                                   Lazy-loads on first access and caches the result.
   * 
   * Invariant: This method always returns the same type, regardless of field type.
   *            The caller never needs to null-check or type-guard.
   */
  public function items(): ContentElementCollection
  {
    if ($this->itemsCache === null) {
      $this->itemsCache = $this->buildItemsCollection();
    }
    return $this->itemsCache;
  }

  /**
   * Build a ContentElementCollection of items from raw data
   * 
   * Internal method used by items() getter.
   * Never returns null. Returns empty collection for scalar/non-iterable types.
   * 
   * @return ContentElementCollection Always populated or empty, never null
   */
  private function buildItemsCollection(): ContentElementCollection
  {
    $collection = new ContentElementCollection();

    if ($this->type === 'list') {
      foreach ($this->data as $item) {
        $collection[] = new ContentElement(
          text: $item['text'] ?? '',
          html: $item['html'] ?? '',
          data: $item,
        );
      }
    } elseif ($this->type === 'images') {
      foreach ($this->data as $img) {
        $collection[] = new ContentElement(
          text: $img['alt'] ?? '',
          html: '<img src="' . htmlspecialchars($img['src']) . '" alt="' . htmlspecialchars($img['alt'] ?? '') . '">',
          data: $img,
        );
      }
    } elseif ($this->type === 'links') {
      foreach ($this->data as $link) {
        $href = $link['href'] ?? '';

        // Normalize and decode encoded schemes before checking allowed protocols.
        $normalizedHref = html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalizedHref = rawurldecode($normalizedHref);

        // Strip control characters and whitespace that browsers may ignore before the scheme.
        $cleanHref = trim(preg_replace('/[\x00-\x20]/', '', $normalizedHref));
        $scheme = parse_url($cleanHref, PHP_URL_SCHEME);

        if ($scheme !== null) {
            $scheme = strtolower($scheme);
            if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'])) {
                $href = '#';
            }
        }

        $collection[] = new ContentElement(
          text: $link['text'] ?? '',
          html: '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($link['text'] ?? '') . '</a>',
          data: $link,
        );
      }
    }

    return $collection;
  }
}

/**
 * FieldContainer: Structural field that participates in document hierarchy
 * 
 * Represents open fields (<!-- field... -->) that can contain any content
 * including headings, blocks, and mixed elements. Unlike FieldData, this
 * does not infer a type—it's purely structural.
 */
class FieldContainer
{
  use HasBlockCollections;
  public function __construct(
    public string $name,
    public string $markdown,
    public string $html,
    public string $text,
    protected array $blocks,
    public ?string $key = null,
  ) {}

  public function __get($name)
  {
    if ($name === 'fields') {
      return $this->fields();
    }

    $field = $this->field($name);
    if ($field !== null) {
      return $field;
    }

    return match ($name) {
      'blocks' => $this->blocks,
      'images' => $this->getImages(),
      'links' => $this->getLinks(),
      'lists' => $this->getLists(),
      'paragraphs' => $this->getParagraphs(),
      'headings' => $this->getHeadings(),
      default => null,
    };
  }

  public function getMarkdown(): string
  {
    return $this->markdown;
  }

  public function field(string $name): FieldData|FieldContainer|null
  {
    $fields = $this->fields();
    $field = $fields[$name] ?? null;
    return $field ? $this->withChildIdentity($field, $name) : null;
  }

  public function fields(): array
  {
    $fields = [];
    foreach ($this->blocks as $block) {
      $this->collectFieldsFromBlock($block, $fields);
    }
    return $fields;
  }

  public function data(): array
  {
    return PlainDataProjector::fieldContainer($this);
  }

  private function withChildIdentity(object $node, string $name): object
  {
    if (property_exists($node, 'key')) {
      $node->key = $name;
    }
    return $node;
  }

  private function collectFieldsFromBlock(Block $block, array &$fields): void
  {
    foreach ($block->fields as $name => $field) {
      if (!isset($fields[$name])) {
        $fields[$name] = $this->withChildIdentity($field, (string) $name);
      }
    }

    foreach ($block->children as $child) {
      $this->collectFieldsFromBlock($child, $fields);
    }
  }
}

/**
 * ContentElementCollection: A collection of ContentElement objects
 *
 * Allows accessing the combined HTML or text of all elements in the collection.
 */
class ContentElementCollection extends \ArrayObject
{
  public function __get($name)
  {
    if ($name === 'html') {
      $html = '';
      foreach ($this as $element) {
        $html .= $element->html;
      }
      return $html;
    }
    if ($name === 'text') {
      $text = '';
      foreach ($this as $element) {
        $text .= $element->text . "\n\n";
      }
      return trim($text);
    }
    return null;
  }

  public function __toString(): string
  {
    return $this->text;
  }
}

class PlainDataProjector
{
  public static function content(ContentData $content): array
  {
    $data = [];

    foreach ($content->sectionsByName as $name => $section) {
      $section->key = (string) $name;
      $data[(string) $name] = self::section($section, (string) $name);
    }

    return $data;
  }

  public static function section(Section $section, ?string $key = null): array
  {
    $resolvedKey = $key ?? $section->key ?? '';
    $data = [
      'key' => (string) $resolvedKey,
      'subsections' => [],
    ];

    foreach (['html', 'text', 'markdown'] as $property) {
      if (self::hasMeaningfulString($section->{$property})) {
        $data[$property] = $section->{$property};
      }
    }

    foreach ($section->fields as $name => $field) {
      if (property_exists($field, 'key')) {
        $field->key = (string) $name;
      }
      $data[$name] = self::projectField($field);
    }

    foreach ($section->subsections as $name => $subsection) {
      $subsection->key = (string) $name;
      $child = self::section($subsection, (string) $name);
      $data[$name] = $child;
      $data['subsections'][(string) $name] = $child;
    }

    return $data;
  }

  public static function fieldData(FieldData $field): array
  {
    $data = [
      'type' => self::fieldDataType($field),
      'key' => (string) ($field->key ?? ''),
    ];

    foreach (['html', 'text', 'markdown'] as $property) {
      if (self::hasMeaningfulString($field->{$property})) {
        $data[$property] = $field->{$property};
      }
    }

    if (self::isLinkField($field)) {
      return self::linkData($field, $data);
    }

    if (self::isImageField($field)) {
      return self::imageData($field, $data);
    }

    if (self::isStructuredField($field)) {
      return self::structuredFieldData($field, $data);
    }

    foreach ($field->data as $name => $value) {
      if (is_scalar($value) && $value !== '' && !array_key_exists((string) $name, $data)) {
        $data[(string) $name] = $value;
      }
    }

    return $data;
  }

  private static function fieldDataType(FieldData $field): string
  {
    if (in_array($field->type, ['image', 'images', 'link', 'links', 'list', 'binding'], true)) {
      return $field->type;
    }

    return (string) ($field->key ?? $field->name);
  }

  public static function fieldContainer(FieldContainer $field): array
  {
    $data = [
      'key' => (string) ($field->key ?? ''),
      'items' => [],
    ];

    foreach (['html', 'text', 'markdown'] as $property) {
      if (self::hasMeaningfulString($field->{$property})) {
        $data[$property] = $field->{$property};
      }
    }

    foreach ($field->fields() as $name => $childField) {
      if (property_exists($childField, 'key')) {
        $childField->key = (string) $name;
      }
      $data[(string) $name] = self::projectField($childField);
    }

    foreach ($field->blocks as $block) {
      $data['items'][] = self::blockData($block);
    }

    return $data;
  }

  private static function projectField($field)
  {
    if ($field instanceof FieldData) {
      return self::fieldData($field);
    }

    if ($field instanceof FieldContainer) {
      return self::fieldContainer($field);
    }

    return $field;
  }

  private static function isStructuredField(FieldData $field): bool
  {
    if ($field->data === []) {
      return false;
    }

    if (in_array($field->type, ['list', 'images', 'links'], true)) {
      return true;
    }

    $first = array_values($field->data)[0] ?? null;
    return is_array($first);
  }

  private static function isLinkField(FieldData $field): bool
  {
    return $field->type === 'link' || isset($field->data['href']);
  }

  private static function isImageField(FieldData $field): bool
  {
    return $field->type === 'image' || isset($field->data['src']);
  }

  private static function structuredFieldData(FieldData $field, array $data): array
  {
    $data['items'] = [];

    foreach (array_values($field->data) as $item) {
      if ($field->type === 'images') {
        $data['items'][] = self::imageDataArray($item);
        continue;
      }

      if ($field->type === 'links') {
        $data['items'][] = self::linkDataArray($item);
        continue;
      }

      $data['items'][] = self::fieldItemData($item);
    }

    return $data;
  }

  private static function fieldItemData(array $item): array
  {
    $data = [];

    foreach (['html', 'text', 'markdown'] as $property) {
      if (
        isset($item[$property]) &&
        is_string($item[$property]) &&
        self::hasMeaningfulString($item[$property])
      ) {
        $data[$property] = $item[$property];
      }
    }

    if (!empty($item['images']) && is_array($item['images'])) {
      $data['images'] = array_map(
        fn(array $image): array => self::imageDataArray($image),
        array_values($item['images'])
      );
    }

    if (!empty($item['links']) && is_array($item['links'])) {
      $data['links'] = array_map(
        fn(array $link): array => self::linkDataArray($link),
        array_values($item['links'])
      );
    }

    return $data;
  }

  private static function linkData(FieldData $field, array $base): array
  {
    $data = array_merge($base, self::linkDataArray($field->data + [
      'text' => $field->text,
      'html' => $field->html,
    ]));
    return $data;
  }

  private static function imageData(FieldData $field, array $base): array
  {
    $data = array_merge($base, self::imageDataArray($field->data + [
      'html' => $field->html,
    ]));
    return $data;
  }

  private static function blockData(Block $block): array
  {
    $data = [];

    foreach (['html', 'text', 'markdown'] as $property) {
      if (self::hasMeaningfulString($block->{$property})) {
        $data[$property] = $block->{$property};
      }
    }

    if ($block->heading instanceof HeadingElement && $block->heading->text !== '') {
      $data['heading'] = array_filter([
        'html' => $block->heading->html,
        'text' => $block->heading->text,
        'level' => $block->level,
      ], static fn($value) => $value !== '' && $value !== null);
    }

    return $data;
  }

  private static function linkDataArray(array $data): array
  {
    $out = [];
    foreach (['href', 'text', 'html', 'markdown'] as $property) {
      if (
        isset($data[$property]) &&
        is_string($data[$property]) &&
        self::hasMeaningfulString($data[$property])
      ) {
        $out[$property] = $data[$property];
      }
    }
    return $out;
  }

  private static function imageDataArray(array $data): array
  {
    $out = [];
    foreach (['src', 'alt', 'html', 'text', 'markdown'] as $property) {
      if (
        isset($data[$property]) &&
        is_string($data[$property]) &&
        self::hasMeaningfulString($data[$property])
      ) {
        $out[$property] = $data[$property];
      }
    }
    return $out;
  }

  private static function collectionToArray($collection): array
  {
    if (!$collection instanceof \Traversable && !is_array($collection)) {
      return [];
    }

    $items = [];
    foreach ($collection as $item) {
      if ($item instanceof ContentElement) {
        $items[] = array_filter(array_merge($item->data, [
          'html' => $item->html,
          'text' => $item->text,
        ]), static function ($value): bool {
          if (is_string($value)) {
            return trim($value) !== '';
          }

          return $value !== '';
        });
        continue;
      }
      $items[] = $item;
    }

    return $items;
  }

  private static function hasMeaningfulString(string $value): bool
  {
    return trim($value) !== '';
  }
}
