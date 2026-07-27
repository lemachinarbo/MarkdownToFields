<?php

namespace ProcessWire;

/**
 * The value every lens returns: body plus everything needed to emit it as
 * a complete, cacheable HTTP response. send() is the single place headers
 * are set, so cache behavior (ETag/304) is uniform across all lenses and
 * no lens ever talks to header()/http_response_code() directly.
 */
class MarkdownLensResponse
{
  public function __construct(
    public string $body,
    public string $contentType,
    public int $status = 200,
    public array $headers = [],
  ) {}

  /**
   * Emits status + headers and returns the body for output. Successful
   * responses get an ETag derived from the body; a matching
   * If-None-Match request short-circuits to an empty 304.
   */
  public function send(): string
  {
    http_response_code($this->status);
    header('Content-Type: ' . $this->contentType);

    foreach ($this->headers as $header) {
      header($header);
    }

    if ($this->status === 200) {
      $etag = '"' . md5($this->body) . '"';
      header('ETag: ' . $etag);
      if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return '';
      }
    }

    return $this->body;
  }
}
