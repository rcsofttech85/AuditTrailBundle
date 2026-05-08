# Upgrade Guide: v3 to v4

This guide is for applications upgrading from a 3.x release to `4.0`.

v4 changes a few public APIs and fixes one important collection-audit bug.

The main upgrade points are:

- audit actions are now represented by the new `AuditAction` enum
- several contracts and service boundaries were tightened or moved
- AI-related admin and audit-insight capabilities were expanded
- same-flush to-many relation auditing now resolves final identifiers later in
  the flush lifecycle instead of persisting placeholders

If your application only uses the built-in services and transports, the
upgrade is usually simple:

- update the package
- clear the container cache
- run your test suite
- manually verify one same-flush collection add scenario

If you maintain custom transports, custom scheduled-audit code, contract
implementations, or custom admin or event integrations, read the rest of this
guide carefully.

## Quick Checklist

Before deploying v4:

1. Upgrade the package and clear the container cache.
2. Update any code that reads or writes audit actions as raw strings.
3. If you implement bundle contracts, check the current signatures directly.
4. If you implement `ScheduledAuditManagerInterface`, add support for pending
   audit plans:
   - `schedulePendingAuditPlan()`
   - `getPendingAuditPlans()`
   - If you fully replace the stock `ScheduledAuditManager` service, also
     review the internal failed-dispatch retention flow provided through
     `FailedAuditDispatchRetainerInterface`.
5. If you import `TrackableCollectionInterface`, update the namespace to
   `Rcsofttech\AuditTrailBundle\Contract\TrackableCollectionInterface`.
6. If you configure `soft_delete_field`, ensure it points to a nullable
   timestamp-like field such as `deletedAt` or `archivedAt`. The built-in
   restore flow clears that field by setting it to `null`, so boolean or
   status-based soft-delete markers are not part of the built-in restore
   contract.
7. If you already use custom AI enrichment, review
   `AuditLogAiProcessorInterface`, the `context['ai']` payload shape, and any
   admin-side AI insight integrations you expose.
8. If you subscribed to old event-name constants, switch to event classes.
9. If you manually instantiate `EntityProcessor`, update that wiring to match
   the new concrete constructor or prefer DI / `EntityProcessorInterface`.
10. If you manually instantiate `AuditQuery` or `AuditReader`, update that
    wiring to match the new concrete constructors or prefer DI /
    `AuditReaderInterface`.
11. If you implement `AuditLogInterface` or `EntityIdResolverInterface`,
    update unresolved ID handling:
    - `AuditLogInterface::$entityId` is now nullable
    - unresolved IDs now use `null` instead of placeholder strings
    - callers that require a concrete ID should use
      `hasResolvedEntityId()` / `requireEntityId()`
12. If you implement `AuditExporterInterface`, update `exportToStream()` to
    return the exported record count as `int`.
13. Run your schema migration before production traffic so the new v4 audit-log
    columns and indexes exist. Historical revert rows created on v3 remain
    recognized after upgrade even if they only stored `reverted_log_id` inside
    `context`.
14. If you enable HTTP or queue transport and relied on implicit failure
    defaults, review `fail_on_transport_error` and `fallback_to_database`.
    When those remote transports are enabled and the flags are left unset, v4
    sets them to `true` and `false` respectively.

## 1. `AuditAction` Is New in v4

v4 introduces `Rcsofttech\AuditTrailBundle\Enum\AuditAction`.

This is a real major version change from v3:

- `AuditLog::$action` is now an `AuditAction` enum-backed field
- `AuditServiceInterface`, `AuditVoterInterface`, and
  `ChangeProcessorInterface` now use `AuditAction`
- command, repository, query, renderer, transport, and admin code now use the
  enum model

What this means in practice:

- custom code that compared raw action strings may need updates
- custom contract implementations must match the new action type expectations
- if you store or transform action values outside the bundle, re-check those
  integrations

Typical migration example:

Before:

```php
if ($log->action === 'update') {
}
```

After:

```php
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

if ($log->action === AuditAction::Update) {
}
```

## 2. Contract and Extension Changes

Some extension points changed in v4.

Important examples:

- `TrackableCollectionInterface` moved from `Service` to `Contract`
- `AuditLogInterface::$entityId` is now `?string`, and the contract adds
  `hasResolvedEntityId()` / `requireEntityId()`
