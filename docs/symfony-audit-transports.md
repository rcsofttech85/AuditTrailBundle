# Audit Transports Documentation

AuditTrailBundle supports multiple transports to dispatch audit logs. This allows you to offload audit processing to external services or queues, keeping your main application fast.

> [!NOTE]
> **Messenger Confusion Warning:** The bundle utilizes Symfony Messenger for **two different features**.
>
> 1. `database: { async: true }`: Designed to save logs to your *local database* asynchronously via an internal worker.
> 2. `queue: { enabled: true }`: Designed to publish logs to an *external system* (so other microservices or tools like ELK can consume them).
>
> They can be used simultaneously without conflict because they route to different internal queues.

## 1. Database Transport (Async)

By default, the `database` transport persists logs synchronously at the end of the Doctrine transaction. For high-traffic applications, you can offload this database write to a background worker.

### Configuration

Enable async mode in `config/packages/audit_trail.yaml`:

```yaml
audit_trail:
    transports:
        database:
            enabled: true
            async: true # Offloads inserts to Messenger
```

You must explicitly define a transport named `audit_trail_database` in `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            # Internal bundle worker will consume from this transport
            audit_trail_database: '%env(MESSENGER_TRANSPORT_DSN)%'
```

*(The bundle auto-registers `PersistAuditLogHandler` to consume from this transport and insert the records into the database).*

---

## 2. Queue Transport (External Delivery)

The `queue` transport acts as a webhook publisher. It dispatches a strictly-typed DTO (`AuditLogMessage`) to the bus. You must write your own external consumer to ingest these messages (e.g., Logstash, another microservice).

### Configuration for Queue transport

Enable the queue transport in `config/packages/audit_trail.yaml`:

```yaml
audit_trail:
    transports:
        queue:
            enabled: true
            bus: 'messenger.bus.default' # Optional: specify a custom bus
```

You must define a transport named `audit_trail` in `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            # Your external service/worker will consume from this transport
            audit_trail: '%env(MESSENGER_TRANSPORT_DSN)%'
```

### Advanced Usage: Messenger Stamps

You can pass Messenger stamps (like `DelayStamp` or `DispatchAfterCurrentBusStamp`) in two ways:

#### 1. Manually (Programmatic Audits)

Pass them via the `$context` array when creating an audit log or performing a revert.

```php
use Symfony\Component\Messenger\Stamp\DelayStamp;

// Programmatic audit log with a 5-second delay
$auditService->createAuditLog($entity, 'custom_action', null, null, [
    'messenger_stamps' => [new DelayStamp(5000)]
]);
```

#### 2. Automatically (Event Subscriber)

Use the `AuditMessageStampEvent` to add stamps to the message right before it is dispatched to the bus. This is the recommended way to add transport-specific stamps.

```php
namespace App\EventSubscriber;

use Rcsofttech\AuditTrailBundle\Event\AuditMessageStampEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class AuditMessengerSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AuditMessageStampEvent::class => 'onAuditMessageStamp',
        ];
    }

    public function onAuditMessageStamp(AuditMessageStampEvent $event): void
    {
        // Add a 5-second delay to all audit logs sent via Queue
        $event->addStamp(new DelayStamp(5000));
    }
}
```

### Queue Payload Signing

When `audit_trail.integrity.enabled` is true, the bundle automatically signs the payload to ensure authenticity. The signature is added as a `SignatureStamp` to the Messenger envelope.

```php
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;

// In your message handler
$signature = $envelope->last(SignatureStamp::class)?->signature;
```

---

## 2. HTTP Transport

The HTTP transport streams audit logs to an external API endpoint (e.g., a logging service, ELK, or Splunk).

### Configuration for http transport

```yaml
audit_trail:
    transports:
        http:
            enabled: true
            endpoint: 'https://audit-api.example.com/v1/logs'
            headers:
                'Authorization': 'Bearer your-api-token'
                'X-App-Name': 'MySymfonyApp'
            timeout: 10 # seconds
```

### HTTP Payload Signing

When `audit_trail.integrity.enabled` is true, the bundle adds an `X-Signature` header to the HTTP request. This header contains the HMAC signature of the JSON body.

```http
POST /api/logs HTTP/1.1
X-Signature: a1b2c3d4...
Content-Type: application/json

{ ... }
```

### Payload Structure

The transport sends a `POST` request with a JSON body:

```json
{
    "entity_class": "App\\Entity\\Product",
    "entity_id": "123",
    "action": "update",
    "old_values": {"price": 100},
    "new_values": {"price": 120},
    "changed_fields": ["price"],
    "user_id": 1,
    "username": "admin",
    "ip_address": "127.0.0.1",
    "user_agent": "Mozilla/5.0...",
    "transaction_hash": "a1b2c3d4...",
    "signature": "hmac-signature...",
    "context": {
        "app_version": "1.0.0",
        "custom_meta": "value"
    },
    "created_at": "2024-01-01T12:00:00+00:00"
}
```

---

## 3. Database Transport (Default)

The Database transport stores logs in your local database. It is enabled by default.

### Sync Mode (Default)

Logs are persisted directly during the Doctrine lifecycle:

```yaml
audit_trail:
    transports:
        database:
            enabled: true
            async: false
```

### Async Mode

Logs are dispatched via Symfony Messenger and persisted by a built-in handler (`PersistAuditLogHandler`).
This is useful for high-traffic applications where you want to offload DB writes to a worker.

```yaml
audit_trail:
    transports:
        database:
            enabled: true
            async: true
```

You must define a transport named `audit_trail_database` in `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            audit_trail_database: '%env(MESSENGER_TRANSPORT_DSN)%'
```

---

## 4. Chain Transport (Multiple Transports)

You can enable multiple transports simultaneously. The bundle will automatically use a `ChainAuditTransport` to dispatch logs to all enabled transports.

```yaml
audit_trail:
    transports:
        database:
            enabled: true
        queue:
            enabled: true
```

---

## 5. Signature vs. Payload Signing

It is important to distinguish between the two types of signatures:

1. **Entity Signature (`signature` field in JSON):**
   * Generated at the moment of creation.
   * Signs the business data (Entity Class, ID, Changes).
   * **Purpose:** Long-term data integrity and non-repudiation. Stored in the database.

2. **Transport Signature (`X-Signature` header or `SignatureStamp`):**
   * Generated just before sending.
   * Signs the *entire* payload (including the Entity Signature).
   * **Purpose:** Transport security. Ensures the message wasn't tampered with during transit.
