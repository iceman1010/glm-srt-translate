# AGENTS.md

## Workflow Rules

### Version Bumping
- **ALWAYS** bump the VERSION file before every commit. No exceptions.
- The version bump must happen BEFORE staging and committing, so the VERSION change is included in the commit.
- Use patch bumps (e.g. 1.3.2 → 1.3.3) for small fixes. Use minor bumps (e.g. 1.3.3 → 1.4.0) for new features.
- Do NOT ask the user about version bumps — just do it. This is required for the self-update process to work.

### Commit and Push
- NEVER commit or push without the user's **explicit approval**.
- Always ask before committing and pushing. Example: "Ready to commit and push?"
- When the user says "commit and push" or gives clear approval, proceed immediately.
- NEVER forget to bump VERSION before committing. If you forget, the update process breaks.

### Making Changes
- Ask before making assumptions about scope. When in doubt, ask.
- Don't revert changes without asking first.
- If multiple features are being worked on, confirm with the user before bundling them into a single commit or splitting them.
