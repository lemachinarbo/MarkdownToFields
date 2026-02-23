
# ProcessWire Module Coding Contract

You are assisting with ProcessWire module development.

Mantra:
- You’re coding for a ProcessWire module.
- Keep it simple. Trust the framework.
- No enterprise patterns, no abstractions, no defensive over-engineering.
- Prefer clear, boring, readable code over clever code.
- Use native ProcessWire APIs and conventions.
- Avoid smart magic, DSLs, service layers, or unnecessary indirection.
- If something can be done in one obvious way, do it that way


Assume the following as hard constraints, not preferences:

## Architecture & intent

* This is a *module*, not an application.
* ProcessWire already provides lifecycle, safety, permissions, and IO.
* Trust the framework, trust processwire API
* Do not reimplement Processwire API
* Always look for existing API methods before adding new ones. If you dont know them ask or check the docs.
* Do not re-implement framework responsibilities.
* APIs > behavior. Data exposure > helper logic.
* Refactors must be behavior-preserving. Do not change semantics, output, side effects, or data shape unless explicitly instructed.

## Code style

* Prefer boring, explicit, linear code.
* One obvious way > flexible abstractions.
* No enterprise patterns: no services, factories, managers, adapters, DTOs.
* No magic helpers, no DSLs, no reflection hacks.
* Minimal indirection. If a function can be inline, inline it.

## Error handling

* Use `try/catch` **only** at real system boundaries:

  * external input
  * persistence
  * framework calls that are documented to throw
* Never catch exceptions just to “be safe”.
* Never swallow exceptions silently.
* If failure is unrecoverable, let it fail loudly.

## Mutability rules

* Parsed data is canonical and immutable after creation.
* No post-parse fixing, patching, or mutation.
* If data must be transformed, do it *before* object creation.
* Projection helpers are allowed only if:

  * they are pure
  * they do not recompute or invent data
  * they do not mutate originals

## Fallback Policy

Do not add defensive fallbacks inside deterministic logic.
Do not turn deterministic systems into probabilistic ones.

## Layered rule

* **Core logic** → strict. No fallbacks. Throw on invalid state or missing required data.
* **Boundary layer** → tolerant. Fallbacks allowed for external uncertainty (IO, network, user input).
* **UI layer** → user-friendly. Convert errors into messages, never silence them.

If a condition indicates a bug, fail fast.
If a condition can happen in normal operation, handle gracefully.


## Logging

* Log only when something *meaningful changes*.
* Never log:

  * function entry
  * configuration
  * no-ops
  * early exits
* One log per actual mutation, maximum.

## Templates

* Templates are dumb.
* No helpers required to “fix” data for templates.
* If templates need logic, the data model is wrong.

## When proposing changes

* Default to the smallest possible change.
* Prefer documentation over behavior changes.
* Prefer explicit opt-in helpers over automatic behavior.
* Avoid adding new public methods unless they expose data, not behavior.

## Tone

* Be direct.
* No cheerleading.
* No summaries unless explicitly requested.
* If something is over-engineered, say so plainly.