# Changelog

All notable changes to the **AuditTrailBundle** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.3.0]

### 2.3.0 New Features

- **Symfony Web Profiler Integration**: The bundle now natively integrates with the Symfony Web Debug Toolbar and Profiler Panel.
  - **Zero Configuration**: Automatically activates in `dev` and `test` environments when `WebProfilerBundle` is present. Adds zero overhead to production (services are strictly conditionally registered).
  - **Toolbar Badge**: Displays a live count of audit logs generated during the current request.
  - **Profiler Panel**: Provides a detailed tab showing every collected audit event (action, entity, changed fields, user, transaction hash, and timestamp) in a clean table format.
  - **Memory Safe**: Built on a highly optimized, two-layer architecture (`TraceableAuditCollector` -> `AuditDataCollector`) ensuring complete serialization safety and no Doctrine memory leaks.

## [2.2.0]

### 2.2.0 Installation Note

**Important for UI updates**: Please run the following command to publish the newly added CSS file for the EasyAdmin integration:

```bash
php bin/console assets:install
```

### 2.2.0 New Features

- **JSON & CSV Export**: Added ability to export filtered audit logs to JSON or CSV directly from the index page via a dropdown action menu. Uses memory-efficient `toIterable()` streaming for large datasets through the new `findAllWithFilters()` repository method.
- **Transaction Drill-down Pagination**: The transaction drill-down view now supports cursor-based (keyset) pagination using `afterId`/`beforeId` for deterministic, offset-free navigation through large transaction groups.
- **Integrity Signature Badge**: Visual integrity verification on the Changes tab — displays "Verified Authentic", "Tampered / Invalid", or "Integrity Disabled" badges with corresponding icons and color coding to instantly alert admins to tampered logs.
- **"Reverted" UI State Protection**: Added an `isReverted()` repository check that powers a new "REVERTED" badge on the detail page. The revert button is now dynamically disabled with an "Already Reverted" state to prevent duplicate reverts of the same log.
- **Conditional EasyAdmin Registration**: The `AuditLogCrudController` is now conditionally registered as a service only when `EasyAdminBundle` is actively installed, checked via `kernel.bundles` at compile time in `AuditTrailExtension`.

### 2.2.0 Improvements

- **Optimized `isReverted()` Query**: Replaced N+1 entity hydration with a single `COUNT` + `LIKE` query against the JSON `context` column, eliminating full result set loading for the "Reverted" UI check.
- **Keyset Pagination UUID Typing**: `afterId`/`beforeId` parameters now explicitly use the `'uuid'` Doctrine type constraint for proper cross-database UUID comparison in cursor-based pagination.
- **Soft Delete Detection Fix**: `ChangeProcessor::determineUpdateAction()` now correctly detects soft-deletes when `oldValue` is `null` and `newValue` is not (previously only detected restores).
- **Revert Subscriber Silencing**: Wrapped the entire revert operation in a `try/finally` block to ensure the `ScheduledAuditManagerInterface` subscriber is reliably re-enabled even if the revert fails mid-transaction.
- **Revert Dry-Run Associations**: `RevertValueDenormalizer` now uses `EntityManager::getReference()` instead of `find()` during dry-runs to avoid unnecessary database query overhead for associated entities.
- **Revert Access Action**: `AuditReverter::determineChanges()` now gracefully handles `ACTION_ACCESS` (read-tracking) logs by returning empty changes instead of throwing an exception.

---

## [2.1.0]

### 2.1.0 Breaking Changes

- **Transport Configuration**: The `doctrine` transport config key has been replaced with `database`. The new `database` key strictly requires an options array: `database: { enabled: true, async: false }` rather than a scalar boolean.

### 2.1.0 New Features

- **Asynchronous Database Transport**: Audit logs can now be dispatched to the database asynchronously via Symfony Messenger by configuring `database: { enabled: true, async: true }`. This utilizes a dedicated `audit_trail_database` route and a built-in `PersistAuditLogHandler` to insert the records, preventing conflict with the external `queue` transport.
- **Collection Serialization Tuning**: Introduced a tiered strategy for Doctrine collections (`lazy`, `ids_only`, `eager`) with configurable `max_collection_items` (default 100). This allows developers to balance audit detail against N+1 query safety and log bloat.

