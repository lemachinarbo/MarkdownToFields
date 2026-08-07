<?php

namespace ProcessWire;

/**
 * Fallback response provider for Agent Discovery & Protocol Endpoints
 * (RFC 9727, RFC 9728, MCP, Agent Cards, Agent Skills, DNS-AID, llms.txt, auth.md)
 */
class MarkdownAgentEndpoints extends WireData
{
  public function handle(string $endpoint): ?MarkdownLensResponse
  {
    $httpRoot = rtrim($this->wire()->urls->httpRoot, '/');
    $host = parse_url($httpRoot, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scheme = parse_url($httpRoot, PHP_URL_SCHEME) ?: 'https';
    $baseUrl = "{$scheme}://{$host}";
    $homepage = $this->wire()->pages->get('/');
    $siteName = (string) ($homepage->title ?: 'ProcessWire Site');

    switch (ltrim($endpoint, '/')) {
      case 'llms.txt':
      case 'llms-full.txt':
        $body = "# {$siteName}\n\n";
        $body .= "> Machine-readable index for LLMs and AI agents.\n\n";
        $body .= "## Pages\n\n";
        if ($homepage->id) {
          foreach ($homepage->children("limit=20") as $child) {
            if ($child->viewable()) {
              $body .= "- [{$child->title}]({$child->httpUrl})\n";
            }
          }
        }
        $body .= "\n## Agent Discovery & Protocols\n\n";
        $body .= "- [API Catalog]({$baseUrl}/.well-known/api-catalog)\n";
        $body .= "- [MCP Server Card]({$baseUrl}/.well-known/mcp/server-card.json)\n";
        $body .= "- [Agent Card]({$baseUrl}/.well-known/agent-card.json)\n";
        $body .= "- [Agent Skills]({$baseUrl}/.well-known/agent-skills/index.json)\n";
        $body .= "- [Auth Spec]({$baseUrl}/auth.md)\n";
        return new MarkdownLensResponse($body, 'text/plain; charset=utf-8');

      case 'auth.md':
      case '.well-known/auth.md':
        $body = "# Agent Authorization Model - {$siteName}\n\n";
        $body .= "All public content and pages on `{$host}` are open and accessible to AI agents without authentication.\n\n";
        $body .= "No API keys or OAuth tokens are required for public read access.\n";
        return new MarkdownLensResponse($body, 'text/markdown; charset=utf-8');

      case '.well-known/api-catalog':
        $json = json_encode([
          'linkset' => [[
            'anchor' => "{$baseUrl}/.well-known/api-catalog",
            'service-desc' => [['href' => "{$baseUrl}/.well-known/mcp/server-card.json", 'type' => 'application/json']],
            'service-doc' => [['href' => "{$baseUrl}/llms.txt", 'type' => 'text/plain']],
            'describedby' => [['href' => "{$baseUrl}/.well-known/agent-card.json", 'type' => 'application/json']]
          ]]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');

      case '.well-known/oauth-protected-resource':
        $json = json_encode([
          'resource' => $baseUrl,
          'authorization_servers' => [],
          'scopes_supported' => [],
          'bearer_methods_supported' => [],
          'resource_documentation' => "{$baseUrl}/auth.md"
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');

      case '.well-known/oauth-authorization-server':
        $json = json_encode([
          'issuer' => $baseUrl,
          'service_documentation' => "{$baseUrl}/auth.md"
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');

      case '.well-known/mcp/server-card.json':
        $json = json_encode([
          '$schema' => 'https://modelcontextprotocol.io/schemas/server-card.json',
          'name' => "{$siteName} MCP Server",
          'description' => "MCP server card for {$siteName}",
          'vendor' => $siteName,
          'homepage' => $baseUrl,
          'version' => '1.0.0',
          'capabilities' => ['tools' => true, 'resources' => true, 'prompts' => true],
          'transport' => ['type' => 'http', 'url' => "{$baseUrl}/api/mcp"]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');

      case '.well-known/agent-card.json':
        $json = json_encode([
          'name' => "{$siteName} Agent Interface",
          'description' => "AI Agent Card for {$siteName}",
          'url' => $baseUrl,
          'version' => '1.0.0',
          'capabilities' => [
            'content_negotiation' => true,
            'markdown_support' => true,
            'agent_skills' => true,
            'mcp_support' => true
          ],
          'skills' => ["{$baseUrl}/.well-known/agent-skills/index.json"]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');

      case '.well-known/dns-aid':
        $json = json_encode([
          'version' => '1.0',
          'domain' => $host,
          'name' => $siteName,
          'endpoints' => [
            'llms' => "{$baseUrl}/llms.txt",
            'api_catalog' => "{$baseUrl}/.well-known/api-catalog",
            'mcp' => "{$baseUrl}/.well-known/mcp/server-card.json",
            'agent_card' => "{$baseUrl}/.well-known/agent-card.json",
            'agent_skills' => "{$baseUrl}/.well-known/agent-skills/index.json",
            'auth' => "{$baseUrl}/auth.md"
          ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');

      case '.well-known/web-bot-auth':
        $json = json_encode([
          'version' => '1.0',
          'policy' => 'public-read',
          'allowed_bots' => ['*'],
          'authentication_required' => false
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');

      case '.well-known/agent-skills/index.json':
        $json = json_encode([
          'version' => '1.0',
          'skills' => [
            [
              'name' => 'content',
              'description' => 'Query site content and pages as clean Markdown.',
              'url' => "{$baseUrl}/.well-known/agent-skills/content/SKILL.md"
            ]
          ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');

      case '.well-known/agent-skills/content/SKILL.md':
        $body = "# Agent Skill: Content Discovery\n\n";
        $body .= "Request any URL on `{$host}` with `Accept: text/markdown` or append `?format=md` / `.md` to retrieve clean structured Markdown.\n";
        return new MarkdownLensResponse($body, 'text/markdown; charset=utf-8');

      case '.well-known/x402':
      case '.well-known/mpp':
      case '.well-known/ucp':
      case '.well-known/acp':
        $json = json_encode([
          'status' => 'free',
          'payment_required' => false,
          'service' => $siteName
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new MarkdownLensResponse($json, 'application/json');
    }

    return null;
  }
}
