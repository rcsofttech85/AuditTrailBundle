# AuditTrailBundle

[![CI](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml/badge.svg)](https://github.com/rcsofttech85/AuditTrailBundle/actions/workflows/ci.yaml)
[![Latest Stable Version](https://img.shields.io/packagist/v/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)
![License](https://img.shields.io/github/license/rcsofttech85/AuditTrailBundle)
[![Total Downloads](https://img.shields.io/packagist/dt/rcsofttech/audit-trail-bundle.svg)](https://packagist.org/packages/rcsofttech/audit-trail-bundle)

A lightweight, high-performance Symfony bundle that automatically tracks and stores Doctrine ORM entity changes for audit logging and compliance.

## Features

-   **Automatic Tracking**: Listens to Doctrine `onFlush` and `postFlush` events to capture inserts, updates, and deletions.
-   **Zero Configuration**: Works out of the box with sensible defaults.
-   **Multiple Transports**:
    -   **Doctrine**: Store audit logs directly in your database (default).
    -   **HTTP**: Send audit logs to an external API (e.g., ELK, Splunk).
    -   **Queue**: Dispatch audit logs via Symfony Messenger for async processing.
    -   **Chain**: Use multiple transports simultaneously.
-   **User Context**: Automatically captures the current user, IP address, and User Agent.
-   **Granular Control**: Use the `#[Auditable]` attribute to enable/disable auditing per entity and ignore specific properties.
-   **Modern PHP**: Built for PHP 8.4+ using strict types, readonly classes, and asymmetric visibility.

## Requirements

-   PHP 8.4+
-   Symfony 7.4+
-   Doctrine ORM 3.0+

## Installation

1.  Install the bundle via Composer:

    ```bash
    composer require rcsofttech/audit-trail-bundle
    ```

2.  If you are using the **Doctrine Transport** (default), update your database schema:

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

### 2. Viewing Audit Logs

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

### 3. Cleaning Up Old Logs

The bundle provides a command to cleanup old audit logs from the database:

```bash
php bin/console audit:cleanup
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

1.  **Config**:
    ```yaml
    audit_trail:
        transports:
            doctrine: false # Optional: disable DB storage
            queue:
                enabled: true
    ```

2.  **Messenger Routing**:
    ```yaml
    framework:
        messenger:
            routing:
                'Rcsofttech\AuditTrailBundle\Message\AuditLogMessage': async
    ```

### Custom User Resolution

By default, the bundle uses Symfony Security to resolve the user. If you have a custom authentication system, you can implement `UserResolverInterface` and decorate the service.

## License

MIT License.
