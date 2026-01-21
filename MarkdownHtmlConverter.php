<?php

namespace ProcessWire;

use League\HTMLToMarkdown\HtmlConverter;
use Parsedown;

class MarkdownHtmlConverter extends MarkdownFileIO
{
  protected const COMMENT_PLACEHOLDER_CLASS = 'md-comment-placeholder';
  
  protected static ?Parsedown $parsedown = null;
  protected static ?HtmlConverter $htmlConverter = null;

  protected static function markdownToHtml(
    string $markdown,
    ?Page $page = null,
  ): string {
    if ($markdown === '') {
      return '';
    }
    // Keep original markdown format intact; only adjust for render.
    $renderMarkdown = self::ensureStructuralBreaksForRender($markdown);
    $html = self::parsedown()->text($renderMarkdown);

    if ($page) {
      self::logInfo($page, 'markdownToHtml: about to process images', [
        'htmlLength' => strlen($html),
        'pageId' => $page->id,
      ]);
      $html = self::processImagesToPageAssets($page, $html);
      $config = self::config($page);
      $baseUrl = is_array($config)
        ? $config['imageBaseUrl'] ?? null
        : null;
      if ($baseUrl) {
        $html = self::applyImageBaseUrl($html, $baseUrl);
      }
    }

    return $html;
  }

  protected static function htmlToMarkdown(
    string $html,
    ?Page $page = null,
  ): string {
    if ($html === '') {
      return '';
    }

    if ($page) {
      $config = self::config($page);
      $baseUrl = is_array($config)
        ? $config['imageBaseUrl'] ?? null
        : null;
      if ($baseUrl) {
        $html = self::stripImageBaseUrl($html, $baseUrl);
      }
    }

    $html = self::editorPlaceholdersToComments($html);

    [$prepared, $comments] = self::replaceCommentsForConversion($html);
    [$prepared, $inlineTokens] = self::protectInlineHtml($prepared);

    $markdown = self::htmlConverter()->convert($prepared);
    $markdown = self::restoreCommentsAfterConversion($markdown, $comments);
    $markdown = self::restoreInlineHtml($markdown, $inlineTokens);

    return self::normalizeMarkdownBody($markdown);
  }

  /** Converts HTML comments to visible editor placeholder elements. */
  public static function commentsToEditorPlaceholders(string $html): string
  {
    if ($html === '') {
      return '';
    }

    return preg_replace_callback(
      '/<!--(.*?)-->/s',
      function ($match) {
        $raw = $match[0];
        $label = self::formatCommentLabel((string) $match[1]);
        $encoded = base64_encode($raw);

        $dataAttr = htmlspecialchars(
          $encoded,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8',
        );

        $labelText = htmlspecialchars(
          $label,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8',
        );

        $classes = [self::COMMENT_PLACEHOLDER_CLASS];
        $typeClass = self::commentPlaceholderTypeClass((string) $match[1]);
        if ($typeClass !== '') {
          $classes[] = $typeClass;
        }

        return sprintf(
          '<p class="%s" data-md-comment="%s" data-mce-noneditable="true" contenteditable="false"><span>%s</span></p>',
          implode(' ', $classes),
          $dataAttr,
          $labelText,
        );
      },
      $html,
    );
  }

  /** Converts editor placeholder elements back to HTML comments. */
  public static function editorPlaceholdersToComments(string $html): string
  {
    if ($html === '') {
      return '';
    }

    $pattern =
      '/<(?P<tag>[a-zA-Z0-9]+)[^>]*data-md-comment\s*=\s*(?:"(?P<data_dq>[^"]+)"|\'(?P<data_sq>[^\']+)\'|(?P<data_unquoted>[^\s>]+))[^>]*>.*?<\/(?P=tag)>/is';

    return preg_replace_callback(
      $pattern,
      function (array $match): string {
        $dataValue = $match['data_dq'] ?? '';
        if ($dataValue === '' && !empty($match['data_sq'])) {
          $dataValue = $match['data_sq'];
        }
        if ($dataValue === '' && !empty($match['data_unquoted'])) {
          $dataValue = $match['data_unquoted'];
        }

        $encoded = html_entity_decode(
          $dataValue,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8',
        );

        $raw = base64_decode($encoded, true);
        if ($raw === false) {
          $decodedLabel = trim($encoded);
          if ($decodedLabel === '') {
            return '';
          }

          return "\n<!-- " . $decodedLabel . " -->\n";
        }

        return "\n" . $raw . "\n";
      },
      $html,
    );
  }

