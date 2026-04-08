# Cross-Platform Serializer

The bundle includes an `AuditLogMessageSerializer` that supports **cross-platform** JSON serialization. This allows you to:

1. **Publish** audit logs from your Symfony app to a queue (RabbitMQ, Redis, etc.) in a clean, language-agnostic JSON format.
2. **Consume** these logs in **any application** (Node.js, Python, Go, or another Symfony app) using the flat JSON body and transport headers.

## JSON Format

The serializer produces a flat JSON structure that is easy to parse:

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
  "created_at": "2024-01-20T12:00:00+00:00"
}
```

## Consuming in Symfony

`AuditLogMessageSerializer` is currently **encode-only**. It is intended for publishing outbound audit messages and it throws if `decode()` is called.

If you are consuming these messages in another Symfony application, treat the payload as plain JSON and read any transport-level metadata from headers or stamps:

- Queue transport: integrity is carried in a `SignatureStamp`
- Custom HTTP delivery: integrity is carried in the `X-Signature` header
- `AuditLogMessageSerializer` headers: `X-Audit-Api-Key` and `X-Audit-Signature` when the corresponding stamps are present

In other words, the documented interoperability guarantee is the flat JSON payload shape, not automatic reverse deserialization by this serializer class.
