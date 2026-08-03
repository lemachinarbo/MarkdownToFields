# ProcessWire Contract

You are assisting with ProcessWire website development.

These are hard constraints, not preferences.

## Mantra

- This is a website, not a module.
- Keep it simple. Trust ProcessWire.
- Prefer clear, boring, readable code over cleverness.
- Use native ProcessWire APIs and conventions.
- Avoid enterprise patterns, DSLs, magic helpers, and unnecessary indirection.
- If there is one obvious way, do that.


## Simplicity And Abstraction Gate

- Prefer the simplest correct implementation.
- Do not introduce abstractions unless they solve a real, current problem.
- An abstraction is allowed only if it removes real duplication or existing complexity, makes the code easier for a human to understand, and is used in at least two concrete places.
- Optimize for clarity, maintainability, and debuggability, not sophistication.


## Framework First

- ProcessWire already provides lifecycle, safety, permissions, and IO.
- Do not reimplement ProcessWire responsibilities or APIs.
- Always look for existing API methods before adding your own.
- If unsure, ask or check the docs.
- Refactors must be behavior-preserving unless explicitly instructed.
- Do not change semantics, output, side effects, or data shape unless the task requires it.


## Code Style And Structure

- Prefer explicit, linear code.
- Minimal indirection.
- One obvious way is better than flexible abstractions.
- Inline small logic instead of creating layers to organize it.
- Avoid architecture for its own sake.


## Error Handling And Boundaries

- Use `try/catch` only at real system boundaries.
- Never swallow exceptions silently.
- If failure is unrecoverable, fail loudly.


## Determinism, Fallbacks, And Layers

- Do not add defensive fallbacks inside deterministic logic.
- Do not turn deterministic systems into probabilistic ones.
- Core logic is strict: no fallbacks for invalid state or missing required data.
- Boundary logic may tolerate external uncertainty such as IO or user input.
- UI logic should turn errors into messages and never silence them.


## Data And Mutability

- Transform data before final render state, not after.


## Logging

- Log only meaningful mutations.
