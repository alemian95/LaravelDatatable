# How Laravel Boost discovers and installs third-party package skills

Research for issue #48 (child of map #47). Facts only; what we ship belongs to #50/#51.
Sources read on 2026-10-11:

- `laravel/boost` **v2.10.3**, commit [`3fdb467`](https://github.com/laravel/boost/tree/3fdb46776ea1e9c77319b11d196848f16394f0ed) (2026-10-09). Links below are pinned to it; `B/` = `https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/`.
- `laravel/roster` v1.0.0 (Boost requires `^1.0.0`), which supplies the package list.
- [laravel.com/docs/12.x/boost](https://laravel.com/docs/12.x/boost), sections "Third-Party Package AI Guidelines" and "Third-Party Package Skills". The page is partly stale (it still lists Gemini CLI, removed from Boost in v2.4.9, PR #819), so the code wins where they differ.

## TL;DR for this repo

- Ship `resources/boost/skills/<name>/SKILL.md` in the Composer package. Boost picks it up only if the app lists `alemian95/laraveldatatable` **directly** in `composer.json`, and only after the user **ticks the package** in a multiselect (`boost:install`, or `boost:update` when it detects a new package).
- Plain `SKILL.md` is **not** Blade-rendered, so `@` and `{{ }}` need no escaping. Only `*.blade.php` files are rendered, and `resources/boost/guidelines/core.blade.php` is one of them. Ship `core.md` instead to avoid Blade, or wrap the samples in `@verbatim`.
- The installed directory takes its name from the frontmatter `name`, not from the source directory. An app skill in `.ai/skills/` with the same `name` wins. Between two packages, the last one merged wins, with no warning.
- npm packages are scanned too (Boost **≥ 2.8.1**), from `node_modules/<name>/resources/boost/...` for direct `package.json` deps. Composer package skills work from **v2.0.0**.

## 1. When and what is discovered

**Commands.** Both. `boost:install` calls `selectThirdPartyPackages()` ([B/src/Console/InstallCommand.php#L263-L288](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Console/InstallCommand.php#L263-L288)). That method runs `ThirdPartyPackage::discover()` and shows a multiselect, *"Which third-party AI guidelines/skills would you like to install?"*, pre-ticked with the packages saved in `boost.json`. Non-interactive runs keep only the saved selection. The choice is stored with `setPackages()` (L534/L536). `boost:update` re-runs the install silently with the saved selection ([B/src/Console/UpdateCommand.php#L56-L60](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Console/UpdateCommand.php#L56-L60)). Before that, unless `--no-discover` is set, it prompts for **newly** discovered packages (L69-L106). That prompt is **skipped** when the run is non-interactive or runs as a Composer script (`COMPOSER_DEV_MODE` set, L77, L112-L115). So the documented `post-update-cmd` hook never adds a new package: the user must run `boost:install` or an interactive `boost:update` once.

**Which packages.** Only **direct** dependencies that are not first-party ([B/src/Install/ThirdPartyPackage.php#L63-L79](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/ThirdPartyPackage.php#L63-L79)):

```php
// Transitive dependencies are excluded so an indirect package cannot inject guidelines.
->filter(fn (Package $package): bool => $package->isDirect() && ! PackageRegistry::isFirstParty($package))
```

"Direct" means listed in the app's `composer.json` `require`/`require-dev` (or `package.json` deps), as computed by Roster. Before v2.5.0 Boost read `composer.json` `require` + `require-dev` itself ([v2.0.0 `src/Support/Composer.php`](https://github.com/laravel/boost/blob/v2.0.0/src/Support/Composer.php)), with the same effect. First-party means the `laravel/*` scope plus a fixed list (livewire, inertia-laravel, pest...; `src/Support/Composer.php`, `src/Support/Npm.php`). `alemian95/*` is not first-party.

**Matching.** By path. `PackageRegistry::boostPath()` checks `<package path>/resources/boost/skills` (or `/guidelines`) with `is_dir` ([B/src/Support/PackageRegistry.php#L84-L93](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Support/PackageRegistry.php#L84-L93)). The package path comes from Roster: `vendor/<name>` (respects `config.vendor-dir`) or `node_modules/<name>`. The selection key is the full package name (`alemian95/laraveldatatable`) ([B/src/Install/SkillComposer.php#L126-L139](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/SkillComposer.php#L126-L139)). Each subdirectory of `skills/` that holds a `SKILL.blade.php` or `SKILL.md` (Blade wins if both exist) becomes one skill (L205-L269). There is no version subdirectory for third-party skills; `skills/<name>/` is read flat.

## 2. Blade rendering and escaping

**Skills.** `parseSkill()` renders only when the file ends in `.blade.php`, otherwise it reads the raw file ([B/src/Install/SkillComposer.php#L225-L227](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/SkillComposer.php#L225-L227)). On copy, `SkillWriter::copyFile()` ([B/src/Install/SkillWriter.php#L228-L266](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/SkillWriter.php#L228-L266)) handles each file in the skill directory, recursively and including dotfiles:
- `*.blade.php` is Blade-rendered and written as `*.md`;
- `*.md` is **not** rendered, only passed through `MarkdownFormatter::format()`. That normalises line endings, adds blank lines around `#`-`####` headings and collapses 3+ blank lines, all *outside* code fences ([B/src/Install/MarkdownFormatter.php](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/MarkdownFormatter.php));
- any other file is copied byte-for-byte with its permissions.

So `SKILL.md` and `references/*.md` need **no** `@`/`{{ }}` escaping.

**Guidelines.** `resources/boost/guidelines/` accepts `*.blade.php` **and** `*.md` (Finder, [B/src/Install/GuidelineComposer.php#L315-L330](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/GuidelineComposer.php#L315-L330)). Every file goes through `renderBladeFileWithScopedBlocks`, but `renderContent()` returns non-Blade content untouched ([B/src/Concerns/RendersBladeGuidelines.php#L16-L22](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Concerns/RendersBladeGuidelines.php#L16-L22)). Note that `@boostsnippet` and `@scoped` are still pre-processed in `.md` files (L263-L274). For `core.blade.php`, Boost protects these before calling `Blade::render` (L27-L48):
- backticks, `<?php`, `@volt`/`@endvolt`, `@can`, `@include`, `@props`, `<x-`/`</x-`, and `&` inside fences;
- `@verbatim`, `@@`, `@{{` and `@{!!` escapes.

Everything else is compiled, **including inside code fences**: `{{ $x }}`, `@if`, `@foreach`, `@php`, `@json`... So code samples in `core.blade.php` must use `@verbatim ... @endverbatim` (the docs' own example does this) or `@{{ }}`/`@@`. A render failure is not fatal: Boost records it, drops the content (L50-L62), and warns at the end of the install. For a static guideline, the simplest path is `core.md`. Its key is still `<package>/core`, so overrides work the same way.

## 3. Name collisions

Skills are a `Collection` keyed by frontmatter `name`, built by successive `merge()` calls, so a later source overwrites an earlier one ([B/src/Install/SkillComposer.php#L47-L62](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/SkillComposer.php#L47-L62)):

```
core (.ai/boost/skill) → integrations → first-party package skills → third-party skills
  → reject(config('boost.skills.exclude')) → user skills (.ai/<pkg>/skill, then .ai/skills/*)
```

- **App override:** `.ai/skills/<dir>/SKILL.md` whose frontmatter `name` equals ours replaces the package skill. The match is on the `name` value, not the directory name. User skills are merged after `exclude` and are never excluded. The written result is marked custom and symlinked from `.ai/skills` where possible (`writeCustomSkill`, [B/src/Install/SkillWriter.php#L66-L89](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/SkillWriter.php#L66-L89)).
- **App opt-out:** `config/boost.php` → `'skills' => ['exclude' => ['<name>']]`, or untick the package.
- **Two packages, same name:** `flatMap` collapses with string keys, so the **last** package wins silently. The order is Composer packages, then npm, in Roster's scan order. A third-party skill also silently replaces a Boost built-in or first-party skill with the same name, because third-party skills are merged after them. Guidelines do not collide: they are keyed `<package>/<relative path>` (GuidelineComposer L268-L282). Apps override them at `.ai/guidelines/<vendor>/<package>/core.(blade.php|md)` (`guidelinePath()`, L409-L420).
- Stale skills (tracked in `boost.json` but no longer discovered) are deleted from agent dirs on sync (`SkillWriter::sync`, L118-L124).

## 4. npm packages

Yes, since **v2.8.1** (PR #935, *"support third-party NPM package guidelines and skills"*). `boostDirectories()` concatenates `$project->php()->packages()` with `$project->js()->packages()` (ThirdPartyPackage L71-L72). Roster's JS scanners resolve each direct `package.json` dependency to `node_modules/<name>` (`laravel/roster` `src/Scanners/JsPackageScanner.php` L43, `src/Scanners/PackageJson.php`). So `@alemian95/laraveldatatable-react` could ship `resources/boost/skills/...` inside its npm tarball (it must be in `files`). It would appear as a **separate** entry in the multiselect, keyed `@alemian95/laraveldatatable-react`. `node_modules` must be installed when Boost runs.

## 5. Minimum Boost version

| Capability | First tag | Commit |
|---|---|---|
| Third-party `resources/boost/skills` (Composer, direct deps, user-selected) | **v2.0.0** | `999c9b8` "Skills Support (#421)" |
| Third-party `resources/boost/guidelines` (and `boost:update`) | v1.3.0 | `ef88008` (#277) |
| Roster-based discovery (`isDirect()`) | v2.5.0 | `30ba429` |
| npm packages | **v2.8.1** | `3bc08e9` (#935) |

v2.0.0 already filtered third-party skills by the selected packages (`$this->config->aiGuidelines`), see [v2.0.0 SkillComposer](https://github.com/laravel/boost/blob/v2.0.0/src/Install/SkillComposer.php).

## 6. Where skills are written

Each agent implementing `SupportsSkills` has a `skillsPath()` (overridable via `config('boost.agents.<agent>.skills_path')`). `SkillWriter` writes to `<skillsPath>/<frontmatter name>/` ([B/src/Install/SkillWriter.php#L39](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/SkillWriter.php#L39)). Package (non-custom) skills are **copied**, not symlinked.

| Agent | Default path | Source |
|---|---|---|
| Claude Code | `.claude/skills` | `src/Install/Agents/ClaudeCode.php#L66-L68` |
| Codex | `.agents/skills` | `Codex.php#L86-L88` |
| Cursor | `.cursor/skills` | `Cursor.php#L72-L74` |
| GitHub Copilot | `.github/skills` | `Copilot.php#L70-L72` |
| Junie | `.junie/skills` | `Junie.php#L79-L81` |
| Gemini CLI | — removed in v2.4.9 (#819, replaced by Antigravity → `.agents/skills`) | `Antigravity.php#L54-L56` |
| Others | Amp, OpenCode, Zed → `.agents/skills`; Factory `.factory/skills`; Kiro `.kiro/skills`; Pi `.pi/skills`; Grok Build `.grok/skills` | `src/Install/Agents/*` |

**Interactive choice:** per **package**, not per skill. The multiselect lists packages labelled e.g. `alemian95/laraveldatatable (guidelines, skills)` (`ThirdPartyPackage::displayLabel()`). A package's guidelines and skills are selected together. Selecting a package installs all of its skills. Per-skill opt-out exists only through `boost.skills.exclude`. Skills also require the "Agent Skills" feature to be selected (`InstallCommand` L143).

## 7. Frontmatter and naming constraints

From `parseSkill()` / `parseSkillFrontmatter()` ([B/src/Install/SkillComposer.php#L217-L299](https://github.com/laravel/boost/blob/3fdb46776ea1e9c77319b11d196848f16394f0ed/src/Install/SkillComposer.php#L217-L299)):

- YAML frontmatter between `---` lines at the top (leading HTML comments allowed). It must be a mapping with non-empty `name` **and** `description`. Otherwise the skill is skipped, and recorded as a parse failure if any frontmatter existed. An unclosed `---` is a parse failure. A file without frontmatter is silently skipped.
- `name` need **not** match the directory name. The installed directory uses `name`.
- `SkillWriter::isValidSkillName()` (L345-L353) rejects only `..`, `/`, `\`, NUL and names made only of dots and whitespace. An invalid name makes `writeAll()` **throw** after writing the valid ones. Boost enforces no other character rules. The [Agent Skills spec](https://agentskills.io/what-are-skills) (which the Boost docs cite) asks for lowercase letters, digits and hyphens, matching the directory. Following that keeps the skill portable to agents that load it natively.
- Other frontmatter keys are ignored by Boost and copied through untouched.

## Open points (not answered by source)

- Roster's merge order between two Composer packages with the same skill `name` depends on lock-file order. It is not tested here.
- Whether each agent itself accepts a `name` that differs from the directory is agent behaviour, outside Boost; not verified here.
