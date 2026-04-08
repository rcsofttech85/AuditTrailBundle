# Revert Entity Changes

AuditTrailBundle provides a powerful **Point-in-Time Restore** capability, allowing you to undo accidental changes or recover data from any point in your audit history.

When using the built-in EasyAdmin UI, the revert button is intentionally shown only for the latest meaningful state-changing log of an entity. Older `create`, `update`, or `soft_delete` entries remain revertable through the CLI or programmatic API, but the admin UI hides them to reduce stale-history mistakes.

```bash
# Revert an entity to its state in a specific audit log
php bin/console audit:revert 018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a

# Revert with custom context (JSON)
php bin/console audit:revert 018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a --context='{"reason": "Accidental deletion", "ticket": "T-101"}'

# Revert without silencing automatic audit logs (Keep technical logs)
php bin/console audit:revert 018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a --noisy
```

## Custom Context on Revert

You can pass custom context when programmatically reverting an entity. This is useful for tracking why a revert was performed.

```php
<?php

declare(strict_types=1);

$auditReverter->revert($log, false, false, [
    'reason' => 'Accidental deletion',
    'ticket_id' => 'TICKET-456',
]);
```

The bundle also **automatically** adds `reverted_log_id` to the context of the new audit log, linking it back to the original entry.

## Controlling Log Redundancy

By default, `AuditReverter` **silences** the standard `AuditSubscriber` during a revert. This prevents a duplicate `update` log from being created alongside the explicit `revert` log.

For scenarios requiring full technical transparency (e.g., strict forensic compliance), you can disable this silencing:

```php
<?php

declare(strict_types=1);

// Pass 'false' as the 5th parameter to keep standard update logs enabled
$auditReverter->revert($log, false, false, [], false);
```

## What the Reverter Handles

- **Association Awareness**: Automatically handles entity relations and collections.
- **UUID-Safe Relation Restore**: Dry-run previews and persisted reverts correctly restore related entities even when associations use UUID-backed identifiers instead of a plain `id` column.
- **Typed Identifier Restore**: Reverts also normalize root entity identifiers before lookup, covering UUID- and ULID-backed entities more safely.
- **Soft-Delete Support**: Handles soft-delete revert flows and restore operations when supported by the configured soft-delete handler.
- **Dry Run Mode**: Preview exactly what will change before applying (`--dry-run`).
- **Validation**: Validates the entity before completing a persisted revert.

## EasyAdmin Preview Behavior

The EasyAdmin revert modal uses the same dry-run logic as the programmatic reverter.

- To-many relations are restored as collections, not as raw Doctrine internals.
- UUID-backed relations are previewed safely without triggering missing-identifier errors.
- Inverse-side collection restores synchronize owning relations so persisted reverts match the preview more reliably.
- Collection items are rendered in a readable form such as `Tag#018f... (Security)` instead of `ArrayCollection@...`.

> [!TIP]
> Use the revert feature for **emergency data recovery**, **undoing unauthorized changes**, or **restoring accidental deletions** after reviewing the dry-run output.
