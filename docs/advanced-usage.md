# Advanced Usage

## Granular Control

You can ignore specific properties or enable/disable auditing per entity via the class-level attribute.

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

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
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[Auditable]
#[AuditCondition("action == 'update' and object.getPrice() > 100")]
class Product
{
    public function getPrice(): int
    {
        // ...
    }
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
<?php

declare(strict_types=1);

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

## Access Auditing (Read Tracking)

To track when an entity is accessed (read), use the `#[AuditAccess]` attribute. By default this feature audits **GET** requests only, and you can change the allowed methods with the `audit_trail.audited_methods` configuration option.

```php
<?php

declare(strict_types=1);

use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;

#[ORM\Entity]
#[AuditAccess(cooldown: 3600, level: 'info', message: 'User accessed sensitive record')]
class SensitiveDocument
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;
}
```

### Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `cooldown` | `int` | `0` | Prevent duplicate logs for the same user/entity within X seconds (requires PSR-6 cache). |
| `level` | `string` | `'info'` | The log level for the access audit. |
| `message` | `string?` | `null` | A custom message to include in the audit log. |

> [!NOTE]
> `#[AuditAccess]` does not require `#[Auditable]` — they are independent attributes.
> However, if `#[AuditCondition]` is present on the same entity, it **is** respected for access logs.
> The expression receives `action = "access"` for fine-grained control.

### Access Audit Lifecycle

Access audits are detected from Doctrine `postLoad` events during the main request. The bundle queues those access logs and processes the pending access-audit work on Symfony's `kernel.terminate` event.

Important behavior notes:

- the bundle does **not** perform an extra Doctrine ORM `flush()` from `kernel.terminate`
- queued access audits are dispatched through the normal audit dispatcher and transport flow
- when the database transport handles a deferred access audit, it uses the deferred writer path instead of re-entering Doctrine flush lifecycle work

## Collection Tracking Notes

The bundle tracks direct Doctrine collection diffs and merges scalar-field plus collection changes from the same flush into one coherent `update` audit.

Important limitation:

- If you delete a related entity and expect the owning entity's collection field to be audited as changed, the bundle can only infer that reliably when Doctrine exposes the owning side through the in-memory object graph.
- In practice, that means bidirectional associations are the safest choice for delete-propagation auditing.
- With unidirectional mappings, database join-table cleanup may still happen correctly, but the bundle may not have enough reverse-relation context during flush to emit a second owner-side collection update audit.

### Explicit Read Intent Override

For enterprise apps with complex back-office flows, you can explicitly tell the bundle whether the current main request should count as a real record view.

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Http\AuditRequestAttributes;
use Symfony\Component\HttpFoundation\Request;

final class AdminPostController
{
    public function detail(Request $request): void
    {
        $request->attributes->set(AuditRequestAttributes::ACCESS_INTENT, true);
    }

    public function edit(Request $request): void
    {
        $request->attributes->set(AuditRequestAttributes::ACCESS_INTENT, false);
    }
}
```

- `true`: force access auditing for the current main request
- `false`: suppress access auditing for the current main request
- unset: fall back to the bundle's default Symfony-friendly heuristics for safe `GET` / `HEAD` record views

### Cache Configuration

To use the `cooldown` feature, you must specify a PSR-6 cache pool in your configuration:

```yaml
# config/packages/audit_trail.yaml
audit_trail:
    cache_pool: 'cache.app' # Use any available PSR-6 cache pool
```

If no cache pool is configured, request-level deduplication still works during the current request, but cross-request cooldown persistence is unavailable.

## Rich Context & Impersonation Tracking

The bundle automatically tracks rich context for every audit log, stored in a flexible JSON `context` column.

### Impersonation Tracking

If an admin is impersonating a user (using Symfony's `_switch_user`), the bundle automatically records the impersonator's ID and username in the audit log context.

```php
$entry = $auditReader->forEntity(Product::class, '123')->getFirstResult();
$context = $entry?->auditLog->context ?? [];

if (isset($context['impersonation'])) {
    echo "Action performed by " . $context['impersonation']['impersonator_username'];
}
```

### Custom Context

You can manually add custom metadata to your audit logs by implementing the `AuditContextContributorInterface`. This is the recommended way to add application-specific information like correlation IDs, app versions, or feature flags.

```php
<?php

declare(strict_types=1);

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

Autoconfigured services that implement `AuditContextContributorInterface` are tagged automatically and their results are merged into the `context` JSON column.

## Events & Customization

The bundle dispatches Symfony events that allow you to hook into the audit process.

## AI-Ready Processing

The bundle now includes an optional `AuditLogAiProcessorInterface` contract for integrations that want to add AI-derived metadata before signing and transport dispatch.

This is the recommended path for future Symfony AI integrations because it keeps AI explicit, optional, and non-blocking.

```php
<?php

declare(strict_types=1);

namespace App\Audit;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogAiProcessorInterface;

final class AuditInsightAiProcessor implements AuditLogAiProcessorInterface
{
    public function getNamespace(): string
    {
        return 'insight_engine';
    }

    public function process(array $context, ?object $entity = null): array
    {
        return [
            'summary' => 'Price changed on a high-value order',
            'risk' => 'medium',
        ];
    }
}
```

Guidelines for AI processor implementations:

- Treat AI output as optional metadata, not business-critical logic.
- Return metadata only; the dispatcher stores it under `context['ai'][your_namespace]`.
- Always use a stable namespace so multiple AI processors can coexist cleanly.
- AI processors run only in delivery-safe phases such as `post_flush`, `batch_flush`, and `manual_flush`.
- Keep payloads structured and compact so they remain compatible with context-size limits.
- If AI metadata alone would push context over the size limit, the dispatcher drops only the AI payload and preserves the rest of the audit context.
- Prefer deterministic outputs when possible; if using AI later, fail open and let the audit continue.

### `AuditLogCreatedEvent`

Dispatched immediately after an `AuditLog` object is created but before it is persisted or sent to a transport. Use this to:

- Add custom metadata to the `context`.
- Modify the audit log data.
- Trigger external notifications.

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AuditSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AuditLogCreatedEvent::class => 'onAuditLogCreated',
        ];
    }

    public function onAuditLogCreated(AuditLogCreatedEvent $event): void
    {
        $event->auditLog->context = [
            ...$event->auditLog->context,
            'server_id' => 'node-01',
        ];
    }
}
```

### `AuditMessageStampEvent`

Dispatched when an audit message is about to be sent via the Messenger transport. Use this to add custom stamps (e.g., `DelayStamp`, `AmqpStamp`) to the message.

```php
<?php

declare(strict_types=1);

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
