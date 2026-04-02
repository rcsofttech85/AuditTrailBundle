# Audit Transports Documentation

AuditTrailBundle supports multiple transports to dispatch audit logs. This allows you to store logs locally, publish them to external services, or fan them out to several destinations at once.

> [!NOTE]
> **Messenger Confusion Warning:** The bundle utilizes Symfony Messenger for **two different features**.
>
> 1. `database: { async: true }`: Designed to save logs to your *local database* asynchronously via an internal worker.
> 2. `queue: { enabled: true }`: Designed to publish logs to an *external system* (so other microservices or tools like ELK can consume them).
>
> They can be used simultaneously because they target different Messenger message types and transport names.
> [!NOTE]
> In `v3`, `symfony/messenger` and `symfony/http-client` are installed as bundle dependencies.
> You do not need a separate Composer step to enable queue, async database, or HTTP delivery.
> What you still need is the corresponding runtime configuration:
> Messenger routing/buses for queue or async database, and an endpoint for HTTP transport.

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

> [!IMPORTANT]
> Async database mode uses a per-message delivery identifier so worker retries can be handled idempotently.
> If you enable or upgrade to this feature, generate and run a Doctrine migration so the `audit_log.delivery_id`
> column and unique constraint exist before workers process messages.

---

## 2. Queue Transport (External Delivery)

The `queue` transport dispatches a strictly-typed DTO (`AuditLogMessage`) to a Symfony Messenger bus. You must define the Messenger transport routing yourself and provide the downstream consumer that ingests those messages.

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

Use the `AuditMessageStampEvent` to add stamps to the message right before it is dispatched to the bus. This is the supported way to add transport-specific stamps such as `DelayStamp` or `DispatchAfterCurrentBusStamp`.

```php
<?php

declare(strict_types=1);

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
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;

// In your message handler
$signature = $envelope->last(SignatureStamp::class)?->signature;
```

---

## 3. HTTP Transport

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

Different transports send slightly different JSON payloads based on their delivery target.

#### HTTP Transport

The HTTP transport sends a flat JSON object including the entity signature and the persisted audit context captured on the `AuditLog`.

```json
{
    "entity_class": "App\\Entity\\Product",
    "entity_id": "123",
    "action": "update",
    "old_values": {"price": 100},
    "new_values": {"price": 120},
    "changed_fields": ["price"],
    "user_id": "1",
    "username": "admin",
    "ip_address": "127.0.0.1",
    "user_agent": "Mozilla/5.0...",
    "transaction_hash": "a1b2c3d4...",
    "signature": "hmac-signature-here",
    "context": {
        "impersonation": {
            "impersonator_id": "99",
            "impersonator_username": "admin"
        },
        "runtime_meta": "value"
    },
    "created_at": "2024-01-01T12:00:00+00:00"
}
```

#### Queue Transport (AuditLogMessage)

The Queue transport dispatches a strictly-typed DTO. It **omits** the entity signature from the body and carries transport signing separately through Messenger metadata (`SignatureStamp`).

```json
{
    "entity_class": "App\\Entity\\Product",
    "entity_id": "123",
    "action": "update",
    "old_values": {"price": 100},
    "new_values": {"price": 120},
    "changed_fields": ["price"],
    "user_id": "1",
    "username": "admin",
    "ip_address": "127.0.0.1",
    "user_agent": "Mozilla/5.0...",
    "transaction_hash": "a1b2c3d4...",
    "created_at": "2024-01-01T12:00:00+00:00",
    "context": {
        "impersonation": {
            "impersonator_id": "99",
            "impersonator_username": "admin"
        }
    }
}
```

---

## 4. Database Transport (Default)

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

Worker retries are handled safely: duplicate deliveries for the same internal message are ignored instead of creating duplicate audit rows.

---

## 5. Chain Transport (Multiple Transports)

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

## 6. Signature vs. Payload Signing

It is important to distinguish between the two types of signatures:

1. **Entity Signature (`signature` field in JSON):**
   * Generated at the moment of creation.
   * Signs the business data (Entity Class, ID, Changes).
   * **Purpose:** Long-term data integrity and non-repudiation. Stored in the database.

2. **Transport Signature (`X-Signature` header or `SignatureStamp`):**
   * Generated just before sending.
   * Signs the outbound transport payload.
   * **Purpose:** Transport security. Ensures the message wasn't tampered with during transit.
