# Audit Transports Documentation

AuditTrailBundle can store audits in the local database, send them over HTTP,
or send them through Symfony Messenger.

Messenger is used for two different jobs:

1. `database: { async: true }` writes audit rows to your local database through a background worker.
2. `queue: { enabled: true }` publishes audit messages for an external consumer.

You can use these at the same time because they use different message types
and transport names.

Install only what you need:

- `symfony/messenger` for queue delivery or async database persistence
- `symfony/http-client` for HTTP delivery

If you enable a transport without its package installed, the bundle throws a
clear `LogicException` during container build. You still need the matching
runtime config too: Messenger routing or buses for queue or async database,
and an endpoint for HTTP delivery.

## Install Matrix

Use the base bundle by itself if you only need the synchronous database transport.

| Feature | Extra package | Extra Symfony config |
| :--- | :--- | :--- |
| Synchronous database transport | none | none |
| Async database transport | `symfony/messenger` | `audit_trail_database` Messenger transport |
| Queue transport | `symfony/messenger` | `audit_trail` Messenger transport/routing |
| HTTP transport | `symfony/http-client` | HTTP endpoint config |

## Transport Contract

Custom transports implement this contract:

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Transport\AuditDeliveryResult;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;

final class AppAuditTransport implements AuditTransportInterface
{
    public function send(AuditTransportContext $context): AuditDeliveryResult
    {
        // $context->phase
        // $context->entityManager
        // $context->unitOfWork
        // $context->entity
        // $context->audit

        return AuditDeliveryResult::delivered();
    }

    public function supports(AuditTransportContext $context): bool
    {
        return true;
    }
}
```

Return `AuditDeliveryResult::delivered()` when delivery succeeds. If your
transport has multiple child steps and one fails later, it can return a
partial result instead.

`AuditTransportContext` contains:

- `phase`: the current [AuditPhase](../src/Enum/AuditPhase.php)
- `entityManager`: the active Doctrine entity manager for this audit flow
- `audit`: the current [AuditLog](../src/Entity/AuditLog.php)
- `unitOfWork`: the active `UnitOfWork` when the phase has one
- `entity`: the source entity when it is available

Treat the `AuditTransportContext` you receive as read-only. Internally the
dispatcher may create a new context with `withAudit()` after listeners replace
the `AuditLog`, but your transport should not mutate the context it receives.

## 1. Database Transport (Async)

By default, the `database` transport stores logs synchronously with logic that
matches the current phase:

- during `onFlush`, ORM-safe audit rows are attached to the current UnitOfWork
- during deferred phases such as `postFlush`, database audit rows are written without re-entering Doctrine `flush()`

If you want, you can move this database write to a Messenger worker.

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

If `symfony/messenger` is not installed and you enable `database.async: true`, the bundle throws:

```text
To use async database transport, you must install the symfony/messenger package.
```

*(The bundle auto-registers `PersistAuditLogHandler` for this transport and uses it to insert the records into the database.)*

> [!IMPORTANT]
> Async database mode uses a per-message delivery identifier so worker retries can be handled idempotently.
> If you enable or upgrade to this feature, generate and run a Doctrine migration so the `audit_log.delivery_id`
> column and unique constraint exist before workers process messages.

---

## 2. Queue Transport (External Delivery)

The `queue` transport sends an `AuditLogMessage` through a Symfony Messenger
bus. You still need to configure Messenger routing and the worker or service
that reads those messages.

If `symfony/messenger` is not installed and you enable `queue`, the bundle throws:

```text
To use the Queue transport, you must install the symfony/messenger package.
```

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

Use `AuditMessageStampEvent` if you want to add stamps right before the message
is dispatched. This is the normal way to add transport-specific stamps such as
`DelayStamp` or `DispatchAfterCurrentBusStamp`.

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

When `audit_trail.integrity.enabled` is true, the bundle signs the serialized `AuditLogMessage` to ensure authenticity in transit. That transport signature is added as a `SignatureStamp` on the Messenger envelope.

This is separate from the audit log's own `signature` field, which may still be present inside the JSON body when entity integrity signing is enabled.

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;

// In your message handler
$signature = $envelope->last(SignatureStamp::class)?->signature;
```

