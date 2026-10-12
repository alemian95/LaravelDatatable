# Contributing

Pull requests are welcome. Before opening one, run:

```bash
composer test
composer analyse
cd react && npm test
```

## Public API changes

The public API is listed in [ADR 0002](docs/adr/0002-public-api-boundary.md). A change to it also updates the Agent Skill in `resources/boost/skills/laraveldatatable/SKILL.md`: `tests/AgentSkillTest.php` catches renamed or missing symbols, but not a changed default or behaviour.
