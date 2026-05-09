# AuditTrailBundle

[![CI](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml)
[![Version](https://img.shields.io/packagist/v/rcsofttech/audit-trail-bundle.svg?label=stable)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![Downloads](https://img.shields.io/packagist/dt/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![License](https://img.shields.io/github/license/rcsofttech85/AuditTrailBundle)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/4737e92c64cc4e63b781016efeb48a99)](https://app.codacy.com/gh/rcsofttech85/AuditTrailBundle/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Codacy Badge](https://app.codacy.com/project/badge/Coverage/4737e92c64cc4e63b781016efeb48a99)](https://app.codacy.com/gh/rcsofttech85/AuditTrailBundle/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_coverage)
[![Mutation Testing](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Frcsofttech85%2FAuditTrailBundle%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/rcsofttech85/AuditTrailBundle/main)

AuditTrailBundle records Doctrine ORM entity changes and stores audit logs.
By default it captures changes during flush and sends audits after `postFlush`
so normal writes stay fast. If you need stricter rules, you can also keep
audit delivery inside the transaction.

---

## Why AuditTrailBundle?

Many audit bundles do everything in one step. That can slow down writes.
AuditTrailBundle splits capture, delivery, and persistence so it fits Doctrine
flush rules better and keeps normal writes lighter.

### Split-Phase Architecture

```text
  Application       Doctrine ORM       AuditTrailBundle       Queue / Storage
       |                  |                    |                     |
       | flush()          |                    |                     |
       |----------------->|                    |                     |
       |                  | onFlush            |                     |
       |                  |------------------->|                     |
       |                  |                    | Compute Diffs       |
       |                  |                    | Persist ORM-safe    |
       |                  |                    | audit rows when     |
       |                  |                    | allowed in-UoW      |
       |                  |<-------------------|                     |
       |                  |                    |                     |
       |                  | Execute SQL        |                     |
       |                  | (Transaction)      |                     |
       |                  |                    |                     |
       |                  | postFlush          |                     |
       |                  |------------------->|                     |
       |                  |                    | Dispatch Audit      |
       |                  |                    | Persist deferred    |
       |                  |                    | database audits via |
       |                  |                    | direct writer       |
       |                  |                    |-------------------->|
       | flush() returns  |                    |                     |
       |<-----------------|                    |                     |
                                                                     | Async Save
```

- **Deferred by default**: The bundle captures changes during flush and sends audits after `postFlush` unless you choose stricter behavior.
- **Safe with Doctrine**: It does not call `flush()` from inside Doctrine `postFlush`. Deferred database writes use a separate writer.
- **Integrity support**: You can sign audit logs and transport payloads.
- **Simple setup**: Use PHP 8 attributes and normal Symfony services.

### Key Features

- Deferred audit delivery by default
- Database, HTTP, and queue transports
- Tracking for to-many collection changes
- Sensitive data masking
- Revert support
- Access audit support for reads
- Conditional auditing
- Request and user context tracking
- Optional AI metadata hooks
- Symfony profiler support

---

## Admin UI

The bundle includes EasyAdmin support for browsing and reviewing audit logs.

![EasyAdmin Integration Showcase](.github/assets/easyadmin_integration_dark.png)

---

## Security & Compliance

The bundle can also help with audit integrity and review.

- **Sensitive Data Masking**: Native support for `#[SensitiveParameter]` on promoted constructor parameters and custom `#[Sensitive]` attributes.
- **HMAC Signatures**: Audit logs can be signed so tampering can be detected during verification.
- **Integrity Verification**: Command-line tools to audit your audit logs.

![Integrity Check CLI](.github/assets/audit_integrity_check.png)

---

## Documentation

| Topic | Description |
| :--- | :--- |
| **[Installation & Setup](README.md#quick-start)** | Basic setup. |
| **[Configuration](docs/configuration.md)** | Full configuration reference (`enabled`, `transports`, `integrity`, access auditing, export limits, queue limits, collection serialization). |
| **[Advanced Usage](docs/advanced-usage.md)** | Attributes, Conditional Auditing, Impersonation, Custom Context. |
| **[Architecture](docs/architecture.md)** | A short map of the main runtime pieces and extension points. |
| **[Transports](docs/symfony-audit-transports.md)** | Doctrine, HTTP, and Queue (Messenger) transport details. |
| **[Audit Reader](docs/symfony-audit-reader.md)** | Querying audit logs programmatically. |
| **[Revert & Recovery](docs/revert-feature.md)** | How revert works. |
| **[Security & Integrity](docs/security-integrity.md)** | Data masking, cryptographic signing, and verification. |
| **[CLI Commands](docs/cli-commands.md)** | Console commands for listing, purging, and exporting logs. |
| **[Integrations](docs/integrations.md)** | EasyAdmin and Symfony Profiler support. |
| **[Serialization](docs/serialization.md)** | JSON payload shape. |
| **[Failure & Transaction Safety](docs/configuration.md#transaction-safety-guide)** | Recommended settings for `defer_transport_until_commit`, fallback, and transport errors. |

---

## Quick Start

### 1. Installation

```bash
composer require rcsofttech/audit-trail-bundle
```

Transport-specific packages are optional:

- Database transport in synchronous mode: no extra package required
- Async database transport or queue transport: `composer require symfony/messenger`
- HTTP transport: `composer require symfony/http-client`
- EasyAdmin dashboard: `composer require easycorp/easyadmin-bundle`

### 2. Database Setup (Doctrine Transport)

If you are using the **Doctrine Transport** (default), update your database schema:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

### 3. Basic Usage

Add the `#[Auditable]` attribute to any Doctrine entity you want to track.

```php
<?php

declare(strict_types=1);

use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[ORM\Entity]
#[Auditable(ignoredProperties: ['internalCode'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column]
    public private(set) string $name;
}
```

The default transport stores audit logs in the database. If you enable integrity signing, make sure `audit_trail.integrity.secret` is configured.

If you enable a transport without its supporting package installed, the bundle fails fast during container build with a clear `LogicException`.

### 4. Requirements

- **PHP**: 8.4+
- **Symfony**: 7.4+ or 8.0+
- **Doctrine ORM**: 3.6+

### 5. Operational Defaults

If you are choosing settings for production:

- Use `defer_transport_until_commit: true` for HTTP or queue-first setups where application write latency matters most.
- HTTP and queue transports remain deferred-phase transports; setting `defer_transport_until_commit: false` does not move them into Doctrine's `onFlush` transaction window.
- If you enable HTTP or queue transport and leave the failure knobs unset, the bundle defaults to stricter remote handling: `fail_on_transport_error: true` and `fallback_to_database: false`. Override them if you want softer behavior or a local safety net.
- Use synchronous database transport with `fail_on_transport_error: true` when the write and audit must succeed or fail together.
- Keep `fallback_to_database: true` when you want external transport failures to still leave a local audit trail.
- Configure a PSR-6 `cache_pool` if you rely on cross-request access-audit cooldowns.

Feature dependency notes:

- `database.async: true` requires `symfony/messenger` and a Messenger transport named `audit_trail_database`
- `queue.enabled: true` requires `symfony/messenger`
- `http.enabled: true` requires `symfony/http-client`
- EasyAdmin integration is registered only when `EasyAdminBundle` is enabled

Operational notes:

- Audit query limits must be positive integers. `AuditReader`, `AuditQuery`, and the repository reject `0` or negative limits.
- Cursor pagination uses audit-log UUIDs. Invalid cursors are rejected.
- When you use the bundle's container-managed services, audit-log row IDs stay
  UUID v7 even if the host application changes Symfony's global default UUID
  version for unrelated identifiers.
- Async database persistence preserves the original audit-log UUID across Messenger dispatch and worker persistence, so UUID cursor ordering and transaction drilldowns stay stable even if workers consume messages out of order.
- `AuditReader` / `AuditQuery` `changedField()` uses database JSON checks on MySQL, PostgreSQL, and SQLite. On other platforms it falls back to batched in-memory matching.
- When you use object-based lookups or `ignored_entities`, configure the real mapped class such as `App\Entity\Order`. The bundle maps proxies and lazy subclasses back to that class.
- EasyAdmin transaction drill-down accepts only one cursor at a time: `afterId` or `beforeId`, never both.
- CLI audit IP handling is conservative. In console use, the bundle prefers values such as `AUDIT_TRAIL_CLI_IP`, `SSH_CLIENT`, or `SSH_CONNECTION`. If no valid IP is available, it stores `null`.
- When an entity changes scalar fields and Doctrine collections in the same flush, the bundle records one merged `update` audit instead of redundant separate entries.
- EasyAdmin revert previews handle UUID-backed relations and to-many collections safely, and restored collection values are shown in a readable format.
- The built-in soft-delete restore flow clears `soft_delete_field` by setting it back to `null`, so it should point to a nullable timestamp-like field such as `deletedAt` or `archivedAt`. Boolean or status-based soft-delete markers need custom restore handling.
- Collection audits caused by deleting a related entity are most reliable with bidirectional Doctrine associations. With unidirectional mappings, the bundle may not have enough reverse relation context to emit an owner-side collection update.

---

## Community & Support

- **Bugs & Features**: Please use the [GitHub Issue Tracker](https://github.com/rcsofttech85/AuditTrailBundle/issues).
- **Contributing**: Check out our [Contributing Guide](https://github.com/rcsofttech85/AuditTrailBundle/blob/main/CONTRIBUTING.md).

## License

MIT License.
