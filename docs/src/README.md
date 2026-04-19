# Verified Documentation Builder

This script automates the generation of `docs/guide.md` from `docs/src/guide.source.md`. It ensures that every code example and property table shown in the documentation is 100% accurate and verified against the actual codebase.

## Quick Commands

- **Build documentation** (Verify only):
  ```bash
  ddev php docs/src/build-docs.php
  ```

- **Update snapshots** (Approve changes):
  Use this when you intentionally change an example or update the module logic.
  ```bash
  ddev php docs/src/build-docs.php --update
  ```

## Example Structure

Each example lives in its own folder under `docs/src/examples/`:

```text
examples/hero/
├── source.md    # Input Markdown
├── render.php   # PHP Snippet (Logic)
├── expect.html  # Verified HTML output (Auto-generated/verified)
└── expect.txt   # Verified Object Dump (Auto-generated/verified)
```

## Placeholders Reference

You can use these markers in `guide.source.md`:

- `[[EXAMPLE:name]]`: Injects both the Markdown and PHP from the example folder.
- `[[EXAMPLE:name:md]]`: Injects only the Markdown.
- `[[EXAMPLE:name:php]]`: Injects only the PHP.
- `[[DUMP:name]]`: Injects the verified object dump (`expect.txt`).
- `[[REFLECT:ClassName]]`: Generates a property table for any class in the `LetMeDown` namespace using Reflection.

## Verification Logic

The builder uses **Snapshot Testing**:
1. It runs the example.
2. It compares the raw result to the `expect.*` files.
3. If they don't match, the build **fails**. 

This prevents "Documentation Drift" where the code works one way but the docs show another.
