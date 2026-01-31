# Advanced Usage

## Granular Control

You can ignore specific properties or enable/disable auditing per entity via the class-level attribute.

```php
#[Auditable(enabled: true, ignoredProperties: ['internalCode'])]
class Product
{
    private ?string $internalCode = null;
}
```

## Conditional Auditing

Skip auditing based on runtime conditions using the `#[AuditCondition]` attribute or custom voters.

### Option 1: Using ExpressionLanguage

Add the `#[AuditCondition]` attribute to your entity. You have access to `object`, `action`, `changeSet`, and `user`.

```php
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[Auditable]
#[AuditCondition("action == 'update' and object.getPrice() > 100")]
class Product
{
    public function getPrice(): int { ... }
}
```

**Available Context Variables:**

- `object`: The entity being audited.
- `action`: The action being performed (`create`, `update`, `delete`, etc.).
- `changeSet`: The array of changes.
- `user`: An object containing `id`, `username`, and `ip`.

### Option 2: Custom Voters

For complex logic, implement the `AuditVoterInterface`. Your voter will be automatically discovered if it's registered as a service.

```php
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;

class MyCustomVoter implements AuditVoterInterface
{
    public function vote(object $entity, string $action, array $changeSet): bool
    {
        // Return false to skip auditing
        return $action !== 'delete' || $this->isAdmin();
    }
}
```

## Rich Context & Impersonation Tracking

The bundle automatically tracks rich context for every audit log, stored in a flexible JSON `context` column.

### Impersonation Tracking

If an admin is impersonating a user (using Symfony's `_switch_user`), the bundle automatically records the impersonator's ID and username in the audit log context.

```php
$entry = $auditReader->forEntity(Product::class, '123')->getFirstResult();
$context = $entry->getContext();

if (isset($context['impersonation'])) {
    echo "Action performed by " . $context['impersonation']['impersonator_username'];
}
```

### Custom Context

You can manually add custom metadata to your audit logs by implementing the `AuditContextContributorInterface`. This is the recommended way to add application-specific information like correlation IDs, app versions, or feature flags.

```php
namespace App\Audit;

use Rcsofttech\AuditTrailBundle\Contract\AuditContextContributorInterface;

class SystemInfoContributor implements AuditContextContributorInterface
{
    public function contribute(object $entity, string $action, array $changeSet): array
    {
        return [
            'app_version' => 'v2.4.1',
            'server_node' => gethostname(),
        ];
    }
}
```

The bundle automatically discovers and executes all tagged contributors, merging their results into the `context` JSON column.

## Events & Customization

The bundle dispatches Symfony events that allow you to hook into the audit process.

### `AuditLogCreatedEvent`

Dispatched immediately after an `AuditLog` object is created but before it is persisted or sent to a transport. Use this to:

- Add custom metadata to the `context`.
- Modify the audit log data.
- Trigger external notifications.

```php
namespace App\EventSubscriber;

use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AuditSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AuditLogCreatedEvent::NAME => 'onAuditLogCreated',
        ];
    }

    public function onAuditLogCreated(AuditLogCreatedEvent $event): void
    {
        $log = $event->getAuditLog();
        
        // Add custom metadata
        $context = $log->getContext();
        $context['server_id'] = 'node-01';
    }
}
```

### `AuditMessageStampEvent`

Dispatched when an audit message is about to be sent via the Messenger transport. Use this to add custom stamps (e.g., `DelayStamp`, `AmqpStamp`) to the message.

```php
namespace App\EventSubscriber;

use Rcsofttech\AuditTrailBundle\Event\AuditMessageStampEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class AuditStampSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AuditMessageStampEvent::class => 'onAuditMessageStamp',
        ];
    }

    public function onAuditMessageStamp(AuditMessageStampEvent $event): void
    {
        // Add a 5-second delay to all audit messages
        $event->addStamp(new DelayStamp(5000));
    }
}
```
