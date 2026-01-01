# AuditTrailBundle

[![CI](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml)
[![Latest Stable Version](https://img.shields.io/packagist/v/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
![License](https://img.shields.io/github/license/rcsofttech85/AuditTrailBundle)
[![Total Downloads](https://img.shields.io/packagist/dt/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/38d81ef3b38d4ea3976f5eb12c98e112)](https://app.codacy.com/gh/rcsofttech85/AuditTrailBundle/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)

**Enterprise-grade, high-performance audit trail solution for Symfony.**

AuditTrailBundle is a modern, lightweight bundle that automatically tracks and stores Doctrine ORM entity changes. Built for performance and compliance, it uses a unique **Split-Phase Architecture** to ensure your application stays fast even under heavy load.

---

## Key Features

- **High Performance**: Non-blocking audits using a **Split-Phase Architecture** (capture in `onFlush`, dispatch in `postFlush`).
- **Multiple Transports**:
  - **Doctrine**: Store logs in your local database (default).
  - **HTTP**: Stream logs to external APIs (ELK, Splunk, etc.).
  - **Queue**: Offload processing to **Symfony Messenger** (RabbitMQ, Redis).
- **Deep Collection Tracking**: Tracks Many-to-Many and One-to-Many changes with precision (logs exact IDs).
- **Sensitive Data Masking**: Native support for `#[SensitiveParameter]` and custom `#[Sensitive]` attributes for **GDPR compliance**.
- **Safe Revert Support**: Easily roll back entities to any point in history, including associations.
- **Modern Stack**: Built for **PHP 8.4+**, **Symfony 7.4+**, and **Doctrine ORM 3.0+**.

---

## Why AuditTrailBundle?

Most Symfony audit solutions either:

- Slow down Doctrine flush operations
- Log incomplete transactional data
- Cannot safely revert changes
- Are hard to extend beyond database storage

AuditTrailBundle is designed for **production-grade auditing** with:

- **Minimal write overhead**: Split-phase architecture ensures your app stays fast.
- **Transaction-aware logging**: Group related changes under a single transaction hash.
- **Safe revert support**: Easily roll back entities to any point in history, including associations.
- **Multiple transport strategies**: Store logs in DB, send to an API, or offload to a Queue (Messenger).

---

## Architecture

This bundle is built using a **Split-Phase Audit Architecture** to ensure high performance and reliability in Symfony applications.

1. **Phase 1 (Capture)**: Listens to Doctrine `onFlush` to capture changes without slowing down the transaction.
2. **Phase 2 (Dispatch)**: Dispatches audits in `postFlush` via your chosen transport.

For a deep dive into the design decisions and how the split-phase approach works, check out the full article on Medium:
[Designing a Split-Phase Audit Architecture for Symfony](https://medium.com/@rcsofttech85/designing-a-split-phase-audit-architecture-for-symfony-f4ff532491dc)

---

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

    #[ORM\Column]
    private ?string $internalCode = null;
}
```

---

## Configuration

Create a configuration file at `config/packages/audit_trail.yaml`:

```yaml
audit_trail:
    # Enable or disable the bundle globally
    enabled: true

    # Global list of properties to ignore (e.g., timestamps)
    ignored_properties: ['updatedAt', 'updated_at', 'password', 'token']

    # Global list of entities to ignore
    ignored_entities: []

    # Retention period for database logs (in days)
    retention_days: 365

    # Context tracking
    track_ip_address: true
    track_user_agent: true

    # enable or disable delete tracking
    enable_hard_delete: true
    enable_soft_delete: true
    soft_delete_field: 'deletedAt'

    transports:
        # Store logs in the local database
        doctrine: true

        # Send logs to an external API
        http:
            enabled: false
            endpoint: 'https://audit-service.internal/api/logs'

        # Dispatch logs to a message queue
        queue:
            enabled: false
            bus: 'messenger.bus.default' # Optional: specify a custom bus

    # Transaction Safety & Performance
    # --------------------------------
    # true (Default): Audits are sent AFTER the transaction commits (postFlush).
    #   - Pros: High performance, non-blocking. Main transaction succeeds even if audit fails.
    #   - Cons: Small risk of "data without audit" if app crashes between commit and audit.
    #   - Recommended for: External transports (HTTP, Queue).
    #
    # false: Audits are sent DURING the transaction (onFlush).
    #   - Pros: Strict atomicity. Data and audit are committed together.
    #   - Cons: Slower. If audit transport fails, the entire transaction rolls back.
    #   - Recommended for: Doctrine transport (when strict compliance is required).
    defer_transport_until_commit: true

    # If true, an exception in the transport will stop execution (and rollback if defer=false).
    # If false (default), transport errors are logged but execution continues.
    fail_on_transport_error: false
```

---

## Detailed Usage

### 1. Granular Control

You can ignore specific properties or enable/disable auditing per entity via the class-level attribute.

```php
#[Auditable(enabled: true, ignoredProperties: ['internalCode'])]
class Product
{
    private ?string $internalCode = null;
}
```

### 2. Masking Sensitive Fields

Sensitive data is automatically masked in audit logs.

**Option 1: Use PHP's `#[SensitiveParameter]`**

```php
public function __construct(
    #[\SensitiveParameter] private string $password,
) {}
```

**Option 2: Use `#[Sensitive]`**

```php
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;

#[Sensitive(mask: '****')]
private string $ssn;
```

### 3. Programmatic Audit Retrieval (Reader / Query API)

Read and query audit logs programmatically using a dedicated, read-only API.

→ [Audit Reader Documentation (Symfony & Doctrine)](docs/symfony-audit-reader.md)

---

## CLI Commands

The bundle provides several commands for managing audit logs.

### List Audit Logs

```bash
php bin/console audit:list --entity=User --action=update --limit=50
```

### Purge Old Logs

```bash
php bin/console audit:purge --before="30 days ago" --force
```

### Export Logs

```bash
php bin/console audit:export --format=json --output=audits.json
```

### View Diff

```bash
php bin/console audit:diff User 42
```

### Revert Entity Changes

AuditTrailBundle provides a powerful **Point-in-Time Restore** capability, allowing you to undo accidental changes or recover data from any point in your audit history.

```bash
# Revert an entity to its state in a specific audit log
php bin/console audit:revert 123
```

**Why it's "Safe":**

- **Association Awareness**: Automatically handles entity relations and collections.
- **Soft-Delete Support**: Temporarily restores soft-deleted entities to apply the revert.
- **Dry Run Mode**: Preview exactly what will change before applying (`--dry-run`).
- **Data Integrity**: Ensures the entity remains in a valid state after the rollback.

> [!TIP]
> Use the revert feature for **emergency data recovery**, **undoing unauthorized changes**, or **restoring accidental deletions** with full confidence.

### Verify Audit Log Integrity

Ensure the integrity of your audit logs by detecting any unauthorized tampering or modifications. This command validates cryptographic hashes to identify compromised records.

```bash
php bin/console audit:verify-integrity
```

**Use Cases:**

- **Compliance Audits**: Verify that audit logs haven't been altered for regulatory compliance (SOX, HIPAA, GDPR).
- **Security Monitoring**: Detect unauthorized tampering after security incidents.
- **Historical Data Verification**: Confirm past records are accurate and trustworthy.

**Example Output:**

![Audit Integrity Verification](.github/assets/audit_integrity_check.png)

> [!WARNING]
> Any tampered logs indicate a serious security breach. Investigate immediately and review access controls to your database.

---

## Integrations

### EasyAdmin Integration

Add the `AuditLogCrudController` to your `DashboardController`:

```php
use Rcsofttech\AuditTrailBundle\Controller\Admin\AuditLogCrudController;

yield MenuItem::linkToCrud('Audit Logs', 'fas fa-history', AuditLog::class)
    ->setController(AuditLogCrudController::class);
```

---

## Benchmarks

| Operation | Time (mode) | Memory (peak) |
| :--- | :--- | :--- |
| **Audit Creation (Overhead)** | 1.66ms / flush | 11.25 MB |
| **Baseline (Auditing Disabled)** | 0.68ms / flush | 10.41 MB |
| **Audit Retrieval (10 logs)** | 5.60ms | 12.86 MB |
| **Audit Purge (1000 logs)** | 44.14ms | 21.79 MB |

[View full benchmark report](docs/audit-log-benchmark.md)

---

## Requirements

- PHP 8.4+
- Symfony 7.4+
- Doctrine ORM 3.0+

---

## License

MIT License.

---
