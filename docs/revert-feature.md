# Revert Entity Changes

AuditTrailBundle provides a powerful **Point-in-Time Restore** capability, allowing you to undo accidental changes or recover data from any point in your audit history.

```bash
# Revert an entity to its state in a specific audit log
php bin/console audit:revert 123

# Revert with custom context (JSON)
php bin/console audit:revert 123 --context='{"reason": "Accidental deletion", "ticket": "T-101"}'
```

## Custom Context on Revert

You can pass custom context when programmatically reverting an entity. This is useful for tracking why a revert was performed.

```php
$auditReverter->revert($log, false, false, [
    'reason' => 'Accidental deletion',
    'ticket_id' => 'TICKET-456'
]);
```

The bundle also **automatically** adds `reverted_log_id` to the context of the new audit log, linking it back to the original entry.

## Why it's "Safe"

- **Association Awareness**: Automatically handles entity relations and collections.
- **Soft-Delete Support**: Temporarily restores soft-deleted entities to apply the revert.
- **Dry Run Mode**: Preview exactly what will change before applying (`--dry-run`).
- **Data Integrity**: Ensures the entity remains in a valid state after the rollback.

> [!TIP]
> Use the revert feature for **emergency data recovery**, **undoing unauthorized changes**, or **restoring accidental deletions** with full confidence.
