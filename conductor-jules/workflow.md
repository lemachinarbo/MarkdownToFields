# Workflow: Conductor Protocol

## 1. Planning
- Pick task from `tracks.md` backlog.
- Move to `Active Tracks`.
- Commit and push `tracks.md` to `master` (Track Locking).

## 2. Implementation
- Jules works on a branch.
- No PR created by Jules (unless manual intervention needed).

## 3. Overtaking (Orchestrator)
- Orchestrator monitors session.
- If Jules is ready/blocked, Orchestrator pulls diff via API.
- Orchestrator pushes the PR manually.

## 4. Finalization
- Verify work against plan.
- Run `ddev composer test`.
- Squash merge to `master`.
- Move track to `Completed` in `tracks.md`.
