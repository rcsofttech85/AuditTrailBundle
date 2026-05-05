# Architecture Guide

This document is for contributors who want a quick mental model of the bundle.

It is not a full internal spec. It is a practical map of where work happens and
which extension points are meant to be used.

## High-Level Flow

Most audit work happens in two phases:

1. `onFlush`
2. `postFlush`

In simple terms:

- `onFlush` is where the bundle inspects Doctrine changes
- `postFlush` is where deferred work is finalized and dispatched

This split keeps the bundle safer around Doctrine lifecycle rules and lets it
handle cases where final relation identifiers do not exist yet during the first
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

Their responsibilities are roughly:

- detect entity and collection changes
- decide whether something should be audited
- create immediate audit work or defer it
- finalize post-flush work and dispatch it

`EntityProcessor` is intentionally thin in v4. It preserves the
`EntityProcessorInterface` entry point while delegating each flush-time
lifecycle concern to a focused processor service.

### Audit Creation and Dispatch

These services build and send audit logs:

- `AuditLogFactory`
- `AuditDispatcher`
- `EntityAuditDispatchManager`
- `ScheduledAuditManager`

They are responsible for:

- turning detected changes into audit log objects
- tracking scheduled and deferred work
- dispatching to transports
- handling fallback and delivery result paths

### Querying Audit Logs

The query layer is intentionally split in v4:

- `AuditReader`
- `AuditQuery`
- `AuditQueryState`
- `AuditQueryExecutor`
- `AuditQueryPage`

Their responsibilities are roughly:

- `AuditReader` is the bundle-facing query entry point
- `AuditQuery` is the fluent immutable API callers chain against
- `AuditQueryState` holds filter and cursor state
- `AuditQueryExecutor` performs repository access and changed-field batch scans
- `AuditQueryPage` materializes entries with the next cursor from one query

This keeps the fluent API small while moving repository execution and paging
rules out of the query builder itself.

### Transports

Transport code lives in `src/Transport`.

The built-in transports cover:

- database
- HTTP
- queue / Messenger
- chain / null transport helpers

If you are changing delivery behavior, start there and in `AuditDispatcher`.

### Admin UI

Admin integration is intentionally split into smaller services now.

Look at:

- `AuditLogCrudController`
- `AuditLogAdminCrudConfigurator`
- `AuditLogAdminFieldProvider`
- `AuditLogAdminOperations`
- `AuditLogAdminViewFactory`
- `AuditLogExportResponseFactory`

If your change is mostly presentation or export behavior, prefer changing these
services instead of pushing more logic into the controller.

### Revert Support

Revert behavior is spread across focused services in `src/Service/Revert*`.

Important pieces include:

- `AuditReverter`
- `RevertPlanBuilder`
- `RevertEntityStateApplier`
- `RevertValueDenormalizer`
- `RevertActionHandlerInterface` implementations

If you need to add new revert behavior, prefer a new handler or helper over
growing `AuditReverter`.

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