- `EntityIdResolverInterface::resolveFromEntity()` and
  `resolveFromValues()` now return `?string`
- event usage is class-based rather than relying on older event-name constants
- AI-related admin features were expanded on top of the existing enrichment
  hook
- the project now includes a CI workflow that checks backward compatibility for
  public API changes
- scheduled-audit state handling is more explicit
- flush-time entity lifecycle handling is split across focused processors while
  preserving the `EntityProcessorInterface` entry point
- transport and repository/query internals were split into smaller typed
  services and value objects
- the query layer now separates immutable fluent state from execution and page
  materialization
- `AuditExporterInterface::exportToStream()` now returns `int` instead of
  `void`
- `ScheduledAuditManagerInterface` keeps pending-audit plan support on the
  public contract, while failed-dispatch `replace*()` methods moved behind the
  internal `FailedAuditDispatchRetainerInterface`

If your application extends the bundle, check the current interfaces in
`src/Contract` directly.

### Audit export contract change

v4 keeps the `audit:export` command behavior the same, but the internal export
pipeline was reworked around streaming services.

The relevant upgrade point for custom integrations is:

- `AuditExporterInterface::exportToStream()` now returns the number of
  exported records as `int`

If you provide a custom `AuditExporterInterface` implementation, update that
method signature accordingly.

## 3. Optional AI Metadata

The AI processor hook is still optional in v4.

What stays the same:

- there is no built-in AI provider integration
- applications can attach structured metadata under `context['ai']`
- normal audit delivery must still work when AI processing is skipped or fails

What changed in v4:

- admin-side AI insight support expanded
- processor failures are contained so they do not break audit delivery
- oversized AI metadata is trimmed defensively
- namespace collisions inside `context['ai']` are handled explicitly

If you use custom AI processors, review the interface, the `context['ai']`
payload shape, and any admin tools that render that metadata.

## 4. Same-Flush Collection Adds Are Fixed

This is the biggest bug fix in v4.

In v3 and earlier, the bundle tried to serialize to-many association
identifiers too early during flush processing. That caused wrong audit payloads
when a related entity did not yet have a stable identifier, especially with
auto-increment integer IDs.

Common old symptoms:

- parent `create` or `update` logs missing the newly added related ID
- placeholder values such as `App\Entity\Category` appearing in collection
  payloads
- collection `newValues` not matching the final persisted relation state

In v4:

- change detection still happens during flush processing
- collection-sensitive create/update flows can be deferred as pending audit
  plans
- final collection identifier materialization happens after flush work has
  completed
- audit payloads now store the final related IDs instead of placeholders

What this means:

- parent `create` and `update` logs are now correct for same-flush relation
  creation cases
- placeholder class-name values are no longer persisted as relation stand-ins
- if your application accidentally depended on those placeholder payloads, that
  behavior is gone

## 5. Custom Scheduled Audit Managers Must Support Pending Audit Plans

If you implement `ScheduledAuditManagerInterface`, v4 adds a new kind of
scheduled work: `PendingAuditPlan`.

Your implementation must now support:

- `schedulePendingAuditPlan(PendingAuditPlan $plan)`
- `getPendingAuditPlans(): array`

This is required because v4 may delay final audit-log construction for
collection-sensitive create/update flows until `postFlush`.

If your custom manager only handled scheduled audits and pending deletions, you
need to update it for v4.

The stock bundle also uses an internal failed-dispatch retention contract to
re-queue work after post-flush delivery failures. Only applications that fully
replace the stock scheduled-audit service need to mirror that internal
behavior. Failed retries should keep the first materialized audit payload so a
later flush does not regenerate timestamps, transaction metadata, or resolved
collection snapshots.

## 5.1. Unresolved Entity IDs Now Use `null`

If you touch the audit entity or identifier contracts directly, note that v4 no
longer models unresolved audit entity IDs with placeholder strings.

What changed:

- `AuditLogInterface::$entityId` is now `?string`
- `EntityIdResolverInterface::resolveFromEntity()` now returns `?string`
- `EntityIdResolverInterface::resolveFromValues()` now returns `?string`

What this means in practice:

