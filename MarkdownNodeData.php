<?php

namespace ProcessWire;

use LetMeDown\ContentData;
use LetMeDown\FieldContainer;
use LetMeDown\FieldData;
use LetMeDown\Section;

class MarkdownNodeData
{
  public static function fromNode(Page $page, $source, ?ContentData $content = null)
  {
    $content = $content ?? (method_exists($page, 'content') ? $page->content() : null);
    if (!$content instanceof ContentData) {
      return null;
    }

    $node = is_string($source)
      ? self::resolvePath($content, $source)
      : $source;

    if (!is_object($node) || !method_exists($node, 'data')) {
      return null;
    }

    $area = self::findArea($content, $node);
    return self::adaptNode($page, $node, $node->data(), $area);
  }

  public static function adaptData(Page $page, object $node, $data, string $area)
  {
    return self::adaptNode($page, $node, $data, $area);
  }

  private static function adaptNode(Page $page, object $node, $data, string $area)
  {
    if (!is_array($data)) {
      return $data;
    }

    $adapted = $data;

    if ($node instanceof ContentData) {
      foreach ($node->sectionsByName as $name => $section) {
        $childArea = self::joinArea($area, (string) $name);
        $adapted[(string) $name] = self::adaptNode($page, $section, $adapted[$name] ?? $section->data(), $childArea);
      }

      return $adapted;
    }

    if ($node instanceof Section) {
      $adapted['key'] = (string) ($adapted['key'] ?? $node->key ?? '');
      $adapted['area'] = $area;
      $adapted['subsections'] = is_array($adapted['subsections'] ?? null) ? $adapted['subsections'] : [];

      foreach ($node->fields as $name => $field) {
        $childArea = self::joinArea($area, (string) $name);
        $adapted[(string) $name] = self::adaptNode($page, $field, $adapted[$name] ?? $field->data(), $childArea);
      }

      foreach ($node->subsections as $name => $subsection) {
        $childArea = self::joinArea($area, (string) $name);
        $child = self::adaptNode($page, $subsection, $adapted[$name] ?? $subsection->data(), $childArea);
        $adapted[(string) $name] = $child;
        $adapted['subsections'][(string) $name] = $child;
      }

      unset($adapted['items'], $adapted['blocks']);

      return $adapted;
    }

    if ($node instanceof FieldData) {
      $adapted['type'] = (string) ($adapted['type'] ?? $node->type);
      $adapted['key'] = (string) ($adapted['key'] ?? $node->key ?? '');
      $adapted['area'] = $area;

      if (self::isImageArray($adapted)) {
        return self::adaptImage($page, $adapted, $area);
      }

      if (self::isLinkArray($adapted)) {
        return self::adaptLink($adapted, $area);
      }

      if (isset($adapted['items']) && is_array($adapted['items'])) {
        foreach ($adapted['items'] as $index => $item) {
          $itemArea = self::joinArea($area, (string) $index);
          $adapted['items'][$index] = self::adaptFieldItem($page, $item, $itemArea);
        }
      }

      return $adapted;
    }

    if ($node instanceof FieldContainer) {
      $adapted['key'] = (string) ($adapted['key'] ?? $node->key ?? '');
      $adapted['area'] = $area;
      $adapted['items'] = is_array($adapted['items'] ?? null) ? $adapted['items'] : [];

      if (method_exists($node, 'fields')) {
        foreach ($node->fields() as $name => $field) {
          $childArea = self::joinArea($area, (string) $name);
          $adapted[(string) $name] = self::adaptNode($page, $field, $adapted[$name] ?? $field->data(), $childArea);
        }
      }

      unset($adapted['blocks'], $adapted['subsections']);

      return $adapted;
    }

    return $adapted;
  }

  private static function adaptFieldItem(Page $page, array $item, string $area): array
  {
    $adapted = $item;
    if ($area !== '') {
      $adapted['area'] = $area;
    }

    if (isset($adapted['images']) && is_array($adapted['images'])) {
      $adapted['images'] = self::adaptImageList($page, $adapted['images'], self::joinArea($area, 'images'));
    }

    if (isset($adapted['links']) && is_array($adapted['links'])) {
      $adapted['links'] = self::adaptLinkList($adapted['links'], self::joinArea($area, 'links'));
    }

    return $adapted;
  }

