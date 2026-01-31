# Cross-Platform Serializer

The bundle includes a `AuditLogMessageSerializer` that supports **cross-platform** JSON serialization. This allows you to:

1. **Publish** audit logs from your Symfony app to a queue (RabbitMQ, Redis, etc.) in a clean, language-agnostic JSON format.
2. **Consume** these logs in **any application** (Node.js, Python, Go, or another Symfony app).

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

If you are consuming these messages in another Symfony application using this bundle, the serializer automatically handles **decoding** back into an `AuditLogMessage` object, including verifying signatures if integrity is enabled.
