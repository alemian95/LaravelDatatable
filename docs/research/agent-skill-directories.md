# Where coding agents look for Agent Skills (without Boost)

Research for issue #49 (child of map #47). Facts only; the packaging decision belongs to #50.
Official docs only, read on 2026-10-11.

## 1. Skill directories per agent

All six agents support Agent Skills (a folder holding a `SKILL.md` with YAML frontmatter). None of them is limited to always-loaded instruction files any more.

| Agent | Native project dir | Also reads (project) | Reads `.agents/skills/`? | Always-loaded instruction files |
|---|---|---|---|---|
| Claude Code | `.claude/skills/` (cwd and parents up to repo root, plus nested `<subdir>/.claude/skills/`) | none | **No** | `CLAUDE.md`; `AGENTS.md` only when no `CLAUDE.md` exists (v2.1.277+) |
| OpenAI Codex | `.agents/skills/` (cwd up to repo root) | none | **Yes** (native) | `AGENTS.override.md` / `AGENTS.md` (+ configurable fallbacks) |
| Cursor | `.cursor/skills/`, `.agents/skills/` | `.claude/skills/`, `.codex/skills/` | **Yes** | `.cursor/rules/*.mdc`, `AGENTS.md` |
| GitHub Copilot (VS Code, cloud/coding agent, CLI, code review) | `.github/skills/` | `.claude/skills/`, `.agents/skills/` | **Yes** | `.github/copilot-instructions.md`, `.github/instructions/*.instructions.md`, `AGENTS.md`, or root `CLAUDE.md`/`GEMINI.md` |
| Gemini CLI | `.gemini/skills/` | `.agents/skills/` (alias, wins over `.gemini/skills/` on name clash) | **Yes** | `GEMINI.md` (`context.fileName` can add `AGENTS.md`) |
| JetBrains Junie (CLI; IDE plugin shares skills) | `.junie/skills/` | `.agents/skills/` (trusted projects) | **Yes** | `.junie/AGENTS.md`, `AGENTS.md`, legacy `.junie/guidelines.md` |

Notes:
- Claude Code's AGENTS.md section says it does **not** read "anything under a `.agents/` directory". Open feature requests (e.g. anthropics/claude-code#31005, #50778, #56193) confirm `.agents/skills/` is unsupported.
- Junie does not load `.claude/skills/`, `.cursor/skills/` or `.codex/skills/`; it detects them and *offers to import* them into `.junie/skills/`.
- Cursor and Copilot read both `.claude/skills/` and `.agents/skills/`. Shipping the same skill in both may produce a name collision; the agentskills.io guide says clients should pick one deterministically and warn.

## 2. Shared convention

- The **agentskills.io specification** defines only what is *inside* a skill (`SKILL.md`, `name` ≤ 64 chars matching the folder, `description` ≤ 1024 chars, optional `scripts/`, `references/`, `assets/`). It does not mandate a location.
- The agentskills.io **client implementation guide** recommends that clients scan `<project>/.<client>/skills/` **and** `<project>/.agents/skills/` ("a widely-adopted convention for cross-client skill sharing"), and notes some clients also scan `.claude/skills/` for compatibility.
- Honoured by: Codex, Cursor, Copilot, Gemini CLI, Junie. **Not** Claude Code.

## 3. Instruction-only agents

None of the six. All support on-demand skills alongside their always-loaded files.

## Recommendation

Smallest set covering all six agents: **two paths**.

1. `.agents/skills/<name>/SKILL.md`: Codex, Cursor, Copilot, Gemini CLI, Junie (5/6).
2. `.claude/skills/<name>/SKILL.md`: Claude Code (also Cursor, Copilot).

`.claude/skills/` alone covers only 3/6 (Claude Code, Cursor, Copilot). If writing the same skill twice, keep the files identical: Cursor and Copilot will see both copies.

## Sources

- Claude Code skills: https://code.claude.com/docs/en/skills
- Claude Code memory / AGENTS.md: https://code.claude.com/docs/en/memory
- Codex skills: https://learn.chatgpt.com/docs/build-skills (redirect from https://developers.openai.com/codex/skills)
- Codex AGENTS.md: https://learn.chatgpt.com/docs/agent-configuration/agents-md
- Cursor skills: https://cursor.com/docs/context/skills
- Cursor rules: https://cursor.com/docs/context/rules
- Copilot in VS Code: https://code.visualstudio.com/docs/copilot/customization/agent-skills
- Copilot (GitHub docs): https://docs.github.com/en/copilot/concepts/agents/about-agent-skills
- Copilot instructions: https://docs.github.com/en/copilot/how-tos/configure-custom-instructions/add-repository-instructions
- Gemini CLI skills: https://geminicli.com/docs/cli/skills/
- Gemini CLI context files: https://geminicli.com/docs/cli/gemini-md/
- Junie skills: https://junie.jetbrains.com/docs/agent-skills.html
- Junie guidelines: https://junie.jetbrains.com/docs/guidelines-and-memory.html
- Spec: https://agentskills.io/specification
- Client guide: https://agentskills.io/client-implementation/adding-skills-support
