# Laravel Datatable

Server-side datatables for Laravel with a React companion. This glossary covers the terms used when planning the package and how it reaches its users.

## AI agent instructions

**Agent Skill**:
Instructions for the coding agents of the package's users, loaded by the agent on demand when the task touches the package.
_Avoid_: CLAUDE.md, prompt, AI docs

**Guideline**:
Instructions loaded upfront into every agent session, whether or not the task touches the package.
_Avoid_: rules, system prompt

**Distribution**:
The channel through which the Agent Skill reaches a user's project: Laravel Boost reading it from `vendor/`, or a copy published into the project.
_Avoid_: install, export
