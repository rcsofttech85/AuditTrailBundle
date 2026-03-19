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

## Why AuditTrailBundle?

Most audit bundles capture changes synchronously, which can significantly slow down your application's write performance. AuditTrailBundle solves this by separating the **Capture** and **Persistence** phases.

### Split-Phase Architecture

```text
  Application       Doctrine ORM       AuditTrailBundle       Queue / Storage
       |                  |                    |                     |
       | flush()          |                    |                     |
       |----------------->|                    |                     |
       |                  | onFlush (Capture)  |                     |
       |                  |------------------->|                     |
       |                  |                    | Compute Diffs       |
       |                  |                    | Cache Payload       |
       |                  |<-------------------|                     |
       |                  |                    |                     |
       |                  | Execute SQL        |                     |
       |                  | (Transaction)      |                     |
       |                  |                    |                     |
       |                  | postFlush          |                     |
       |                  |------------------->|                     |
       |                  |                    | Dispatch Audit      |
       |                  |                    |-------------------->|
       | flush() returns  |                    |                     |
       |<-----------------|                    |                     |
                                                                     | Async Save
```

- **Non-blocking**: Audit capture happens during the flush, but storage is offloaded to a background process.
- **Data Integrity**: Cryptographic signing ensures logs cannot be tampered with.
- **Developer First**: Simple PHP 8 attributes, zero boilerplate.

### Key Features

- **High Performance**: Non-blocking audits using a **Split-Phase Architecture** (capture in `onFlush`, dispatch in `postFlush`).
- **Multiple Transports**: Doctrine (Database), HTTP (ELK/Splunk), and Queue (RabbitMQ/Redis/Messenger).
- **Deep Collection Tracking**: Tracks Many-to-Many and One-to-Many changes with precision.
- **Sensitive Data Masking**: Native support for `#[SensitiveParameter]` and custom `#[Sensitive]` attributes.
- **Safe Revert Support**: Easily roll back entities to any point in history.
- **Access Auditing**: Track sensitive entity read operations (GET requests) with configurable cooldowns.
- **Conditional Auditing**: Skip logs based on runtime conditions or Expressions.
- **Rich Context**: Automatically tracks IP, User Agent, Impersonation, and custom metadata.
- **Web Profiler Integration**: Real-time audit log visibility in the Symfony debug toolbar and profiler panel.

---

## Enterprise-Ready UI

Native integration with **EasyAdmin** provides a professional dashboard for your audit logs out of the box.

![EasyAdmin Integration Showcase](.github/assets/easyadmin_integration_dark.png)

---

## Security & Compliance

Track not just what changed, but who did it and where they were.

- **Sensitive Data Masking**: Native support for `#[SensitiveParameter]` and custom `#[Sensitive]` attributes.
- **HMAC Signatures**: Every audit log is signed to prevent database tampering.
- **Integrity Verification**: Command-line tools to audit your audit logs.

![Integrity Check CLI](.github/assets/audit_integrity_check.png)

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
| **[Integrations](docs/integrations.md)** | EasyAdmin and Symfony Profiler support. |
| **[Serialization](docs/serialization.md)** | Cross-platform JSON format. |
| **[Benchmarks](docs/audit-log-benchmark.md)** | Performance report. |

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
<?php

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

### 4. Requirements

- **PHP**: 8.4+
- **Symfony**: 7.4+ or 8.0+
- **Doctrine ORM**: 3.0+

---

## Community & Support

- **Bugs & Features**: Please use the [GitHub Issue Tracker](https://github.com/rcsofttech85/AuditTrailBundle/issues).
- **Contributing**: Check out our [Contributing Guide](https://github.com/rcsofttech85/AuditTrailBundle/blob/main/CONTRIBUTING.md).

## License

MIT License.