---

## [2.0.0]

This major release represents a complete architectural modernization of the bundle, leveraging **PHP 8.4** features and introducing a **Strict Contract Layer** for better extensibility and performance.

### 2.0.0 Breaking Changes

- **PHP 8.4 Required**: The bundle now requires PHP 8.4+ for property hooks, asymmetric visibility, and typed class constants.
- **Symfony 7.4+ Required**: Updated to leverage modern Symfony DI attributes and framework features.
- **AuditLog Identification**: The primary key for the `AuditLog` entity has shifted from **Integer to UUID** (`Symfony\Component\Uid\Uuid`). This requires a database migration.
- **AuditLog Constructor**: Now enforces mandatory parameters (`entityClass`, `entityId`, `action`) at instantiation time.
- **AuditLog Entity is Non-Readonly**: The entity uses `private(set)` per-property instead of a global `readonly` class, enabling the `seal()` mechanism and controlled mutability via property hooks.
- **AuditEntry Getters Removed**: All getter methods on `AuditEntry` have been replaced with read-only property hooks (e.g., `$entry->getEntityClass()` → `$entry->entityClass`).
- **AuditQuery `execute()` Removed**: Use `getResults()`, `getFirstResult()`, `count()`, or `exists()` instead.
- **Interface Segregation**: All core services now reside behind interfaces in `Rcsofttech\AuditTrailBundle\Contract`. Manual service type-hints must be updated to use these interfaces (23 contracts total).
- **Service Layer Shift**: Metadata, user context, and entity identification logic has been decoupled into specialized services (`AuditMetadataManager`, `ContextResolver`, `EntityIdResolver`).
- **Transport Interface**: `AuditTransportInterface::send()` now requires a mandatory `$context` array parameter for phase-aware dispatching (`on_flush` / `post_flush`). The `supports(string $phase, array $context)` method was added.
- **Configuration Defaults**: `audited_methods` defaults to `['GET']`. `defer_transport_until_commit` defaults to `true`. `fallback_to_database` defaults to `true`.

### 2.0.0 New Features

- **PHP 8.4 Native Features**:
  - **Property Hooks**: Used throughout `AuditLog` for real-time validation of IP addresses, action types, and entity class names directly on the entity. `AuditEntry` uses read-only property hooks for all accessors.
  - **Asymmetric Visibility**: Core properties use `public private(set)` to expose data for reading while protecting internal state.
  - **Typed Class Constants**: `AuditLogInterface` uses typed `string` and `array` constants for action names.
- **Immutable State (Sealing)**: Introduced a `seal()` mechanism in `AuditLog`. Once sealed, property hooks prevent modification of `entityId`, `context`, and `signature`, enforced even via `ReflectionProperty::setValue()`.
- **Transport Architecture**:
  - **Chain Transport**: Dispatches audit logs to multiple transports in sequence.
  - **Doctrine Transport**: Persists audit logs directly to the database.
  - **HTTP Transport**: Sends audit logs to an external HTTP endpoint with configurable timeout and headers.
  - **Queue Transport**: Dispatches audit logs via Symfony Messenger for async processing. Supports custom stamps via `AuditMessageStampEvent` and propagation cancellation.
- **Event System**:
  - **`AuditLogCreatedEvent`**: Dispatched when an audit log is created, allowing listeners to add metadata, filter, or modify logs before persistence and signing.
  - **`AuditMessageStampEvent`**: Dispatched before queue transport dispatch, allowing custom Messenger stamps or cancellation via `stopPropagation()`.
