# Changelog

All notable changes to the **AuditTrailBundle** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0]

This major release represents a complete architectural modernization of the bundle, leveraging **PHP 8.4** features and introducing a **Strict Contract Layer** for better extensibility and performance.

### Breaking Changes

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

### New Features

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
