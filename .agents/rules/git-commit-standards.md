# Git And Commit Standards

- **No Auto-Committing**: Agents must never commit autonomously, EXCEPT when executing the Conductor/Jules Orchestrator release workflow where autonomous committing is required upon user approval of the commit message.
- **Flat History Only**: Never create merge commits. Always squash or rebase to maintain a linear timeline.
- **Scratch Discipline**: All scratch files, debug scripts, or temporary data created during implementation MUST be placed in `conductor-jules/scratch/`. NEVER create scratch files in the project root.
- **Commit Format**: Strictly follow the Conventional Commits specification.
- **No Emojis**: Strictly forbidden in commits, PR titles, or descriptions.
- **Translation Logic (Strict Mapping)**:
   - `add` -> commit as `feat: [description]`
   - `fix` -> commit as `fix: [description]`
   - `remove`, `update`, or `refactor` -> commit as `refactor: [description]`
   - `chore` -> commit as `chore: [description]` (for internal config, tooling, and repo maintenance).
   - **Constraint**: If a request uses a verb outside this list, stop and ask for the correct mapping. Do not infer.


## Submission & PR Guidelines

When creating a PR or submission description, you MUST use the template found in `.github/PULL_REQUEST_TEMPLATE.md`.

**Title Format:** `type: brief description` (no emojis, no capital start, no trailing period)
- **Message Structure (Required)**:
   - Subject line must be exactly: `<type>: <imperative description>`
   - Add a blank line after subject
   - Add a short bullet body describing concrete changes
   - Use `- ` bullets only (no nested lists)
- **Style Rules**:
   - **Case**: The entire subject line must be lowercase.
   - **No Fluff**: No emojis, no "AI-generated" or "Verified" footers, and no trailing periods.
   - **Length**: Keep the subject line concise (under 50 characters).
