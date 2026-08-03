#!/bin/bash
# Validates the subject line of `git commit` invocations against
# .agents/rules/git-commit-standards.md: type prefix (feat|fix|refactor|chore),
# all-lowercase subject, no trailing period, under 50 chars, no emoji.
# Best-effort extraction only — commits opened without an inline message (an
# editor session) can't be inspected here and are left alone.
COMMAND=$(jq -r '.tool_input.command // empty')

if ! echo "$COMMAND" | grep -qE '(^|[;&|]|&&)\s*git commit\b'; then
  exit 0
fi
if ! echo "$COMMAND" | grep -qE -- '-m\b'; then
  exit 0
fi

# Prefer a heredoc body's first line (the subject, per the "blank line + bullet
# body" convention); fall back to a simple quoted -m "..." / -m '...' message.
SUBJECT=$(echo "$COMMAND" | sed -n "/<<[ ]*['\"]\?EOF['\"]\?/{n;p;q}")
if [ -z "$SUBJECT" ]; then
  SUBJECT=$(echo "$COMMAND" | grep -oP "(?<=-m [\"'])[^\"'\\\\n]*" | head -1)
fi

if [ -z "$SUBJECT" ]; then
  exit 0
fi

REASON=""
if ! echo "$SUBJECT" | grep -qE '^(feat|fix|refactor|chore): [a-z]'; then
  REASON="subject must start with 'feat: '/'fix: '/'refactor: '/'chore: ' followed by a lowercase description"
elif echo "$SUBJECT" | grep -qE '[A-Z]'; then
  REASON="subject line must be entirely lowercase"
elif echo "$SUBJECT" | grep -qE '\.$'; then
  REASON="subject line must not end with a trailing period"
elif [ "${#SUBJECT}" -gt 50 ]; then
  REASON="subject line must be under 50 characters (got ${#SUBJECT})"
elif echo "$SUBJECT" | grep -qP '[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]'; then
  REASON="no emojis allowed in commit messages"
fi

if [ -n "$REASON" ]; then
  jq -n --arg reason "$REASON" --arg subject "$SUBJECT" '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: ("Commit message violates git-commit-standards.md: " + $reason + " — got: \"" + $subject + "\" (see .agents/rules/git-commit-standards.md)")
    }
  }'
else
  exit 0
fi
