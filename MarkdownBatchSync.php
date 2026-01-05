<?php

namespace ProcessWire;

/**
 * MarkdownBatchSync - Batch operations with locking and TTL
 *
 * This class handles batch synchronization of multiple pages with
 * proper locking (APCu and file-based) and TTL-based deduplication.
 */
class MarkdownBatchSync extends MarkdownSyncEngine
{
  /**
   * Synchronizes managed pages from markdown files with optional locking and TTL.
   */
  public static function syncAllManagedPages(
    int $limit = 10000,
    ?string $hashFieldName = null,
    ?string $logChannel = null,
    bool $persist = true,
    int $ttlSeconds = 0,
    bool $useLock = true,
  ): int {
    $pages = wire('pages')->find("limit={$limit}");
    $processed = 0;
    $updated = 0;
    $updatedPages = [];
    $logChannel = $logChannel ?? 'migrate-markdown';
    $log = wire('log');

    $cacheKey = 'markdown-sync-last-run';
    $apcuKey = 'markdown-sync-lock';

    try {
      $log->save($logChannel, 'Starting markdown sync migration');
    } catch (\Throwable $_e) {
      // Logging is best-effort; ignore errors
    }

    // If a global hash field name is supplied but doesn't exist in fields, disable persistence.
    if ($hashFieldName !== null) {
      try {
        $fieldsModule = wire('fields');
        if (!$fieldsModule->get($hashFieldName)) {
          $persist = false;
          try {
            self::logDebug(null, 'hash field missing; skipping persistence', [
              'field' => $hashFieldName,
            ]);
          } catch (\Throwable $_e) {
            // ignore
          }
        }
      } catch (\Throwable $_e) {
        // ignore
      }
    }

    // If TTL is set, skip if we recently ran (quick pre-check)
    if ($ttlSeconds > 0) {
      try {
        $last = wire('cache')->get($cacheKey) ?? 0;
        if (time() - (int) $last < $ttlSeconds) {
          try {
            self::logDebug(
              null,
              'Skipping markdown sync: recent run within TTL',
            );
          } catch (\Throwable $_e) {
          }
          return 0;
        }
      } catch (\Throwable $_e) {
      }
    }

    // Acquire a lock if requested. Prefer APCu, fallback to file lock.
    $lockFp = null;
    $gotLock = false;
    if ($useLock) {
      try {
        if (function_exists('apcu_add')) {
          @$gotLock = (bool) \call_user_func(
            'apcu_add',
            $apcuKey,
            1,
            max(30, $ttlSeconds),
          );
        }
      } catch (\Throwable $_e) {
        $gotLock = false;
      }

      if (!$gotLock) {
        try {
          $lockFile = wire('config')->paths->cache . 'markdown-sync.lock';
          $lockFp = fopen($lockFile, 'c');
          if ($lockFp) {
            $gotLock = flock($lockFp, LOCK_EX | LOCK_NB);
          }
        } catch (\Throwable $_e) {
          $gotLock = false;
        }
      }

      if (!$gotLock) {
        try {
          self::logDebug(null, 'Skipping markdown sync: failed to obtain lock');
        } catch (\Throwable $_e) {
        }
        return 0;
      }

      // re-check TTL after obtaining lock
      if ($ttlSeconds > 0) {
        try {
          $last = wire('cache')->get($cacheKey) ?? 0;
          if (time() - (int) $last < $ttlSeconds) {
            if ($lockFp) {
              @flock($lockFp, LOCK_UN);
              @fclose($lockFp);
              @unlink(wire('config')->paths->cache . 'markdown-sync.lock');
            }
            if (function_exists('apcu_delete')) {
              \call_user_func('apcu_delete', $apcuKey);
            }
            try {
              self::logDebug(
                null,
                'Skipping markdown sync after re-check: recent run within TTL',
              );
            } catch (\Throwable $_e) {
            }
            return 0;
          }
        } catch (\Throwable $_e) {
        }
      }
    }

    $didRun = false;
    try {
      $didRun = true;
      foreach ($pages as $p) {
        try {
          if (!self::supportsPage($p)) {
            continue;
          }
          // Determine the target hash field and persisted payload (if any)
          $targetField = $hashFieldName ?? self::getHashField($p);
          $existingPayload = null;
          try {
            if (
              is_string($targetField) &&
              $targetField !== '' &&
              $p->hasField($targetField)
            ) {
              $existingPayload = $p->get($targetField);
            } else {
              // fallback to session or site cache if the template does not have the hash field
              $existingPayload = self::recallFileHash($p, $targetField);
              if ($existingPayload === null || $existingPayload === '') {
                // site cache fallback per-page
                $cacheKeyPage = 'markdown-sync-hash-' . (int) $p->id;
                $cached = wire('cache')->get($cacheKeyPage);
                if (is_string($cached) && $cached !== '') {
                  $existingPayload = $cached;
                }
              }
            }
          } catch (\Throwable $_e) {
            $existingPayload = null;
          }

          // Build normalized payloads and compare to skip unnecessary sync
          $currentHashes = self::languageFileHashes($p);
          $currentEncoded = self::encodeHashPayload($p, $currentHashes);
          $existingDecoded = self::decodeHashPayload($p, $existingPayload);
          $existingEncoded = self::encodeHashPayload($p, $existingDecoded);

          if ($existingEncoded !== '' && $currentEncoded === $existingEncoded) {
            // Uncomment for deep debugging to see all skipped pages:
            // self::logDebug($p, 'skip page: file hash unchanged', [
            //   'path' => (string) $p->path,
            // ]);
            $processed++;
            continue;
          }
          $dirtyFields = self::syncFromMarkdown($p);

          // If syncFromMarkdown threw an exception (e.g., protected field save failed),
          // it will be caught in the outer catch block and we skip hash updates.
          // This ensures we don't persist a hash when critical fields couldn't be saved.

          $payload = self::buildHashPayload($p);

          $didHashSave = false;
          $languageChanges = [];
          if ($payload !== '') {
            $targetField = $hashFieldName ?? self::getHashField($p);

            // Determine previous payload (DB-stored hash if present, else session fallback)
            $existingPayload = null;
            try {
              if (
                is_string($targetField) &&
                $targetField !== '' &&
                $p->hasField($targetField)
              ) {
                $existingPayload = $p->get($targetField);
              } else {
                $existingPayload = self::recallFileHash($p, $targetField);
              }
            } catch (\Throwable $_e) {
              $existingPayload = null;
            }

            $oldHashes = self::decodeHashPayload($p, $existingPayload);
            $newHashes = self::decodeHashPayload($p, $payload);

            // detect language changes
            $codes = array_unique(
              array_merge(array_keys($oldHashes), array_keys($newHashes)),
            );
            foreach ($codes as $code) {
              $old = $oldHashes[$code] ?? null;
              $new = $newHashes[$code] ?? null;
              if ($old !== $new) {
                if ($old === null && $new !== null) {
                  $languageChanges[$code] = 'added';
                } elseif ($old !== null && $new === null) {
                  $languageChanges[$code] = 'removed';
                } else {
                  $languageChanges[$code] = 'changed';
                }
              }
            }

            if (
              $persist &&
              is_string($targetField) &&
              $targetField !== '' &&
              $p->hasField($targetField)
            ) {
              // Only persist the hash if it is different to the existing value
              $existingPayloadNormalized = $existingPayload === null ? '' : (string) $existingPayload;
              $payloadStr = (string) $payload;
              if ($existingPayloadNormalized !== $payloadStr) {
                $p->of(false);
                $p->set($targetField, $payloadStr);
                $p->save($targetField);
                $didHashSave = true;
                // Clear stale cache fallback if we persisted to the field
                wire('cache')->delete('markdown-sync-hash-' . (int) $p->id);
              }
            }

            // If we couldn't write the hash to the page field but persistence is desired,
            // store the value in site cache so subsequent runs are stable.
            if ($persist && !$didHashSave) {
              $cacheKeyPage = 'markdown-sync-hash-' . (int) $p->id;
              if ($payload !== '') {
                wire('cache')->save($cacheKeyPage, (string) $payload, 0);
              }
            }
          }
          $didDirtySave = !empty($dirtyFields);
          $didAnySave = $didDirtySave || !empty($didHashSave);

          if ($didAnySave) {
            // Only log & count updated pages if we actually persisted something
            if ($didDirtySave && !empty($dirtyFields)) {
              self::logDebug($p, 'fields updated after markdown sync', [
                'fields' => implode(',', $dirtyFields),
              ]);
            }
            if ($languageChanges) {
              $labelParts = [];
              foreach ($languageChanges as $code => $type) {
                $labelParts[] =
                  $code . ($type === 'changed' ? '' : "({$type})");
              }
              $label = implode(', ', $labelParts);
            } else {
              $label = 'hash';
            }
            $updated++;
            $updatedPages[] = sprintf('%s [%s]', (string) $p->path, $label);

            // Production-level log for actual updates
            self::logInfo($p, 'page synced from markdown', [
              'changes' => $label,
            ]);

            try {
              $log->save(
                $logChannel,
                sprintf('Updated %s: %s', (string) $p->path, $label),
              );
            } catch (\Throwable $_e) {
            }
          }

          $processed++;
        } catch (\Throwable $e) {
          $isProtectedFieldError = str_contains(
            $e->getMessage(),
            'protected fields',
          );

          if ($isProtectedFieldError) {
            // Log prominently for protected field failures
            $log->save(
              $logChannel,
              sprintf(
                'ERROR: Failed to sync %s - %s',
                (string) $p->path,
                $e->getMessage(),
              ),
            );
          }

          self::logDebug($p, 'syncAllManagedPages failed', [
            'message' => $e->getMessage(),
            'protectedFieldError' => $isProtectedFieldError,
          ]);
        }
      }
    } finally {
      // Release any locks and persist TTL marker if requested
      if ($useLock && $gotLock) {
        // Release file lock if acquired
        if (isset($lockFp) && $lockFp) {
          @flock($lockFp, LOCK_UN);
          @fclose($lockFp);
          @unlink(wire('config')->paths->cache . 'markdown-sync.lock');
        }

        // Release APCu lock if available (independent of file lock)
        if (function_exists('apcu_delete')) {
          @\call_user_func('apcu_delete', $apcuKey);
        }
      }

      if ($didRun && $ttlSeconds > 0) {
        wire('cache')->save($cacheKey, time(), $ttlSeconds);
      }
    }

    try {
      $log->save(
        $logChannel,
        sprintf(
          'Markdown sync migration done (%d pages; %d updated)',
          $processed,
          $updated,
        ),
      );
      if ($updated > 0 && $updatedPages) {
        $limit = 30;
        $summaryList = $updatedPages;
        $more = 0;
        if (count($summaryList) > $limit) {
          $more = count($summaryList) - $limit;
          $summaryList = array_slice($summaryList, 0, $limit);
        }
        $message = 'Updated pages: ' . implode('; ', $summaryList);
        if ($more > 0) {
          $message .= sprintf(' ... +%d more', $more);
        }
        $log->save($logChannel, $message);
      }
    } catch (\Throwable $_e) {
      // ignore
    }

    return $processed;
  }
}