---

## 3. HTTP Transport

The HTTP transport sends audit logs to an external API endpoint, such as a
logging service, ELK, or Splunk.

HTTP delivery is synchronous when it runs, but it is phase-limited: the built-in
HTTP transport only supports deferred phases such as `postFlush` and `postLoad`.
Setting `defer_transport_until_commit: false` does not move HTTP delivery into
Doctrine's `onFlush` transaction window.

If `symfony/http-client` is not installed and you enable `http`, the bundle throws:

```text
To use the HTTP transport, you must install the symfony/http-client package.
```

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

Different transports send slightly different JSON payloads.

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

The queue transport sends a message object.

- The JSON body preserves the audit log payload, including the persisted entity-level `signature` field when present.
- Messenger metadata carries a separate transport signature via `SignatureStamp`.

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
    "signature": "entity-hmac-signature-here",
    "delivery_id": null,
    "reverted_log_id": null,
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

Logs are persisted directly during the Doctrine lifecycle for the current
phase:

```yaml
audit_trail:
    transports:
        database:
            enabled: true
            async: false
```

Behavior summary:

- `onFlush`: the bundle uses `persist()` plus `UnitOfWork::computeChangeSet()` so the audit row joins the current flush safely
- deferred phases such as `postFlush` and `postLoad`: the bundle resolves the final entity identifier if needed and inserts the audit row through a dedicated database writer

This avoids nested `flush()` calls from Doctrine event listeners and still
keeps local database-backed audits immediate.

> [!NOTE]
> Deferred database writes are not ORM-managed `AuditLog` persistence operations.
> If your application attaches Doctrine lifecycle listeners/subscribers specifically to `AuditLog`,
> those hooks are only relevant to the in-UnitOfWork ORM path, not the deferred direct-write path.

### Async Mode

Logs are dispatched through Symfony Messenger and persisted by the built-in
`PersistAuditLogHandler`. Use this if you want to move database writes to a
worker.

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

The async database path also preserves the original audit-log UUID when the
message is created, then reuses that UUID in the worker. This keeps keyset
pagination, latest-first reads, and transaction drilldowns aligned with audit
creation order instead of worker-consumption timing.

---

## 5. Chain Transport (Multiple Transports)

You can enable multiple transports at the same time. The bundle uses
`ChainAuditTransport` to send logs to each enabled transport.

```yaml
audit_trail:
    transports:
        database:
            enabled: true
        queue:
            enabled: true
```

`ChainAuditTransport` is fail-fast. If one child transport throws, later
transports in the chain do not run and the exception goes back to the
dispatcher. This keeps failure handling in one place instead of letting part of
the chain fail silently.

Fail-fast does **not** mean rollback across transports. If an earlier transport
already produced a side effect, the chain does not undo it when a later
transport fails.

Audit deliveries now carry an internal `deliveryId` so the bundle's
database-backed writes stay idempotent across fallback and retry paths. This
prevents duplicate local audit rows when an earlier database transport
succeeds and a later transport fails in the same chain. It does not roll back
or deduplicate side effects in external systems.

Transport order is fixed by the bundle, not by YAML key order. When multiple
transports are enabled, they run in this order:

1. database
2. http
3. queue

That means:

- a database transport runs before HTTP or queue when it supports the current phase
- an HTTP transport failure prevents later queue delivery in the same chain execution
- swapping YAML key order does not change execution order

---

## 6. Signature vs. Payload Signing

There are two different signatures:

1. **Entity Signature (`signature` field in JSON):**
   - Generated at the moment of creation.
   - Signs the business data (Entity Class, ID, Changes).
   - **Purpose:** Long-term data integrity and non-repudiation. Stored in the database.

2. **Transport Signature (`X-Signature` header or `SignatureStamp`):**
   - Generated just before sending.
   - Signs the outbound transport payload.
   - **Purpose:** Transport security. Ensures the message wasn't tampered with during transit.
