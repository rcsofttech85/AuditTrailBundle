# Upgrade Guide: v2 to v3

This guide is for applications upgrading from a 2.x release to `3.0`.

The changelog already lists the main release notes, but if your application has
custom transports, custom scheduled-audit infrastructure, or custom event
listeners, you should treat this document as the migration checklist.

If you only use the bundle with stock configuration and built-in services, the
upgrade is usually small:

- update the package
- run the database migration if you use async database transport
- verify the EasyAdmin `admin_permission` still matches the role or permission granted to your audit administrators
- verify any event integrations still match the current contracts

## Who Needs to Read This Carefully

Read the whole guide if your application does any of the following:

- implements `AuditTransportInterface`
- implements `ScheduledAuditManagerInterface`
- listens to `AuditLogCreatedEvent`
- uses the database transport with `async: true`
- extends or decorates internal delivery flow around transport dispatch

If none of those apply, start with the quick checklist below.

## Quick Checklist

Before deploying v3:

1. Upgrade the package and clear the container cache.
2. If `audit_trail.transports.database.async: true` is enabled anywhere, add and run the schema migration for `audit_log.delivery_id`.
3. If you use the EasyAdmin audit UI, confirm `audit_trail.admin_permission` still matches the role or permission your audit admins actually have.
4. If you have a custom transport, update it to the typed `AuditTransportContext` contract.
5. If you have a custom scheduled audit manager, implement the full v3 interface.
6. If you subscribe to audit creation events, subscribe by `AuditLogCreatedEvent::class`.
7. Run your audit flow manually: create, update, delete, access audit, export, and revert.

## 1. Async Database Transport Requires a Schema Change

This affects you only if you use:

```yaml
audit_trail:
    transports:
        database:
            async: true
```

In v3, async database persistence uses a delivery identifier so Messenger
retries can be handled idempotently. That means the `audit_log` table now needs
the `delivery_id` column and a unique constraint for it.

What to do:

1. Generate a Doctrine migration after upgrading.
2. Confirm the migration adds a nullable `delivery_id` column on `audit_log`.
3. Confirm it also adds a unique index or unique constraint for that column.
4. Run the migration before upgraded workers start consuming messages.

If you do not use async database transport, this schema change does not apply to
you.

## 2. Custom Transports Must Use `AuditTransportContext`

This is the biggest custom-code change in v3.

Older custom transports often worked with loosely structured method arguments or
an array-based context. In v3, the transport contract is typed and the bundle
passes a read-only `AuditTransportContext` object instead.

Your transport must now implement:

```php
<?php

use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;

final class AppAuditTransport implements AuditTransportInterface
{
    public function send(AuditTransportContext $context): void
    {
    }

    public function supports(AuditTransportContext $context): bool
    {
        return true;
    }
}
```

What changed in practice:

- `send()` now receives `AuditTransportContext`
- `supports()` now receives `AuditTransportContext`
- phase checks should use `$context->phase`
- the current audit log is available as `$context->audit`
- the source entity is available as `$context->entity` when relevant
- `UnitOfWork` is available as `$context->unitOfWork` only when the current phase has one

Two important migration notes:

- treat the received context as read-only input
- if your old transport expected a plain array or a string phase, it must be rewritten rather than mechanically type-swapped

This usually means your migration work is not just a signature update. Review
any logic that branches by phase or reads delivery metadata.

## 2a. EasyAdmin Audit Access Should Be Verified

If your application uses the built-in EasyAdmin audit screens, verify access
immediately after upgrading.

The bundle protects audit UI actions such as index, detail, export, revert, and
transaction drill-down with the configured `admin_permission` value:

```yaml
audit_trail:
    admin_permission: 'ROLE_ADMIN'
```

This is easy to miss during an upgrade because the symptom looks like a generic
authorization problem rather than a bundle migration issue.

What to check:

- the configured `audit_trail.admin_permission` value in your app
- the actual role or Symfony permission granted to the users who should access the audit UI
- any environment-specific config override that changes this value in production

Typical upgrade symptom:

