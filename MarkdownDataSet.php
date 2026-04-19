<?php

namespace ProcessWire;

/**
 * ProcessWire-native mutable view over data() output.
 *
 * data() stays a plain-array serializer. dataSet() wraps that array into
 * WireData objects so templates and page classes can use ProcessWire
 * ergonomics for light reshaping.
 */
class MarkdownDataSet extends WireData
{
  private const PROJECTION_RESERVED_KEYS = [
    'type',
    'key',
    'area',
    'html',
    'text',
    'markdown',
    'items',
    'blocks',
    'subsections',
    'href',
    'src',
    'alt',
    'links',
    'pageimage',
    'srcset',
    'sizes',
    'lazy',
    'label',
    'theme',
  ];

  public function __construct(array $data = [])
  {
    $this->setArray($data);
  }

  /**
   * Collapse simple content nodes to their html value while keeping structural
   * nodes, links, images, and iterable helpers intact.
   */
  public function html(): self
  {
    return $this->projectContentValue('html');
  }

  /**
   * Collapse simple content nodes to their text value while keeping structural
   * nodes, links, images, and iterable helpers intact.
   */
  public function text(): self
  {
    return $this->projectContentValue('text');
  }

  public function project(?string $mode = null): self
  {
    return match ($mode) {
      null, '' => $this,
      'html' => $this->html(),
      'text' => $this->text(),
      default => $this,
    };
  }

  public function set($key, $value): self
  {
    $path = $this->segments((string) $key);
    if ($path === []) {
      return $this;
    }

    if (count($path) === 1) {
      parent::set($path[0], $this->wrapValue($value));
      return $this;
    }

    $this->setByPath($path, $value);
    return $this;
  }

  public function setArray(array $data): self
  {
    foreach ($data as $key => $value) {
      $this->set((string) $key, $value);
    }
    return $this;
  }

  public function value(): array
  {
    return $this->toArray();
  }

  /**
   * Shallow-merge an associative array into an object-like node at a path.
   */
  public function merge(string $path, array $data): self
  {
    $current = $this->getPathValue($path);

    if ($current instanceof WireData) {
      $current->setArray($data);
      return $this;
    }

    if (is_array($current) && !$this->isList($current)) {
      return $this->set($path, array_merge($current, $data));
    }

    if ($current === null) {
      return $this->set($path, $data);
    }

    return $this->set($path, $data);
  }

  /**
   * Map an iterable value at a dot-notation path and replace it with the result.
   */
  public function map(string $path, callable $callback): self
  {
    $items = $this->getPathValue($path);
    if ($items === null) {
      return $this->set($path, []);
    }

    $mapped = [];

    if ($items instanceof WireData) {
      foreach ($items->getArray() as $key => $item) {
        $mapped[$key] = $callback($item, $key, $this);
      }
      return $this->set($path, $mapped);
    }

    if ($items instanceof WireArray) {
      foreach ($items as $index => $item) {
        $mapped[] = $callback($item, $index, $this);
      }
      return $this->set($path, $mapped);
    }

    if (is_array($items)) {
      foreach ($items as $index => $item) {
        $mapped[] = $callback($item, $index, $this);
      }
      return $this->set($path, $mapped);
    }

    return $this->set($path, []);
  }

  public function toArray(): array
  {
    return $this->unwrapWireData($this);
  }

  private function projectContentValue(string $key): self
  {
    $projected = $this->projectValue($this->toArray(), $key);

    $keys = [];
    foreach ($this->getArray() as $existingKey => $_) {
      $keys[] = (string) $existingKey;
    }
    foreach ($keys as $existingKey) {
      $this->remove($existingKey);
    }

    if (is_array($projected)) {
      $this->setArray($projected);
    } else {
      $this->set('_value', $projected);
    }

    return $this;
  }

  private function setByPath(array $path, $value): void
  {
    $last = array_pop($path);
    if ($last === null) {
      return;
    }

    $container = $this->ensureContainer($path, $last);
    
    // Support path reading safely
    $currentValue = null;
    if ($container instanceof WireData) {
        $currentValue = $container->get($last);
    } elseif ($container instanceof WireArray && ctype_digit((string)$last)) {
        $currentValue = $container->get((int)$last);
    }

    $nextValue = is_callable($value)
      ? $value($currentValue, $container, $this)
      : $value;

    $wrapped = $this->wrapValue($nextValue);
    
    // Support path writing safely
    if ($container instanceof WireData) {
        $container->set($last, $wrapped);
    } elseif ($container instanceof WireArray && ctype_digit((string)$last)) {
        $container->set((int)$last, $wrapped);
    }
  }

  private function getPathValue(string $path)
  {
    $segments = $this->segments($path);
    if ($segments === []) {
      return null;
    }

    $current = $this;

    foreach ($segments as $segment) {
      if ($current instanceof WireData) {
        $current = $current->get($segment);
        continue;
      }
      
      if ($current instanceof WireArray && ctype_digit((string)$segment)) {
        $current = $current->get((int)$segment);
        continue;
      }

      return null;
    }

    return $current;
  }

