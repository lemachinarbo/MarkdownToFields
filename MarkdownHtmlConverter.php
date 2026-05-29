<?php

namespace ProcessWire;

class MarkdownHtmlConverter extends MarkdownFileIO
{
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

  /** Copy referenced images into the page assets folder and rewrite src URLs. */
  public static function processImagesToPageAssets(
    Page $page,
    string $html,
    ?string $languageCode = null,
  ): string
  {
    // Skip processing early when there's no HTML or page id
    if ($html === '' || !$page->id) {
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

    $filesManager = $page->filesManager();
    if (!$filesManager) {
      return $html;
    }

    $destBasePath = $filesManager->path();
    $destBaseUrl = $config['imageBaseUrl'] ?? $filesManager->url();

    if (!$destBasePath || !$destBaseUrl || !$sourcePaths) {
      return $html;
    }

    // Fast-path: if HTML contains no <img> tag, skip processing to avoid
    // noisy per-element calls and logs when there are no images.
    if (stripos($html, '<img') === false) {
      return $html;
    }

    $pattern = '/<img\b[^>]*\bsrc\s*=\s*(["' . "'" . '])\s*([^"' . "'" . '>]+)\s*\1[^>]*>/i';
    
    $matchCount = 0;
    $rewrites = [];
    $hashes = [];
    $collectHashes = $languageCode !== null && trim($languageCode) !== '';
    $result = preg_replace_callback(
      $pattern,
      function (array $match) use ($page, $sourcePaths, $destBasePath, $destBaseUrl, &$matchCount, &$rewrites, &$hashes, $collectHashes) {
        $matchCount++;
        $quote = $match[1];
        $src = trim((string) $match[2]);

        if ($collectHashes) {
          $resolved = self::resolveImageForPage(
            $page,
            $src,
            $sourcePaths,
            $destBasePath,
            $destBaseUrl,
            $hashes,
          );
        } else {
          $resolved = self::resolveImageForPage(
            $page,
            $src,
            $sourcePaths,
            $destBasePath,
            $destBaseUrl,
          );
        }

        if ($resolved === null) {
          return $match[0];
        }

        // Track rewrites and emit one summary line after processing.
        $rewrites[] = ['src' => $src, 'resolved' => $resolved];

        return str_replace($quote . $src . $quote, $quote . $resolved . $quote, $match[0]);
      },
      $html,
    );

    if ($collectHashes && $hashes) {
      self::persistImageHashes($page, $languageCode, $hashes);
    }

    return $result ?? $html;
  }

  /** Copy an image into page assets and return its served URL. */
  protected static function resolveImageForPage(
    Page $page,
    string $src,
    array $sourcePaths,
    string $destBasePath,
    string $destBaseUrl,
    ?array &$hashes = null,
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
          $created = wire('files')?->mkdir($destDir, true);
          if ($created === false || !is_dir($destDir)) {
            throw new WireException(
              sprintf('Unable to create image asset directory at %s', $destDir),
            );
          }
        }

        if (!is_file($destPath) || filemtime($sourceFile) > @filemtime($destPath)) {
          if (!@copy($sourceFile, $destPath) || !is_file($destPath)) {
            throw new WireException(
              sprintf('Unable to copy image asset from %s to %s', $sourceFile, $destPath),
            );
          }
        }

        if (is_array($hashes)) {
          $hash = hash_file('sha256', $sourceFile);
          if (is_string($hash) && $hash !== '') {
            $hashes[$candidate] = $hash;
          }
        }

        try {
          $wrapper = new Pageimages($page);
          $img = new Pageimage($wrapper, $destPath);
          return $img->url();
        } catch (\Throwable) {
          if (!is_file($destPath)) {
            throw new WireException(
              sprintf('Image asset was not materialized at %s', $destPath),
            );
          }

          return $destBaseUrl . ltrim($candidate, '/');
        }
      }
    }

    return null;
  }

  /**
   * Resolve image and copy to page assets for editor insertion.
   * Public wrapper for resolveImageForPage without hash collection.
   * Used by MarkdownToFieldsFrontEditor to ensure images exist in PW assets.
   */
  public static function resolveImageForInsertion(Page $page, string $imagePath): ?string
  {
    if (!$page->id) {
      return null;
    }

    $config = self::config($page) ?? [];
    $sourcePaths = self::normalizeSourcePaths($config['imageSourcePaths'] ?? []);
    
    if (!$sourcePaths) {
      $sitePath = $page->wire('config')->paths->site ?? null;
      if ($sitePath) {
        $sourcePaths[] = self::withTrailingSlash($sitePath . 'images');
      }
    }

    $filesManager = $page->filesManager();
    if (!$filesManager) {
      return null;
    }

    $destBasePath = $filesManager->path();
    $destBaseUrl = $config['imageBaseUrl'] ?? $filesManager->url();

    if (!$destBasePath || !$destBaseUrl || !$sourcePaths) {
      return null;
    }

    return self::resolveImageForPage(
      $page,
      $imagePath,
      $sourcePaths,
      $destBasePath,
      $destBaseUrl
    );
  }

  protected static function persistImageHashes(
    Page $page,
    ?string $languageCode,
    array $hashes,
  ): void {
    $languageCode = $languageCode !== null ? trim($languageCode) : '';
    if ($languageCode === '' || !$hashes) {
      return;
    }

    $filesManager = $page->filesManager();
    if (!$filesManager) {
      return;
    }

    $path = rtrim($filesManager->path(), '/\\') . '/image-hashes.json';
    $existing = [];

    if (is_file($path)) {
      $raw = @file_get_contents($path);
      if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
          $existing = $decoded;
        }
      }
    }

    $lang = self::determineLanguageCode($page, $languageCode);
    $current = $existing[$lang] ?? [];
    if (!is_array($current)) {
      $current = [];
    }

    $existing[$lang] = array_merge($current, $hashes);

    $encoded = json_encode($existing);
    if ($encoded === false) {
      return;
    }

    if (@file_put_contents($path, $encoded) === false) {
      throw new WireException(
        sprintf('Unable to persist image hashes at %s', $path),
      );
    }
  }

  public static function resyncImageHashesForPage(Page $page): int
  {
    if (!$page->id) {
      return 0;
    }

    $filesManager = $page->filesManager();
    if (!$filesManager) {
      return 0;
    }

    $path = rtrim($filesManager->path(), '/\\') . '/image-hashes.json';
    if (!is_file($path)) {
      return 0;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
      return 0;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !$decoded) {
      return 0;
    }

    $config = self::config($page) ?? [];
    $sourcePaths = self::normalizeSourcePaths($config['imageSourcePaths'] ?? []);
    if (!$sourcePaths) {
      $sitePath = $page->wire('config')->paths->site ?? null;
      if ($sitePath) {
        $sourcePaths[] = self::withTrailingSlash($sitePath . 'images');
      }
    }

    if (!$sourcePaths) {
      return 0;
    }

    $destBasePath = self::withTrailingSlash($filesManager->path());
    $updated = 0;
    $changed = false;
    $hashCache = [];

    foreach ($decoded as $lang => $map) {
      if (!is_array($map) || !$map) {
        continue;
      }

      $langChanged = false;
      foreach ($map as $filename => $storedHash) {
        if (!is_string($filename) || $filename === '') {
          continue;
        }

        $sourceFile = self::findImageSourceFile($sourcePaths, $filename);
        if ($sourceFile === null) {
          continue;
        }

        if (!isset($hashCache[$sourceFile])) {
          $hash = hash_file('sha256', $sourceFile);
          if (!is_string($hash) || $hash === '') {
            continue;
          }
          $hashCache[$sourceFile] = $hash;
        }

        $sourceHash = $hashCache[$sourceFile];
        $destPath = $destBasePath . ltrim($filename, '/');
        $destHash = is_file($destPath) ? hash_file('sha256', $destPath) : null;

        if (is_string($destHash) && $destHash === $sourceHash) {
          continue;
        }

        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
          wire('files')?->mkdir($destDir, true);
        }

        @copy($sourceFile, $destPath);

        $map[$filename] = $sourceHash;
        $langChanged = true;
        $updated++;
      }

      if ($langChanged) {
        $decoded[$lang] = $map;
        $changed = true;
      }
    }

    if ($changed) {
      $encoded = json_encode($decoded);
      if ($encoded !== false) {
        @file_put_contents($path, $encoded);
      }
    }

    return $updated;
  }

  protected static function findImageSourceFile(
    array $sourcePaths,
    string $filename,
  ): ?string {
    $relative = ltrim($filename, '/');
    if ($relative === '') {
      return null;
    }

    foreach ($sourcePaths as $sourceBase) {
      $sourceBase = self::withTrailingSlash($sourceBase);
      $candidate = $sourceBase . $relative;
      if (is_file($candidate)) {
        return $candidate;
      }
    }

    return null;
  }
}