- audit pages load before upgrade but return access denied after the package bump
- list/detail pages are blocked even though the route and controller are still registered
- export, revert, or transaction drill-down actions disappear or fail authorization

If you do not use the EasyAdmin integration, you can skip this section.

## 3. Custom Scheduled Audit Managers Must Implement the Full Interface

In v2, some custom implementations could survive with a partial contract or by
relying on internal fallback behavior. v3 is stricter.

If you implement `ScheduledAuditManagerInterface`, you now need the full
contract, including accessors and replacement methods for retained state:

- `getScheduledAudits()`
- `getPendingDeletions()`
- `replaceScheduledAudits(array $scheduledAudits)`
- `replacePendingDeletions(array $pendingDeletions)`

The practical reason is simple: v3 keeps retry and retention flow on explicit,
testable methods rather than probing object properties or falling back to
internal assumptions.

If your implementation only stored pending work internally but did not expose
that state, it must be updated.

Things to verify after updating:

- scheduled inserts and updates still survive the flush lifecycle you expect
- failed post-flush dispatches are retained and restored correctly
- pending deletions are not lost when a later delivery step fails

## 4. `AuditLogCreatedEvent` Listeners Should Be Class-Based

v3 dispatches audit creation events using Symfony's class-based event style:

```php
$this->eventDispatcher->dispatch($event);
```

That means your listeners and subscribers should register against
`AuditLogCreatedEvent::class`.

Recommended subscriber style:

```php
<?php

use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AuditSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AuditLogCreatedEvent::class => 'onAuditLogCreated',
        ];
    }
}
```

If you previously wired listeners around the older string event name, review and
update that wiring during the upgrade.

## 5. Transport and Delivery Integrations Should Be Rechecked

Even if you do not maintain a custom transport class, review any code that makes
assumptions about delivery timing.

v3 tightened transport and retry behavior in a few places:

- async database delivery is idempotency-aware
- HTTP delivery failures are treated as real transport failures
- failed post-flush deliveries are retained through explicit manager contracts
- fallback persistence relies on phase-aware internal paths instead of older compatibility behavior

What this means for integrators:

- if you decorate transport services, re-check exception expectations
- if you monitor audit delivery failures, verify your logs and alerts still match current behavior
- if you built tests around silent HTTP acceptance on failure, those tests likely need to change

## 6. What Probably Does Not Need Changing

Most regular bundle consumers do not need code changes for:

- standard `#[Auditable]` usage
- standard `#[AuditAccess]` usage
- built-in database, queue, or HTTP transports
- EasyAdmin browsing, export, and revert usage, aside from verifying `admin_permission` if audit admins lose access
- `AuditReader`-based read queries

You still need normal regression testing, but these areas do not introduce an
obvious v3 migration task on their own.

## Recommended Upgrade Procedure

Use this order in a real application:

1. Upgrade the package in a branch.
2. Verify the EasyAdmin `admin_permission` value in the target environment if your team uses the audit UI.
3. Update custom transport implementations first so the container compiles cleanly.
4. Update any custom `ScheduledAuditManagerInterface` implementation.
5. Update event subscribers to class-based registration where needed.
6. Generate and run the database migration if async database transport is enabled.
7. Run your test suite.
8. Manually verify one audit flow for each transport you actually use.
9. Only then roll updated Messenger workers or background consumers into production.

## Verification Checklist

After the upgrade, verify these behaviors in the running app:

- entity create writes an audit log
- entity update writes one coherent audit log
- entity delete or soft-delete still writes the expected audit log
- access auditing still works for the configured HTTP methods
- EasyAdmin audit users can still open list/detail pages and perform allowed actions under the expected `admin_permission`
- custom transport logic sees the expected phase and audit payload
- async database workers do not create duplicate rows on retry
- audit export and revert still work in the admin UI
- integrity verification still passes for existing signed records and new records

## Final Advice

If your application has no custom implementations, the upgrade is usually
straightforward, but still check the async database migration requirement if you
use that transport mode.

If your application extends the bundle in any meaningful way, do not rely on
the changelog alone. Review the current interfaces directly and treat this
upgrade as a contract update, not just a dependency bump.
