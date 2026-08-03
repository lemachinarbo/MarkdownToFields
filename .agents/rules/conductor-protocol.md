# Conductor Protocol (Master Workflow)

Applies when: running a Conductor-Driven Development track — planning, implementing,
reviewing, or finalizing work via `conductor-jules/tracks.md`, or interacting with
the Jules Orchestrator. Not relevant to unrelated, non-Conductor tasks.

We use a 4-step "Conductor-Driven Development" lifecycle to ensure high-quality, autonomous maintenance:

1.  **Planning**: A task is selected from the backlog and moved to `Active Tracks` in `conductor-jules/tracks.md`. This update MUST be committed and pushed to `master` immediately to "lock" the track.
2.  **Implementation**: Jules implements the plan on a dedicated branch. The Orchestrator monitors for completion; no PR is required as the Orchestrator will ingest the diff directly.
    - **Phase 1: Task Initiation**: The Orchestrator MUST manually verify the issue, sanitize all emojis from the prompt, and enrich the task with mandatory constraints (AGENTS.md standards, ProcessWire native APIs, Simplicity Gate) before delegating to Jules.
3.  **Autonomous Review**: The Conductor (Orchestrator) monitors the session. If Jules is ready but blocked by manual gates (no auto-publish), the Conductor MUST **Overtake** by extracting the patch via API and pushing the PR manually.
    - **Bidirectional Communication**: Use `jm_get_activities` to monitor status and read blocker descriptions. Use `jm_send_message` to provide missing files or architectural guidance. Use `jm_approve_plan` to approve plans that meet AGENTS.md standards.
4.  **Finalization**: An agent verifies the work against the plan and runs tests. The track is moved from `Active` to `Completed` in `tracks.md`. This ledger update is committed to `master` as part of the final squash-merge of Jules' work.
    - **Session Archival**: Upon successful integration and completion of the track, the Orchestrator MUST send the mandatory session archival message to the Jules session with exactly: `Task done. Close and archive.`.
