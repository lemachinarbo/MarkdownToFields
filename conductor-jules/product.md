# Product: MarkdownToFields

## Vision
To provide a seamless, bidirectional bridge between Markdown files and ProcessWire fields, allowing developers to treat the filesystem as the canonical source of truth for content without losing the power of the ProcessWire API.

## Core Pillars
1. **Markdown First**: Content is authored and stored in `.md` files.
2. **Semantic Tagging**: Use HTML comments to structure content into sections and fields.
3. **Deterministic Sync**: Predictable, bi-directional synchronization on every page save.
4. **Structured API**: Expose complex markdown structures as clean PHP objects (`$page->content()`).

## Target Audience
ProcessWire developers who prefer git-friendly content management and local IDE authoring.
