# Revert Entity Changes

AuditTrailBundle can revert an entity to an earlier audited state. Use it to
undo a bad change or recover a deleted record after you review the audit
history.

In the built-in EasyAdmin UI, the revert button is shown only for the latest
meaningful state-changing log of an entity. Older `create`, `update`, or
`soft_delete` entries can still be reverted through the CLI or code, but the
admin UI hides them to reduce stale-history mistakes.

```bash
# Revert an entity to its state in a specific audit log
php bin/console audit:revert 018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a

# Revert with custom context (JSON)
php bin/console audit:revert 018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a --context='{"reason": "Accidental deletion", "ticket": "T-101"}'

# Revert without silencing automatic audit logs (Keep technical logs)
php bin/console audit:revert 018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a --noisy
```

## Custom Context on Revert

You can pass custom context when you revert an entity in code. This is useful
when you want to record why the revert happened.

```php
<?php

declare(strict_types=1);

$auditReverter->revert($log, false, false, [
    'reason' => 'Accidental deletion',
    'ticket_id' => 'TICKET-456',
]);
```

The bundle also adds `reverted_log_id` to the new audit log context so it
points back to the original entry.
The explicit `revert` audit follows the same transport rules as the rest of the
bundle. Transports that support in-transaction delivery can record the revert
inside the Doctrine transaction. Deferred-only transports such as queue or HTTP
are sent only after the entity change commits successfully.
If an in-transaction transport fails with `fail_on_transport_error: true`, the
entity revert is rolled back with it. If only deferred delivery is available,
the revert may already be committed when the later transport failure is
reported. In that case, replaying the same update revert is rejected as a no-op
instead of creating an empty second `revert` audit.

## Controlling Log Redundancy

By default, `AuditReverter` disables the normal `AuditSubscriber` during a
revert. This stops the bundle from creating a duplicate `update` log next to
the explicit `revert` log.

If you want to keep those normal technical logs too, you can turn that off:

```php
<?php

declare(strict_types=1);

// Pass 'false' as the 5th parameter to keep standard update logs enabled
$auditReverter->revert($log, false, false, [], false);
```

## What the Reverter Handles

- Handles entity relations and collections
- Handles UUID-backed relations in previews and real reverts
- Normalizes root entity identifiers before lookup
- Supports soft-delete restore when the configured handler supports it
- Supports dry runs with `--dry-run`
- Validates the entity before finishing the revert

## Custom Revert Handlers

You can extend custom revert behavior through dedicated action handlers.

Implement:

```php
use Rcsofttech\AuditTrailBundle\Contract\RevertActionHandlerInterface;
```

Implementations are auto-tagged by the bundle. You do not need to add the
`audit_trail.revert_action_handler` tag manually when autoconfiguration is enabled.

Use this when you need action-specific revert logic without decorating the main
revert service.

## EasyAdmin Preview Behavior

The EasyAdmin revert modal uses the same dry-run logic as the code API.

- To-many relations are restored as collections, not as raw Doctrine internals.
- UUID-backed relations are previewed safely without triggering missing-identifier errors.
- Inverse-side collection restores synchronize owning relations so persisted reverts match the preview more reliably.
- Collection items are rendered in a readable form such as `Tag#018f... (Security)` instead of `ArrayCollection@...`.

> [!TIP]
> Use the dry-run output first when you are reverting important data.
