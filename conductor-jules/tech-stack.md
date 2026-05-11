# Tech Stack: MarkdownToFields

## Core Engine
- **PHP**: 8.0+
- **ProcessWire**: 3.x
- **Markdown Engine**: `lemachinarbo/letmedown` (Custom parser)
- **HTML Converter**: `league/html-to-markdown`

## Architecture
- **Trait-based API**: `MarkdownContent` trait for Page classes.
- **Hook-driven Sync**: `MarkdownSyncHooks` manages the save/load lifecycle.
- **Hierarchical Parsing**: Heading-based tree structure for content blocks.

## Development Environment
- **DDEV**: Standardized PHP environment.
- **Composer**: Dependency management.