  /** Applies comment-to-placeholder conversion to an inputfield's value. */
  public static function applyEditorPlaceholdersToInputfield(
    Inputfield $inputfield,
  ): void {
    if ($inputfield instanceof InputfieldWrapper) {
      foreach ($inputfield as $child) {
        if ($child instanceof Inputfield) {
          self::applyEditorPlaceholdersToInputfield($child);
        }
      }
      return;
    }

    $value = $inputfield->value;
    if (!is_string($value) || $value === '') {
      $attrValue = $inputfield->attr('value');
      $value = is_string($attrValue) ? $attrValue : (string) $attrValue;
    }

    if (!is_string($value) || $value === '') {
      return;
    }

    $prepared = self::commentsToEditorPlaceholders($value);
    if ($prepared === $value) {
      return;
    }

    $inputfield->value = $prepared;
    $inputfield->attr('value', $prepared);
  }

  protected static function replaceCommentsForConversion(string $html): array
  {
    $comments = [];

    $prepared = preg_replace_callback(
      '/<!--.*?-->/s',
      function ($match) use (&$comments) {
        $index = count($comments);
        $comments[$index] = $match[0];
        return "<md-comment data-md-index=\"{$index}\"></md-comment>";
      },
      $html,
    );

    return [$prepared ?? $html, $comments];
  }

  protected static function restoreCommentsAfterConversion(
    string $markdown,
    array $comments,
  ): string {
    if (!$comments) {
      return $markdown;
    }

    return preg_replace_callback(
      '/<md-comment data-md-index="(\d+)"><\/md-comment>/',
      function ($match) use ($comments) {
        $index = (int) ($match[1] ?? 0);
        $comment = $comments[$index] ?? '';
        if ($comment === '') {
          return '';
        }

        // Ensure comments remain on their own lines in markdown output
        // to avoid merging with adjacent content. Use single newlines only.
        $c = trim($comment);
        return "\n" . $c . "\n";
      },
      $markdown,
    );
  }

  protected static function normalizeMarkdownBody(string $markdown): string
  {
    if ($markdown === '') {
      return '';
    }

    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $markdown = str_replace('&nbsp;', "\n\n", $markdown);
    $markdown = str_replace("\xc2\xa0", "\n\n", $markdown);

    $markdown = self::tidyMarkdownSpacing($markdown);

    return trim($markdown, "\n");
  }

  /**
   * Rendering-only normalization: ensure comments are on their own blocks
   * so adjacent headings/lists parse correctly. Does not persist to storage.
   */
  protected static function ensureStructuralBreaksForRender(string $markdown): string
  {
    if ($markdown === '') {
      return '';
    }

    $s = str_replace(["\r\n", "\r"], "\n", $markdown);
    // Place comments on their own blocks for parsedown rendering
    $s = preg_replace('/\s*<!--([\s\S]*?)-->\s*/', "\n\n<!--$1-->\n\n", $s);
    return $s ?? $markdown;
  }

  protected static function tidyMarkdownSpacing(string $markdown): string
  {
    if ($markdown === '') {
      return '';
    }

    $markdown = preg_replace('/^[ \t]+$/m', '', $markdown);
    $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);