- pre-flush or same-flush create flows may carry `null` until the final
  identifier is available
- custom code should stop comparing against placeholder sentinel values
- use `hasResolvedEntityId()` or `requireEntityId()` when you need an explicit
  guard on an audit log instance

## 6. Queue and Toggle Contracts Are Split Internally

`ScheduledAuditManagerInterface` still works as the main public surface, but
internally the responsibilities are split into two narrower contracts:

- `AuditQueueManagerInterface`
- `AuditToggleInterface`

What this means for most apps:

- if you only consume the stock `ScheduledAuditManager`, nothing special is
  required
- if you type-hint or decorate services, use the narrowest contract that
  matches your need

Examples:

- queue scheduling/delivery code should prefer `AuditQueueManagerInterface`
- enable/disable guards should prefer `AuditToggleInterface`

## 7. `EntityProcessor` Construction Changed

v4 keeps `EntityProcessorInterface` intact, but the concrete
`Rcsofttech\AuditTrailBundle\Service\EntityProcessor` class is now a thin
façade over dedicated lifecycle processors:

- `EntityInsertionProcessor`
- `EntityUpdateProcessor`
- `EntityCollectionUpdateProcessor`
- `EntityDeletionProcessor`

What this means in practice:

- if your application only uses Symfony DI/autowiring, nothing special is
  usually required
- if custom code or tests call `new EntityProcessor(...)` directly, that
  wiring must be updated for v4
- this is a source-level BC change on the concrete class, not a contract
  change to `EntityProcessorInterface`

## 8. `AuditQuery` and `AuditReader` Construction Changed

v4 keeps the fluent `AuditQuery` API and `AuditReaderInterface` behavior, but
the concrete construction path changed:

- `AuditQuery` is now a thin immutable facade over dedicated query state and
  execution services
- `AuditReader` now depends on a query executor service instead of assembling
  query collaborators itself
- `AuditQuery::getPage()` is available when callers want entries and the next
  cursor from one materialized query

What this means in practice:

- if your application only uses Symfony DI/autowiring, nothing special is
  usually required
- if custom code or tests call `new AuditQuery(...)` or `new AuditReader(...)`
  directly, that wiring must be updated for v4
- this is a source-level BC change on the concrete classes, not a contract
  change to `AuditReaderInterface`

## 9. What Probably Does Not Need Changing

Most regular bundle consumers do not need code changes for:

- standard `#[Auditable]` usage
- built-in database, queue, or HTTP transports
- normal scalar field auditing
- delete and soft-delete auditing
- revert, export, and integrity verification flows

You still need regression testing, but these areas usually do not need code
changes just for v4.

## Recommended Upgrade Procedure

Use this order in a real application:

1. Upgrade the package in a branch.
2. Update custom contract implementations first.
3. Update any custom code that reads audit actions as strings.
4. Review any custom AI enrichment around audit context.
5. Update any custom `ScheduledAuditManagerInterface` implementation.
6. Update any direct `EntityProcessor` construction in custom code or tests.
7. Update any direct `AuditQuery` or `AuditReader` construction in custom code
   or tests.
8. Review code that parses collection payloads and assumes unresolved relation
   placeholders.
9. Run your test suite.
10. Manually verify one same-flush relation add with a generated identifier.
11. Manually verify collection removal/replacement, export, revert, and
   integrity verification.
12. Only then deploy the upgrade.

## Verification Checklist

After the upgrade, verify these behaviors in the running app:

- entity create still writes an audit log
- entity update still writes one coherent audit log
- entity delete or soft-delete still writes the expected audit log
- custom code reading `AuditLog::$action` still works with the enum
- custom AI processors still enrich audit context the way your app expects
- adding an already-existing related entity to a collection still works
- adding a brand-new related entity to a collection in the same flush now stores
  the final related identifier
- no relation payload stores entity class names as placeholders
- collection remove/replace flows still produce correct `oldValues` and
  `newValues`
- audit export, revert, and integrity verification still work

## Final Advice

If your application does not extend the bundle, v4 is mostly an API and
contract cleanup plus a bug fix for same-flush collection auditing.

If your application does extend the bundle, treat this as a real major upgrade.
Review the current contracts, upgrade guide, and affected tests, not just the
changelog summary.
