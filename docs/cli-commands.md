# CLI Commands

The bundle provides several commands for managing audit logs.

## List Audit Logs

```bash
php bin/console audit:list --entity=User --action=update --limit=50
```

## Purge Old Logs

```bash
php bin/console audit:purge --before="30 days ago" --force
```

Notes:

- If integrity signing is enabled, `audit:purge` verifies matching logs before deletion unless you pass `--skip-integrity`.
- The purge verification path streams matching rows through the repository iterable API, which keeps large purge runs more memory-friendly than eager loading.

## Export Logs

```bash
php bin/console audit:export --format=json --output=audits.json
```

## View Diff

```bash
php bin/console audit:diff User 42
```

## Revert Entity Changes

See [Revert Feature](revert-feature.md).

## Verify Integrity

See [Security & Integrity](security-integrity.md#audit-log-integrity).
