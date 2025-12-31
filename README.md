# AuditTrailBundle

[![CI](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml)
[![Latest Stable Version](https://img.shields.io/packagist/v/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
![License](https://img.shields.io/github/license/rcsofttech85/AuditTrailBundle)
[![Total Downloads](https://img.shields.io/packagist/dt/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/38d81ef3b38d4ea3976f5eb12c98e112)](https://app.codacy.com/gh/rcsofttech85/AuditTrailBundle/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)

A lightweight, high-performance Symfony bundle that automatically tracks and stores Doctrine ORM entity changes for audit logging and compliance.

## Architecture

This bundle is built using a **Split-Phase Audit Architecture** to ensure high performance and reliability in Symfony applications.
For a deep dive into the design decisions and how the split-phase approach works, check out the full article on Medium:
 [Designing a Split-Phase Audit Architecture for Symfony](https://medium.com/@rcsofttech85/designing-a-split-phase-audit-architecture-for-symfony-f4ff532491dc)

## Features

- **Automatic Tracking**: Listens to Doctrine `onFlush` and `postFlush` events to capture inserts, updates, and deletions.
- **Zero Configuration**: Works out of the box with sensible defaults.
- **Sensitive Field Masking**: Automatically redacts fields marked with `#[SensitiveParameter]` or `#[Sensitive]`.
- **Multiple Transports**:
  - **Doctrine**: Store audit logs directly in your database (default).
  - **HTTP**: Send audit logs to an external API.
  - **Queue**: Dispatch audit logs via Symfony Messenger for async processing.
  - **Chain**: Use multiple transports simultaneously.
- **User Context**: Automatically captures the current user, IP address, and User Agent.
- **Granular Control**: Use the `#[Auditable]` attribute to enable/disable auditing per entity and ignore specific properties.
- **Modern PHP**: Built for PHP 8.4+ using strict types, readonly classes, and asymmetric visibility.

## Requirements

- PHP 8.4+
- Symfony 7.4+
- Doctrine ORM 3.0+

## Installation

1. **Install the bundle via Composer:**

    ```bash
    composer require rcsofttech/audit-trail-bundle
    ```

2. **If you are using the **Doctrine Transport** (default), update your database schema:**

    ```bash
    php bin/console make:migration
    php bin/console doctrine:migrations:migrate
    ```

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

## Usage

### 1. Mark Entities as Auditable

Add the `#[Auditable]` attribute to any Doctrine entity you want to track.

```php
use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[ORM\Entity]
#[Auditable(enabled: true)] // <--- Add this
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $name;

    #[ORM\Column]
    #[Auditable(ignore: true)] // <--- Ignore specific properties
    private ?string $internalCode = null;

    // ...
}
```

### 2. Masking Sensitive Fields

Sensitive data is automatically masked in audit logs. The bundle supports two approaches:

**Option 1: Use PHP's `#[SensitiveParameter]`** (for constructor-promoted properties)

```php
#[Auditable]
class User
{
    public function __construct(
        private string $email,
        #[\SensitiveParameter] private string $password,  // Masked as "**REDACTED**"
    ) {}
}
```

**Option 2: Use `#[Sensitive]`** (for any property, with custom mask)

```php
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;

#[Auditable]
class User
{
    #[Sensitive]  // Masked as "**REDACTED**"
    private string $apiKey;

    #[Sensitive(mask: '****')]  // Custom mask
    private string $ssn;
}
```

### 3. Viewing Audit Logs

If using the **Doctrine Transport**, you can query the `AuditLog` entity directly via the repository.

```php
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

public function getLogs(EntityManagerInterface $em)
{
    $logs = $em->getRepository(AuditLog::class)->findBy(
        ['entityClass' => Product::class, 'entityId' => '123'],
        ['createdAt' => 'DESC']
    );
    
    // ...
}
```

### 4. Programmatic Audit Retrieval (Reader/Query API)

[AuditReader Documentation](docs/AUDIT_READER.md)

### 5. CLI Commands

The bundle provides several commands for managing audit logs:

#### List Audit Logs

```bash
# List recent audit logs
php bin/console audit:list

# Filter by entity, action, user, or date
php bin/console audit:list --entity=User --action=update --limit=50
php bin/console audit:list --user=123 --from="-7 days"
```

#### Purge Old Logs

```bash
# Preview logs to delete (dry run)
php bin/console audit:purge --before="30 days ago" --dry-run

# Delete logs older than a date
php bin/console audit:purge --before="2024-01-01" --force
```

#### Export Logs

```bash
# Export to JSON
php bin/console audit:export --format=json --output=audits.json

# Export to CSV with filters
php bin/console audit:export --format=csv --entity=User --from="-30 days" -o audits.csv
```

#### View Diff

```bash
# View diff by Audit Log ID
php bin/console audit:diff 123

# View diff by Entity Short Name and ID (shows the latest log)
php bin/console audit:diff User 42

# Options
php bin/console audit:diff 123 --include-timestamps  # Include createdAt/updatedAt
php bin/console audit:diff 123 --json                # Output as JSON
```

#### Revert Entity Changes

```bash
# Revert an entity to its state in a specific audit log
php bin/console audit:revert 123

# Preview the revert changes without applying them
php bin/console audit:revert 123 --dry-run

# Revert a creation (which deletes the entity) - requires force
php bin/console audit:revert 123 --force
```

**Note:** The revert command automatically handles soft-deleted entities (if using Gedmo SoftDeleteable) by temporarily restoring them to apply changes.

```shell
// List audit logs for a specific transaction
// bin/console audit:list --transaction=019b5aca-60ed-70bf-b139-255aa96c96cb
//
// Audit Logs (1 results)
// ======================
//
// +----+--------+-----------+--------+-------------------+----------+---------------------+
// | ID | Entity | Entity ID | Action | User              | Tx Hash  | Created At          |
// +----+--------+-----------+--------+-------------------+----------+---------------------+
// | 60 | Post   | 25        | create | oerdman@yahoo.com | 019b5aca | 2025-12-26 13:12:51 |
// +----+--------+-----------+--------+-------------------+----------+---------------------+
```

## EasyAdmin Integration

This bundle comes with built-in support for [EasyAdmin](https://github.com/EasyCorp/EasyAdminBundle), allowing you to instantly view and filter audit logs in your dashboard.

### 1. Install EasyAdmin

If you haven't already, install the bundle:

```bash
composer require easycorp/easyadmin-bundle
```

### 2. Register the Controller

Add the `AuditLogCrudController` to your `DashboardController`:

```php
use Rcsofttech\AuditTrailBundle\Controller\Admin\AuditLogCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;

public function configureMenuItems(): iterable
{
    yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
    
    // Add the Audit Log menu item
    yield MenuItem::linkToCrud('Audit Logs', 'fas fa-history', \Rcsofttech\AuditTrailBundle\Entity\AuditLog::class)
        ->setController(AuditLogCrudController::class);
}
```

That's it! You now have a read-only view of your audit logs with filtering by Entity, Action, User, and Date.

## Advanced Usage

### Using the Queue Transport

To offload audit logging to a worker, enable the queue transport and configure Symfony Messenger.

1. **Config**:

    ```yaml
    audit_trail:
        transports:
            doctrine: false # Optional: disable DB storage
            queue:
                enabled: true
    ```

2. **Messenger Transport**:

    ```yaml
    framework:
        messenger:
            transports:
                audit_trail: '%env(MESSENGER_TRANSPORT_DSN)%'
    ```

### Custom User Resolution

By default, the bundle uses Symfony Security to resolve the user. If you have a custom authentication system, you can implement `UserResolverInterface` and decorate the service.

### Custom Event Listeners

The bundle dispatches a Symfony event when audit logs are created, allowing you to add custom logic:

```php
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: AuditLogCreatedEvent::NAME)]
class AuditLogListener
{
    public function __invoke(AuditLogCreatedEvent $event): void
    {
        $audit = $event->getAuditLog();
        $entity = $event->getEntity();
        
        // Add custom metadata, send notifications, etc.
        if ($event->getAction() === 'delete') {
            // Send alert for deletions
        }
    }
}
```

## License

MIT License.
