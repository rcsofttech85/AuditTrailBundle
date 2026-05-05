# Upgrade Guide: v3 to v4

This guide is for applications upgrading from a 3.x release to `4.0`.

v4 includes a few important API changes and one important correctness fix for
same-flush collection auditing.

The most important upgrade themes are:

- audit actions are now represented by the new `AuditAction` enum
- several contracts and service boundaries were tightened or moved
- AI-related admin and audit-insight capabilities were expanded
- same-flush to-many relation auditing now resolves final identifiers later in
  the flush lifecycle instead of persisting placeholders

If your application only uses the built-in bundle services and transports, the
upgrade is usually manageable:

- update the package
- clear the container cache
- run your test suite
- manually verify one same-flush collection add scenario

If you maintain custom transports, custom scheduled-audit infrastructure,
contract implementations, or custom admin/event integrations, read the rest of
this guide carefully.

## Quick Checklist

Before deploying v4:

1. Upgrade the package and clear the container cache.
2. Update any code that reads or writes audit actions as raw strings.
3. If you implement bundle contracts, review the current signatures directly.
4. If you implement `ScheduledAuditManagerInterface`, add support for pending
   audit plans:
   - `schedulePendingAuditPlan()`
   - `getPendingAuditPlans()`
   - `replacePendingAuditPlans()`
5. If you import `TrackableCollectionInterface`, update the namespace to
   `Rcsofttech\AuditTrailBundle\Contract\TrackableCollectionInterface`.
6. If you already use custom AI enrichment, review
   `AuditLogAiProcessorInterface`, the `context['ai']` payload shape, and any
   admin-side AI insight integrations you expose.
7. If you subscribed to old event-name constants, switch to event classes.
8. If you manually instantiate `EntityProcessor`, update that wiring to match
   the new concrete constructor or prefer DI / `EntityProcessorInterface`.
9. If you manually instantiate `AuditQuery` or `AuditReader`, update that
   wiring to match the new concrete constructors or prefer DI /
   `AuditReaderInterface`.
10. If you implement `AuditExporterInterface`, update `exportToStream()` to
    return the exported record count as `int`.

## 1. `AuditAction` Is New in v4

v4 introduces `Rcsofttech\AuditTrailBundle\Enum\AuditAction`.

This is a real major-version change from v3:

- `AuditLog::$action` is now an `AuditAction` enum-backed field
- `AuditServiceInterface`, `AuditVoterInterface`, and
  `ChangeProcessorInterface` now use `AuditAction`
- command, repository, query, renderer, transport, and admin flows now work
  with the enum-oriented model

What this means in practice:

- custom code that compared raw action strings may need updates
- custom contract implementations must match the new action type expectations
- if you persisted or transformed action values outside the bundle, re-check
  those integrations

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
- event usage is class-based rather than relying on older event-name constants
- AI-related admin and insight features were expanded on top of the existing
  AI-oriented enrichment hook
- the project now includes a CI workflow that checks backward compatibility for
  public API changes
- scheduled-audit state handling is more explicit
- flush-time entity lifecycle handling is split across focused processors while
  preserving the `EntityProcessorInterface` entry point
- transport and repository/query internals were modernized around typed
  services and value objects
- the query layer now separates immutable fluent state from execution and page
  materialization
- `AuditExporterInterface::exportToStream()` now returns `int` instead of
  `void`

If your application extends the bundle, review the current interfaces in
`src/Contract` directly.

### Audit export contract change

v4 keeps the `audit:export` command behavior the same at the user level, but
the export pipeline was restructured internally around streaming services.

The relevant upgrade point for custom integrations is:

- `AuditExporterInterface::exportToStream()` now returns the number of
  exported records as `int`

If you provide a custom `AuditExporterInterface` implementation, update that
method signature accordingly.

## 3. AI Support

The AI processor hook is not new in v4. What changed in v4 is the admin and
audit insight side around it.

The core idea stays the same:

- there is no hard dependency on any AI provider
- applications can attach structured AI metadata to audit logs before signing
  and dispatch
- AI metadata remains namespaced under `context['ai']`

The current behavior is:

- processors can be skipped for phases where AI enrichment is not allowed
- processor failures are contained so they do not crash normal audit delivery
- oversized AI metadata is truncated defensively
- collisions between AI processor namespaces/keys are handled explicitly

What this means for integrators:

- if you do nothing, nothing AI-specific is required
- if you provide custom AI processors, review the interface and current tests
- if you expose audit data in custom admin tools, review any new AI insight UI
  expectations separately from the core processor hook
- if you consume audit context downstream, be aware that `context['ai']` may
  now exist in a structured form

This is still an extension point, not built-in AI provider integration.

## 4. Same-Flush Collection Adds Are Fixed

This is the biggest correctness improvement in v4.

In v3 and earlier, the bundle tried to serialize to-many association
identifiers too early during flush processing. That caused wrong audit payloads
when a related entity did not yet have a stable identifier, especially with
auto-increment integer IDs.

Typical older symptoms:

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

What this means in practice:

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
- `replacePendingAuditPlans(array $plans): void`

This is required because v4 may delay final audit-log construction for
collection-sensitive create/update flows until `postFlush`.

If your custom manager only handled scheduled audits and pending deletions, you
need to update it for v4.

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

If your application does not extend the bundle, v4 is mostly an API/contract
cleanup plus a correctness improvement for same-flush collection auditing.

If your application does extend the bundle, treat this as a real major upgrade.
Review the current contracts, upgrade guide, and affected tests, not just the
changelog summary.