  private static function adaptImageList(Page $page, array $images, string $baseArea): array
  {
    $adapted = [];
    foreach (array_values($images) as $index => $image) {
      $adapted[] = is_array($image)
        ? self::adaptImage($page, $image, self::joinArea($baseArea, (string) $index))
        : $image;
    }
    return $adapted;
  }

  private static function adaptLinkList(array $links, string $baseArea): array
  {
    $adapted = [];
    foreach (array_values($links) as $index => $link) {
      $adapted[] = is_array($link)
        ? self::adaptLink($link, self::joinArea($baseArea, (string) $index))
        : $link;
    }
    return $adapted;
  }

  private static function adaptImage(Page $page, array $image, string $area): array
  {
    $adapted = $image;
    if ($area !== '') {
      $adapted['area'] = $area;
    }

    $pageimage = MarkdownUtilities::pageimage($page, $image['src'] ?? null);
    if ($pageimage instanceof Pageimage) {
      $adapted['pageimage'] = $pageimage;
      $adapted['src'] = $pageimage->url;
    }

    return $adapted;
  }

  private static function adaptLink(array $link, string $area): array
  {
    $adapted = $link;
    if ($area !== '') {
      $adapted['area'] = $area;
    }
    return $adapted;
  }

  private static function isImageArray(array $value): bool
  {
    return isset($value['src']) && is_string($value['src']);
  }

  private static function isLinkArray(array $value): bool
  {
    return isset($value['href']) && is_string($value['href']);
  }

  private static function joinArea(string $base, string $segment): string
  {
    $segment = trim($segment, '/');
    if ($segment === '') {
      return $base;
    }
    if ($base === '') {
      return $segment;
    }
    return $base . '/' . $segment;
  }

  private static function resolvePath(ContentData $content, string $path)
  {
    $normalized = trim($path, " \t\n\r\0\x0B/");
    if ($normalized === '') {
      return $content;
    }

    $segments = preg_split('#[/.]+#', $normalized) ?: [];
    $value = $content;

    foreach ($segments as $segment) {
      if ($segment === '') {
        continue;
      }

      if (is_array($value) && array_key_exists($segment, $value)) {
        $value = $value[$segment];
        continue;
      }

      if (is_object($value)) {
        $child = $value->{$segment} ?? null;
        if ($child !== null) {
          $value = $child;
          continue;
        }
      }

      return null;
    }

    return $value;
  }

  private static function findArea(ContentData $content, object $target): string
  {
    $segments = self::findObjectPath($content, $target);
    return $segments ? implode('/', $segments) : '';
  }

  private static function findObjectPath($root, object $target): array
  {
    $visited = [];
    $result = self::walkForObjectPath($root, $target, [], $visited);
    return is_array($result) ? $result : [];
  }

  private static function walkForObjectPath($current, object $target, array $segments, array &$visited): ?array
  {
    if (is_object($current)) {
      if ($current === $target) {
        return $segments;
      }

      $objectId = spl_object_id($current);
      if (isset($visited[$objectId])) {
        return null;
      }
      $visited[$objectId] = true;
    }

    foreach (self::enumerateChildren($current) as $segment => $child) {
      $found = self::walkForObjectPath($child, $target, array_merge($segments, [(string) $segment]), $visited);
      if (is_array($found)) {
        return $found;
      }
    }

    return null;
  }

  private static function enumerateChildren($value): array
  {
    if (is_array($value)) {
      return $value;
    }

    if (!is_object($value)) {
      return [];
    }

    $children = [];

    foreach (['sectionsByName', 'fields', 'subsections'] as $property) {
      $propertyValue = $value->{$property} ?? null;
      if (!is_array($propertyValue)) {
        continue;
      }

      foreach ($propertyValue as $key => $child) {
        $children[(string) $key] = $child;
      }
    }

    if ($children) {
      return $children;
    }

    foreach (get_object_vars($value) as $key => $child) {
      if (is_array($child) || is_object($child)) {
        $children[(string) $key] = $child;
      }
    }

    return $children;
  }
}
