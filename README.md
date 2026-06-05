# AuditTrailBundle

[![CI](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml)
[![Version](https://img.shields.io/packagist/v/rcsofttech/audit-trail-bundle.svg?label=stable)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![Downloads](https://img.shields.io/packagist/dt/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![License](https://img.shields.io/github/license/rcsofttech85/AuditTrailBundle)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/4737e92c64cc4e63b781016efeb48a99)](https://app.codacy.com/gh/rcsofttech85/AuditTrailBundle/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Codacy Badge](https://app.codacy.com/project/badge/Coverage/4737e92c64cc4e63b781016efeb48a99)](https://app.codacy.com/gh/rcsofttech85/AuditTrailBundle/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_coverage)
[![Mutation Testing](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Frcsofttech85%2FAuditTrailBundle%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/rcsofttech85/AuditTrailBundle/main)

AuditTrailBundle records Doctrine ORM entity changes in Symfony applications. It captures changes during Doctrine flush, stores audit logs, supports multiple delivery transports, and provides tools for integrity verification, review, export, and recovery.

## Documentation

Full documentation now lives on GitHub Pages:

- **Current docs:** [AuditTrailBundle 4.x documentation](https://rcsofttech85.github.io/audit-trail-bundle/docs/4.x/)
- **Configuration:** [configuration reference](https://rcsofttech85.github.io/audit-trail-bundle/docs/4.x/configuration/)
- **Transports:** [database, async database, queue, HTTP, and chain](https://rcsofttech85.github.io/audit-trail-bundle/docs/4.x/transports/)
- **Usage & AuditReader:** [attributes, context, events, and query API](https://rcsofttech85.github.io/audit-trail-bundle/docs/4.x/usage/)
- **Operations:** [revert, CLI, exports, and serialization](https://rcsofttech85.github.io/audit-trail-bundle/docs/4.x/operations/)
- **Upgrade & Architecture:** [upgrade guides and architecture notes](https://rcsofttech85.github.io/audit-trail-bundle/docs/4.x/upgrade-architecture/)

For older versions, use the README and docs from the matching GitHub tag unless archived public docs are added later.

## Features

- Doctrine entity audit logs for create, update, delete, soft-delete, restore, access, and revert flows
- Split-phase architecture that avoids nested Doctrine `flush()` calls
- Database, async database, queue, HTTP, and chain-style delivery options
- PHP attributes for `#[Auditable]`, `#[AuditCondition]`, `#[AuditAccess]`, and `#[Sensitive]`
- Sensitive data masking with `#[SensitiveParameter]` and bundle-level sensitive fields
- AuditReader API for history, filtering, diffs, changed fields, pagination, and existence checks
- EasyAdmin integration, Symfony profiler support, CLI commands, exports, and revert tooling
- HMAC signing for stored audit logs and transport payloads
- Extension points for voters, context contributors, transports, AI metadata, and revert handlers

## Quick Start

### Install

```bash
composer require rcsofttech/audit-trail-bundle
```

Optional packages depend on the features you enable:

```bash
composer require symfony/messenger          # async database or queue transport
composer require symfony/http-client       # HTTP transport
composer require easycorp/easyadmin-bundle # EasyAdmin dashboard
```

### Prepare The Database

The database transport is enabled by default. Generate and run a Doctrine migration for the audit log table:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

### Mark An Entity As Auditable

The example uses PHP 8.4 asymmetric property visibility (`public private(set)`) and constructor property promotion for normal entity fields. Keep the generated ID outside the constructor so callers cannot pass it accidentally.

```php
<?php

declare(strict_types=1);

namespace App\Entity;

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

    public function __construct(
        #[ORM\Column(length: 180)]
        public private(set) string $name,

        #[ORM\Column]
        public private(set) int $priceInCents,

        #[ORM\Column(length: 40, nullable: true)]
        public private(set) ?string $internalCode = null,
    ) {
    }
}
```

### Minimal Configuration

```yaml
# config/packages/audit_trail.yaml
audit_trail:
    enabled: true
    ignored_properties: ['updatedAt', 'updated_at']
    retention_days: 365
    transports:
        database:
            enabled: true
            async: false
```

## Requirements

- PHP 8.4+
- Symfony 7.4 or 8.0
- Doctrine ORM 3.6+
- DoctrineBundle 3.1+
- `ext-mbstring`

## Links

- **Packagist:** [rcsofttech/audit-trail-bundle](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
- **Issues:** [GitHub issue tracker](https://github.com/rcsofttech85/AuditTrailBundle/issues)
- **Changelog:** [CHANGELOG.md](https://github.com/rcsofttech85/AuditTrailBundle/blob/main/CHANGELOG.md)
- **Contributing:** [CONTRIBUTING.md](https://github.com/rcsofttech85/AuditTrailBundle/blob/main/CONTRIBUTING.md)

## License

MIT License.
