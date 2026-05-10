# Audit Message Serializer

The bundle includes an `AuditLogMessageSerializer` that writes audit messages
as flat JSON. This lets you:

1. Publish audit logs from your Symfony app to a queue such as RabbitMQ or Redis.
2. Read those logs in any application, including Node.js, Python, Go, or another Symfony app, by using the JSON body and transport headers.

## JSON Format

The serializer produces a flat JSON structure. The example below shows the
public shape. Some optional fields may be `null` depending on the audit type
and delivery phase:

```json
{
  "entity_class": "App\\Entity\\User",
  "entity_id": "123",
  "action": "update",
  "old_values": { "email": "old@example.com" },
  "new_values": { "email": "new@example.com" },
  "changed_fields": ["email"],
  "user_id": "42",
  "username": "admin",
  "ip_address": "127.0.0.1",
  "user_agent": "Mozilla/5.0",
  "transaction_hash": "019e05e8-e794-7797-bf4f-8286f65a18cd",
  "created_at": "2024-01-20T12:00:00+00:00",
  "signature": "entity-hmac-signature-here",
  "delivery_id": null,
  "reverted_log_id": null,
  "context": { "source": "checkout" }
}
```

## Consuming in Symfony

`AuditLogMessageSerializer` is currently **encode-only**. It is meant for
publishing outbound audit messages and it throws if `decode()` is called.

If you are consuming these messages in another Symfony application, treat the payload as plain JSON and read any transport-level metadata from headers or stamps:

- Queue transport: the body may include the persisted audit log `signature`, while transport integrity is carried in a `SignatureStamp`
- Queue transport `X-Audit-Signature`: HMAC of the canonical JSON body produced by `AuditLogMessageSerializer`
- Custom HTTP delivery: integrity is carried in the `X-Signature` header
- `AuditLogMessageSerializer` headers: `X-Audit-Api-Key` and `X-Audit-Signature` when the corresponding stamps are present

In short, the stable part is the flat JSON payload shape, not automatic reverse
deserialization by this serializer class.