    return $markdown;
  }

  /**
   * Normalize a full markdown document (frontmatter + body) for comparisons.
   * This keeps canonical formatting and normalizes whitespace, frontmatter order,
   * and body spacing using existing compose/parse helpers.
   */
  protected static function normalizeDocumentForComparison(
    Page $page,
    string $document,
  ): string {
    if ($document === null || $document === '') {
      return '';
    }

    [$frontRaw, $body] = self::splitDocument($document);
    $bodyNorm = self::normalizeMarkdownBody($body);
    $front = [];
    if ($frontRaw !== '') {
      $front = self::parseFrontmatterRaw($frontRaw);
    }

    if (is_array($front) && $front) {
      ksort($front);
    }

    $result = self::composeDocument($front, $bodyNorm);
    return rtrim($result, "\r\n") . "\n";
  }

  /**
   * Normalize HTML for string comparison to minimize spurious differences
   * caused by whitespace or EOLs. This is intentionally conservative.
   */
  protected static function normalizeHtmlForComparison(string $html): string
  {
    if ($html === null || $html === '') {
      return '';
    }

    $s = str_replace(["\r\n", "\r"], "\n", $html);
    $s = trim($s);
    // Collapse all whitespace runs into a single space to avoid trivial diffs
    $s = preg_replace('/\s+/', ' ', $s);
    return (string) $s;
  }

  protected static function protectInlineHtml(string $html): array
  {
    $tokens = [];
    $index = 0;

    $protected = preg_replace_callback(
      '/<br\s*\/?\s*>/i',
      function (array $match) use (&$tokens, &$index) {
        $tokens[$index] = $match[0];
        $placeholder = sprintf(
          '<md-inline data-md-token="%d"></md-inline>',
          $index,
        );
        $index++;
        return $placeholder;
      },
      $html,
    );

    return [$protected ?? $html, $tokens];
  }

  protected static function restoreInlineHtml(
    string $markdown,
    array $tokens,
  ): string {
    if ($tokens) {
      $markdown =
        preg_replace_callback(
          '/<md-inline data-md-token="(\d+)"><\/md-inline>/',
          function (array $match) use ($tokens) {
            $index = (int) ($match[1] ?? -1);
            return $tokens[$index] ?? '';
          },
          $markdown,
        ) ?? $markdown;
    }

    $decoded = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $markdown = self::escapeLooseAngleBrackets($decoded);

    return $markdown;
  }

  protected static function escapeLooseAngleBrackets(string $markdown): string
  {
    if ($markdown === '') {
      return '';
    }

    $length = strlen($markdown);
    $result = '';
    $index = 0;

    while ($index < $length) {
      $char = $markdown[$index];

      if ($char === '<') {
        $matchedTag = self::matchHtmlTagAt($markdown, $index);

        if ($matchedTag !== null) {
          $result .= $matchedTag['text'];
          $index = $matchedTag['end'] + 1;
          continue;
        }

        $result .= '&lt;';
        $index++;
        continue;
      }

      if ($char === '>') {
        $result .= '&gt;';
        $index++;
        continue;
      }

      $result .= $char;
      $index++;
    }

    return $result;
  }

  protected static function matchHtmlTagAt(string $source, int $offset): ?array
  {
    if ($offset < 0 || $offset >= strlen($source) || $source[$offset] !== '<') {
      return null;
    }

    $substring = substr($source, $offset);
    if ($substring === '') {
      return null;
    }

    $patterns = [
      '/^<!--.*?-->/s',
      '/^<!\[CDATA\[.*?\]\]>/s',
      '/^<!DOCTYPE[^>]*>/i',
      '/^<\?[\s\S]*?\?>/',
      '/^<\s*\/?[A-Za-z][\w:-]*(?:\s+(?:"[^"]*"|\'[^\']*\'|[^\'">]+))*\s*\/?>(?:\s*)/u',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $substring, $match)) {
        return [
          'text' => $match[0],
          'end' => $offset + strlen($match[0]) - 1,
        ];
      }
    }

    return null;
  }

  protected static function commentPlaceholderTypeClass(string $comment): string
  {
    $text = strtolower(trim($comment));
    if ($text === '') {
      return '';
    }

    if ($text === '/' || str_starts_with($text, '/')) {
      return self::COMMENT_PLACEHOLDER_CLASS . '--close';
    }

    $prefix = $text;
    if (strpos($text, ':') !== false) {
      $prefix = substr($text, 0, strpos($text, ':'));
    }

    switch ($prefix) {
      case 'section':
        return self::COMMENT_PLACEHOLDER_CLASS . '--section';
      case 'sub':
      case 'subsection':
        return self::COMMENT_PLACEHOLDER_CLASS . '--sub';
      case 'field':
        return self::COMMENT_PLACEHOLDER_CLASS . '--field';
      default:
        return self::COMMENT_PLACEHOLDER_CLASS . '--field';
    }
  }

  protected static function applyImageBaseUrl(
    string $html,
    string $baseUrl,
  ): string {
    $normalizedBase = self::normalizeUrlBase($baseUrl);
    if (!$normalizedBase) {
      return $html;
    }

    return preg_replace_callback(
      "/(<img\\b[^>]*\\bsrc\\s*=\\s*)(['\"])([^'\"]+)(\\2)/i",
      function (array $match) use ($normalizedBase) {
        $prefix = $match[1];
        $quote = $match[2];
        $src = trim($match[3]);

        if (
          $src === '' ||
          preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $src) ||
          strpos($src, '../') === 0 ||
          strpos($src, $normalizedBase) === 0
        ) {
          return $match[0];
        }

        $relative = preg_replace('#^\./#', '', $src);
        if ($relative === '') {
          return $match[0];
        }

        if (strpos($relative, '/') !== false) {
          return $match[0];
        }

        $resolved = $normalizedBase . ltrim($relative, '/');

        return $prefix . $quote . $resolved . $quote;
      },
      $html,
    ) ?? $html;
  }

  protected static function stripImageBaseUrl(
    string $html,
    string $baseUrl,
  ): string {
    $normalizedBase = self::normalizeUrlBase($baseUrl);
    if (!$normalizedBase) {
      return $html;
    }

    $pattern = sprintf(
      "/(<img\\b[^>]*\\bsrc\\s*=\\s*)(['\"])%s([^'\"]+)(\\2)/i",
      preg_quote($normalizedBase, '/'),
    );

    return preg_replace_callback(
      $pattern,
      function (array $match) {
        $prefix = $match[1];
        $quote = $match[2];
        $relative = $match[3];

        return $prefix . $quote . $relative . $quote;
      },
      $html,
    ) ?? $html;
  }

  protected static function parsedown(): Parsedown
  {
    if (!self::$parsedown) {
      self::$parsedown = new Parsedown();
    }

    return self::$parsedown;
  }

  protected static function htmlConverter(): HtmlConverter
  {
    if (!self::$htmlConverter) {
      self::$htmlConverter = new HtmlConverter([
        'remove_nodes' => 'head script style',
        'header_style' => 'atx',
        'strip_placeholder_tags' => false,
      ]);
    }

    return self::$htmlConverter;
  }

  protected static function formatCommentLabel(string $comment): string
  {
    $label = trim(preg_replace('/\s+/', ' ', $comment));
    if ($label === '') {
      $label = 'comment';
    }

    if (strlen($label) > 48) {
      $label = substr($label, 0, 45) . '…';
    }

    return '[' . $label . ']';
  }

  /** Copy referenced images into the page assets folder and rewrite src URLs. */
  public static function processImagesToPageAssets(Page $page, string $html): string
  {
    self::logInfo($page, 'processImagesToPageAssets: called', [
      'htmlLength' => strlen($html),
      'pageId' => $page->id,
    ]);

    if ($html === '' || !$page->id) {
      self::logInfo($page, 'processImagesToPageAssets: early exit', [
        'emptyHtml' => $html === '',
        'noPageId' => !$page->id,
      ]);
      return $html;
    }

    $config = self::config($page) ?? [];

    $sourcePaths = self::normalizeSourcePaths($config['imageSourcePaths'] ?? []);
    if (!$sourcePaths) {
      $sitePath = $page->wire('config')->paths->site ?? null;
      if ($sitePath) {
        $sourcePaths[] = self::withTrailingSlash($sitePath . 'images');
      }
    }

    self::logInfo($page, 'processImagesToPageAssets: config loaded', [
      'sourcePaths' => implode(', ', $sourcePaths),
      'imageBaseUrl' => $config['imageBaseUrl'] ?? 'none',
    ]);

    $filesManager = $page->filesManager();
    if (!$filesManager) {
      self::logInfo($page, 'processImagesToPageAssets: no filesManager');
      return $html;
    }

    $destBasePath = $filesManager->path();
    $destBaseUrl = $config['imageBaseUrl'] ?? $filesManager->url();

    if (!$destBasePath || !$destBaseUrl || !$sourcePaths) {
      self::logInfo($page, 'processImagesToPageAssets: missing paths', [
        'destBasePath' => $destBasePath ?: 'empty',
        'destBaseUrl' => $destBaseUrl ?: 'empty',
        'hasSourcePaths' => !empty($sourcePaths),
      ]);
      return $html;
    }

    $pattern = '/<img\b[^>]*\bsrc\s*=\s*(["' . "'" . '])\s*([^"' . "'" . '>]+)\s*\1[^>]*>/i';
    
    $matchCount = 0;
    $result = preg_replace_callback(
      $pattern,
      function (array $match) use ($page, $sourcePaths, $destBasePath, $destBaseUrl, &$matchCount) {
        $matchCount++;
        $quote = $match[1];
        $src = trim((string) $match[2]);

        self::logInfo($page, 'processImagesToPageAssets: img match', [
          'matchNum' => $matchCount,
          'src' => $src,
          'quote' => $quote,
        ]);

        $resolved = self::resolveImageForPage(
          $page,
          $src,
          $sourcePaths,
          $destBasePath,
          $destBaseUrl,
        );

        self::logInfo($page, 'processImagesToPageAssets: resolved', [
          'src' => $src,
          'resolved' => $resolved ?? 'null',
        ]);

        if ($resolved === null) {
          return $match[0];
        }

        return str_replace($quote . $src . $quote, $quote . $resolved . $quote, $match[0]);
      },
      $html,
    );

    self::logInfo($page, 'processImagesToPageAssets: complete', [
      'matchCount' => $matchCount,
      'resultLength' => strlen($result ?? $html),
    ]);

    return $result ?? $html;
  }

  /** Copy an image into page assets and return its served URL. */
  protected static function resolveImageForPage(
    Page $page,
    string $src,
    array $sourcePaths,
    string $destBasePath,
    string $destBaseUrl,
  ): ?string {
    $trimmed = trim($src);

    if (
      $trimmed === '' ||
      preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|data:|/)#i', $trimmed) ||
      strpos($trimmed, '..') !== false
    ) {
      return null;
    }

    $relative = ltrim(preg_replace('#^\.?/#', '', $trimmed) ?? $trimmed, '/');
    if ($relative === '') {
      return null;
    }

    $candidates = [$relative];

    $clean = preg_replace(
      '/(\.[0-9]+x[0-9]+(?:-[a-z0-9]+)?)+(?=\.[^.]+$)/i',
      '',
      $relative,
    );
    if ($clean && $clean !== $relative) {
      $candidates[] = $clean;
    }

    $destBasePath = self::withTrailingSlash($destBasePath);
    $destBaseUrl = self::normalizeUrlBase($destBaseUrl) ?? $destBaseUrl;

    foreach ($candidates as $candidate) {
      foreach ($sourcePaths as $sourceBase) {
        $sourceBase = self::withTrailingSlash($sourceBase);
        $sourceFile = $sourceBase . $candidate;

        if (!is_file($sourceFile)) {
          continue;
        }

        $destPath = $destBasePath . $candidate;
        $destDir = dirname($destPath);

        if (!is_dir($destDir)) {
          wire('files')?->mkdir($destDir, true);
        }

        if (!is_file($destPath) || filemtime($sourceFile) > @filemtime($destPath)) {
          @copy($sourceFile, $destPath);
        }

        try {
          $wrapper = new Pageimages($page);
          $img = new Pageimage($wrapper, $destPath);
          return $img->url();
        } catch (\Throwable) {
          return $destBaseUrl . ltrim($candidate, '/');
        }
      }
    }

    return null;
  }
}
