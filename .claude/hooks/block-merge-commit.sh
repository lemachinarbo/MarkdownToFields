#!/bin/bash
# Denies any `git merge` invocation. This project's git-commit-standards.md rule
# is squash/rebase only, flat history — there's no legitimate git merge call to
# carve out.
COMMAND=$(jq -r '.tool_input.command // empty')

if echo "$COMMAND" | grep -qE '(^|[;&|]|&&)\s*git merge\b'; then
  jq -n '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: "git merge is not allowed in this project — flat history only, use rebase or squash instead (see .agents/rules/git-commit-standards.md)."
    }
  }'
else
  exit 0
fi