  private function ensureContainer(array $path, string $last): WireData|WireArray
  {
    $container = $this;

    foreach ($path as $index => $segment) {
      $next = $path[$index + 1] ?? $last;
      
      $existing = null;
      if ($container instanceof WireData) {
        $existing = $container->get($segment);
      } elseif ($container instanceof WireArray && ctype_digit((string)$segment)) {
        $existing = $container->get((int)$segment);
      }

      if (!$existing instanceof WireData && !$existing instanceof WireArray) {
        $existing = $this->isNumericSegment($next)
          ? new MarkdownDataArray()
          : new self();

        if ($container instanceof WireData) {
          $container->set($segment, $existing);
        } elseif ($container instanceof WireArray && ctype_digit((string)$segment)) {
          $container->set((int)$segment, $existing);
        }
      }

      $container = $existing;
    }

    return $container;
  }

  private function wrapValue($value)
  {
    if (!is_array($value)) {
      return $value;
    }

    return $this->isList($value)
      ? new MarkdownDataArray($value)
      : new self($value);
  }

  private function unwrapValue($value)
  {
    if ($value instanceof self) {
      return $value->toArray();
    }

    if ($value instanceof MarkdownDataArray) {
      return $value->toArray();
    }

    return $value;
  }

  private function unwrapWireData(WireData $data): array
  {
    $out = [];
    foreach ($data->getArray() as $key => $value) {
      $out[$key] = $this->unwrapValue($value);
    }
    return $out;
  }

  private function isList($value): bool
  {
    if (!is_array($value)) {
      return false;
    }

    if (function_exists('array_is_list')) {
      return array_is_list($value);
    }

    return array_keys($value) === range(0, count($value) - 1);
  }

  private function segments(string $path): array
  {
    return array_values(array_filter(
      array_map('trim', explode('.', $path)),
      static fn ($segment) => $segment !== ''
    ));
  }

  private function isNumericSegment(string $segment): bool
  {
    return ctype_digit($segment);
  }

  private function projectValue($value, string $key)
  {
    if ($value instanceof self || $value instanceof MarkdownDataArray) {
      $value = $value->toArray();
    }

    if (!is_array($value)) {
      return $value;
    }

    if ($this->isList($value)) {
      $out = [];
      foreach ($value as $index => $item) {
        $out[$index] = $this->projectValue($item, $key);
      }
      return $out;
    }

    if ($this->shouldCollapseNode($value, $key)) {
      return $value[$key] ?? '';
    }

    $out = [];
    foreach ($value as $childKey => $childValue) {
      $out[$childKey] = $this->projectValue($childValue, $key);
    }

    return $out;
  }

  private function shouldCollapseNode($node, string $key): bool
  {
    if (!is_array($node)) {
      return false;
    }

    if (!array_key_exists($key, $node) || !is_scalar($node[$key]) || $node[$key] === '') {
      return false;
    }

    if (isset($node['subsections']) || isset($node['href']) || isset($node['src']) || isset($node['links'])) {
      return false;
    }

    if (isset($node['items']) && (($node['type'] ?? null) !== null) && !in_array($node['type'], ['text', 'heading'], true)) {
      return false;
    }

    foreach (array_keys($node) as $nodeKey) {
      if (!in_array($nodeKey, self::PROJECTION_RESERVED_KEYS, true)) {
        return false;
      }
    }

    return true;
  }
}

/**
 * WireArray wrapper for ordered list values inside dataSet().
 */
class MarkdownDataArray extends WireArray
{
  public function __construct(array $items = [])
  {
    $this->setArray($items);
  }

  public function isValidItem($item)
  {
    return true; // We store primitives and data objects
  }

  public function set($key, $value): self
  {
    $value = $this->wrapArrayValue($value);

    parent::set($key, $value);
    return $this;
  }

  public function setArray($data): self
  {
    foreach ($data as $key => $value) {
      $this->set($key, $value);
    }

    return $this;
  }

  public function value(): array
  {
    return $this->toArray();
  }

  public function toArray(): array
  {
    $out = [];
    foreach ($this->getArray() as $key => $value) {
      if ($value instanceof MarkdownDataSet || $value instanceof self) {
        $out[$key] = $value->toArray();
        continue;
      }

      $out[$key] = $value;
    }

    return $out;
  }

  private function wrapArrayValue($value)
  {
    if (!is_array($value)) {
      return $value;
    }

    return $this->isList($value)
      ? new self($value)
      : new MarkdownDataSet($value);
  }

  private function isList($value): bool
  {
    if (!is_array($value)) {
      return false;
    }

    if (function_exists('array_is_list')) {
      return array_is_list($value);
    }

    return array_keys($value) === range(0, count($value) - 1);
  }
}
