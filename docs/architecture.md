# Architecture Guide

This document gives contributors a quick overview of the bundle.

It is not a full internal spec. It just shows where the main work happens and
which extension points you should use first.

## High-Level Flow

Most audit work happens in two phases:

1. `onFlush`
2. `postFlush`

In simple terms:

- `onFlush` is where the bundle inspects Doctrine changes
- `postFlush` is where deferred work is finalized and dispatched

This split helps the bundle stay within Doctrine lifecycle rules. It also lets
the bundle handle cases where final relation IDs do not exist yet in the first
phase.

## Main Runtime Pieces

### Flush Processing

These services are the heart of flush-time auditing:

- `AuditSubscriber`
- `AuditOnFlushProcessor`
- `AuditPostFlushProcessor`
- `EntityProcessor`
- `EntityInsertionProcessor`
- `EntityUpdateProcessor`
- `EntityCollectionUpdateProcessor`
- `EntityDeletionProcessor`

They mainly do this work:

- detect entity and collection changes
- decide whether something should be audited
- create immediate audit work or defer it
- finalize post-flush work and dispatch it

`EntityProcessor` stays small in v4. It keeps the
`EntityProcessorInterface` entry point and passes each flush-time lifecycle
concern to a smaller processor service.

### Audit Creation and Dispatch

These services build and send audit logs:

- `AuditLogFactory`
- `AuditLogMessageFactory`
- `AuditDispatcher`
- `EntityAuditDispatchManager`
- `ScheduledAuditManager`
- `AuditLogWriter`

They mainly:

- turning detected changes into audit log objects
- assigning stable transport payloads for async and queue delivery
- tracking scheduled and deferred work
- dispatching to transports
- handling fallback and delivery result paths

For async database delivery, `AuditLogMessageFactory` preserves the audit row's
UUID before Messenger dispatch and `AuditLogWriter` reuses that UUID when the
worker persists the row. This keeps UUID-sorted readers and keyset pagination
stable even if worker processing order differs from creation order.

### Querying Audit Logs

The query layer is split in v4:

- `AuditReader`
- `AuditQuery`
- `AuditQueryState`
- `AuditQueryExecutor`
- `AuditQueryPage`

In short:

- `AuditReader` is the bundle-facing query entry point
- `AuditQuery` is the fluent immutable API callers chain against
- `AuditQueryState` holds filter and cursor state
- `AuditQueryExecutor` performs repository access and changed-field execution, using native database predicates when supported and batched fallback scans otherwise
- `AuditQueryPage` materializes entries with the next cursor from one query

This keeps the fluent API small and moves execution and paging rules out of the
query builder itself.

### Transports

Transport code lives in `src/Transport`.

The built-in transports cover:

- database
- HTTP
- queue / Messenger
- chain / null transport helpers

If you are changing delivery behavior, start there and in `AuditDispatcher`.

### Admin UI

Admin integration is split into smaller services.

Look at:

- `AuditLogCrudController`
- `Bridge\EasyAdmin\Service\AuditLogAdminCrudConfigurator`
- `Bridge\EasyAdmin\Service\AuditLogAdminFieldProvider`
- `Bridge\EasyAdmin\Service\AuditLogAdminOperations`
- `Bridge\EasyAdmin\Service\AuditLogAdminViewFactory`
- `Bridge\EasyAdmin\Service\AuditLogExportResponseFactory`

If your change is mostly about presentation or export behavior, change these
services instead of adding more logic to the controller.

### Revert Support

Revert behavior is spread across focused services in `src/Service/Revert*`.

Important pieces include:

- `AuditReverter`
- `RevertPlanBuilder`
- `RevertEntityStateApplier`
- `RevertValueDenormalizer`
- `RevertActionHandlerInterface` implementations

If you need new revert behavior, prefer a new handler or helper instead of
making `AuditReverter` bigger.

### Integrity

Integrity features live around:

- `AuditIntegrityService`
- `AuditIntegrityNormalizer`
- `VerifyIntegrityCommand`

If you touch signing or verification behavior, be careful about compatibility
with already-persisted logs.

## Common Extension Points

The main supported extension points are:

- `AuditVoterInterface`
- `AuditContextContributorInterface`
- `AuditTransportInterface`
- `AuditLogAiProcessorInterface`
- `RevertActionHandlerInterface`
- `ScheduledAuditManagerInterface`

If your use case fits one of these, prefer that over changing internal services.

## Testing Map

The test suite is split mainly into:

- `tests/Unit`
- `tests/Functional`
- `tests/Integration`

Use this rule of thumb:

- service logic change: unit test
- Doctrine lifecycle or flush behavior change: functional test
- container wiring or compiler/extension change: integration test

## Contributor Tips

- If you change `src/Contract`, treat it as a public API change.
- If you change `onFlush` or `postFlush` behavior, verify collection handling
  and transport behavior carefully.
- If you add a new service split, update `services.yaml` and the relevant tests
  in the same PR.
- If you change admin UI behavior, check both the PHP service layer and the Twig
  output path.
