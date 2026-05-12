# Testing Rules

**ATTENTION AI AGENT:** This repository is a standalone module symlinked into a ProcessWire "Hub" installation. You must follow these mechanical rules strictly.

## 1. Environmental Context
- **Module Root:** Current directory.
- **Testing Hub:** Located at `../pwlayground/` (or your specific path).
- **All PHP commands must be executed from the Testing Hub root.**

## 2. Framework Strictness
- **Forbidden:** PHPUnit, Pest, Mocks, Stubs, Spies.
- **Required:** ProcessWire `WireTests` module via the Hub.

## 3. The Golden File Strategy
We test behavior, not implementation. Do not write PHP test methods for edge cases.
1. **To add a test:** Create a subdirectory in `tests/fixtures/`.
2. **Files:** Include `input.md` (raw scenario) and `expected.json` (expected field array).
3. **Execution:** The central runner at `site/modules/WireTests/tests/MarkdownToFields.php` (inside the Hub) handles the rest.

## 4. Execution Pipeline
To verify your work, instruct the user to run:
```bash
cd ../pwlayground && php index.php test MarkdownToFields
```