- **Modular Data Masking**: Introduced `DataMaskerInterface` with a default `DataMasker` that auto-redacts common sensitive keys (`password`, `token`, `secret`, `api_key`, `cookie`, etc.) case-insensitively, including nested arrays.
- **`#[Sensitive]` Attribute**: Mark entity properties with `#[Sensitive(mask: '***')]` to automatically mask values in audit logs. Also supports `#[SensitiveParameter]` on promoted constructor parameters.
- **`#[AuditCondition]` Attribute**: Conditional auditing using Symfony Expression Language with a security sandbox (`ExpressionLanguageVoter`) that blocks dangerous functions (`system`, `exec`, `passthru`, `constant`, etc.).
- **`#[AuditAccess]` Attribute**: Configurable read-access auditing with HTTP method filtering via `audited_methods`.
- **Audit Reverter**: Full revert support for `create`, `update`, and `soft_delete` actions with dry-run mode, `--force` flag, and automatic signature verification before revert.
- **Fluent Query API**: Rebuilt `AuditQuery` as an immutable, fluent query builder with `entity()`, `action()`, `user()`, `transaction()`, `since()`, `until()`, `between()`, `changedField()`, `creates()`, `updates()`, `deletes()`, `after()`, `before()`, and keyset (cursor) pagination.
- **`AuditEntryCollection`**: Rich collection wrapper with `groupByEntity()`, `groupByTransaction()`, timeline helpers, and iteration support.
- **Context Contributors**: Implement `AuditContextContributorInterface` to inject custom context data into every audit log. Auto-discovered via DI tag `audit_trail.context_contributor`.
- **Impersonation Tracking**: `ContextResolver` automatically captures impersonator ID and username when Symfony's switch-user feature is active.
- **CLI Commands** (7 total):
  - `audit:list` — Browse and filter audit logs.
  - `audit:diff` — Show field-level changes for a specific audit log.
  - `audit:export` — Export audit logs to CSV or JSON.
  - `audit:purge` — Purge audit logs by retention policy or entity class.
  - `audit:revert` — Revert an entity to a previous state from an audit log.
  - `audit:verify-integrity` — Verify HMAC signatures across audit logs.

### Performance & Scale

- **Smart Flush Detection**: `EntityProcessor` eliminates redundant database flushes by detecting UUID ID generation strategies, enabling a **Single Flush** cycle.
- **N+1 Prevention**: `ValueSerializer` checks Doctrine's `PersistentCollection::isInitialized()` state, preventing accidental lazy-loading storms.
- **Serialization Depth Limit**: `ValueSerializer` enforces a maximum serialization depth of 5 levels to prevent circular reference DoS and excessive data logging.
- **Collection Item Limit**: `ValueSerializer` caps collection serialization at 100 items to prevent memory exhaustion.
- **Lazy Execution**: `AuditReader`, `AuditRenderer`, and `AuditExporter` are marked as `lazy` in the DI container.
- **Log Storm Protection**: `AuditAccessHandler` implements request-scoped caching to prevent redundant log generation during batch `postLoad` events.
- **Table Prefix/Suffix**: `TablePrefixSubscriber` supports configurable audit table naming.

### Security Hardening

- **Canonical HMAC Signing**: Strict canonical normalization layer with sorted, type-prefixed payloads and configurable algorithm (`sha256`, `sha384`, `sha512`). Signatures include `createdAt`, `transactionHash`, `ipAddress`, `userAgent`, and `context` to prevent replay attacks and metadata tampering.
- **Terminal Injection Protection**: `AuditRenderer` strips ANSI escape sequences from all rendered values.
- **Expression Language Sandbox**: `ExpressionLanguageVoter` blacklists dangerous function calls (`system`, `exec`, `passthru`, `shell_exec`, `popen`, `proc_open`, `constant`) and restricts available variables to a predefined whitelist.
- **IP Address Validation**: `AuditLog` property hook validates IP addresses via `filter_var(FILTER_VALIDATE_IP)` at write time.
- **Action Validation**: `AuditLog` property hook validates action strings against `AuditLogInterface::ALL_ACTIONS` at write time.
- **Entity Class Validation**: `AuditLog` property hook trims and rejects empty entity class names.
- **Context Truncation**: Strict 32KB byte-limit enforcement for JSON context data.
- **Parameterized Repository Queries**: `AuditLogRepository::findWithFilters()` uses parameterized queries to prevent SQL injection.

---
