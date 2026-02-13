# AuditTrailBundle

[![CI](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml)
[![Version](https://img.shields.io/packagist/v/rcsofttech/audit-trail-bundle.svg?label=stable)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![Downloads](https://img.shields.io/packagist/dt/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![License](https://img.shields.io/github/license/rcsofttech85/AuditTrailBundle)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/4737e92c64cc4e63b781016efeb48a99)](https://app.codacy.com/gh/rcsofttech85/AuditTrailBundle/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Codacy Badge](https://app.codacy.com/project/badge/Coverage/4737e92c64cc4e63b781016efeb48a99)](https://app.codacy.com/gh/rcsofttech85/AuditTrailBundle/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_coverage)
[![Mutation Testing](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Frcsofttech85%2FAuditTrailBundle%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/rcsofttech85/AuditTrailBundle/main)

**Enterprise-grade, high-performance audit trail solution for Symfony.**

AuditTrailBundle is a modern, lightweight bundle that automatically tracks and stores Doctrine ORM entity changes. Built for performance and compliance, it uses a unique **Split-Phase Architecture** to ensure your application stays fast even under heavy load.

---

## Documentation

| Topic | Description |
| :--- | :--- |
| **[Installation & Setup](README.md#quick-start)** | Getting started guide. |
| **[Configuration](docs/configuration.md)** | Full configuration reference (`enabled`, `transports`, `integrity`). |
| **[Advanced Usage](docs/advanced-usage.md)** | Attributes, Conditional Auditing, Impersonation, Custom Context. |
| **[Transports](docs/symfony-audit-transports.md)** | Doctrine, HTTP, and Queue (Messenger) transport details. |
| **[Audit Reader](docs/symfony-audit-reader.md)** | Querying audit logs programmatically. |
| **[Revert & Recovery](docs/revert-feature.md)** | Point-in-time restoration of entities. |
| **[Security & Integrity](docs/security-integrity.md)** | Data masking, cryptographic signing, and verification. |
| **[CLI Commands](docs/cli-commands.md)** | Console commands for listing, purging, and exporting logs. |
| **[Integrations](docs/integrations.md)** | EasyAdmin support. |
| **[Serialization](docs/serialization.md)** | Cross-platform JSON format. |
| **[Benchmarks](docs/audit-log-benchmark.md)** | Performance report. |

---

## Key Features

- **High Performance**: Non-blocking audits using a **Split-Phase Architecture** (capture in `onFlush`, dispatch in `postFlush`).
- **Multiple Transports**: Doctrine, HTTP (ELK/Splunk), Queue (RabbitMQ/Redis).
- **Deep Collection Tracking**: Tracks Many-to-Many and One-to-Many changes with precision.
- **Sensitive Data Masking**: Native support for `#[SensitiveParameter]` and custom `#[Sensitive]` attributes.
- **Safe Revert Support**: Easily roll back entities to any point in history.
- **Conditional Auditing**: Skip logs based on runtime conditions.
- **Access Auditing**: Track entity read operations (GET requests) with high-performance caching and configurable cooldowns.
- **Rich Context**: Tracks IP, User Agent, Impersonation, and custom metadata.

## Quick Start

### 1. Installation

```bash
composer require rcsofttech/audit-trail-bundle
```

### 2. Database Setup (Doctrine Transport)

If you are using the **Doctrine Transport** (default), update your database schema:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

### 3. Basic Usage

Add the `#[Auditable]` attribute to any Doctrine entity you want to track.

```php
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[ORM\Entity]
#[Auditable(ignoredProperties: ['internalCode'])]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $name;
}
```

### 4. Access Auditing (Read Tracking)

To track when an entity is accessed (read), use the `#[AuditAccess]` attribute. This feature is strictly optimized for **GET requests** to minimize overhead.

```php
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;

#[ORM\Entity]
#[AuditAccess(cooldown: 3600, level: 'info', message: 'User accessed sensitive record')]
class SensitiveDocument
{
    // ...
}
```

#### Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `cooldown` | `int` | `0` | Prevent duplicate logs for the same user/entity within X seconds (requires PSR-6 cache). |
| `level` | `string` | `'info'` | The log level for the access audit. |
| `message` | `string?` | `null` | A custom message to include in the audit log. |

> [!NOTE]
> `#[AuditAccess]` does not require `#[Auditable]` — they are independent attributes.
> However, if `#[AuditCondition]` is present on the same entity, it **is** respected for access logs.
> The expression receives `action = "access"` for fine-grained control.

#### Cache Configuration

To use the `cooldown` feature, you must specify a PSR-6 cache pool in your configuration:

```yaml
# config/packages/audit_trail.yaml
audit_trail:
    cache_pool: 'cache.app' # Use any available PSR-6 cache pool
```

---

## Requirements

- PHP 8.4+
- Symfony 7.4+
- Doctrine ORM 3.0+

## License

MIT License.
