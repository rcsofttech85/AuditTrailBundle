# Security & Integrity

## Masking Sensitive Fields

Sensitive data is automatically masked in audit logs.

### Option 1: Use PHP's `#[SensitiveParameter]`

```php
<?php

declare(strict_types=1);

public function __construct(
    #[\SensitiveParameter]
    private string $password,
) {
}
```

### Option 2: Use `#[Sensitive]`

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;

#[Sensitive(mask: '****')]
private string $ssn;
```

## Audit Log Integrity

Use this command to check whether stored audit logs were changed after they
were written. It validates the stored signatures and reports compromised
records.

```bash
php bin/console audit:verify-integrity
```

**Use cases:**

- **Compliance audits**: Verify that audit logs have not been altered for SOX, HIPAA, GDPR, or similar reviews.
- **Security monitoring**: Detect tampering after a security incident.
- **Historical data checks**: Confirm older records still match their stored signatures.

> [!WARNING]
> Any tampered logs indicate a serious security breach. Investigate immediately and review access controls to your database.

Signed audit metadata includes:

- entity identity and action
- old and new values
- `changed_fields`
- user and request metadata
- transaction hash
- persisted context
- creation timestamp

The bundle also enforces a maximum persisted context payload of 65,536 bytes. Oversized context is truncated to a safe marker payload before storage and signing.

## Transport Payload Signing

When you use **HTTP** or **Queue** transports with integrity enabled, the
bundle also signs the payload before sending it.

### HTTP Transport

The bundle adds an `X-Signature` header to the HTTP request containing the HMAC signature of the JSON body.

```http
POST /api/logs HTTP/1.1
X-Signature: a1b2c3d4...
Content-Type: application/json

{ ... }
```

### Queue Transport

The bundle adds a `SignatureStamp` to the Messenger envelope containing the HMAC of the exact JSON body emitted by `AuditLogMessageSerializer`.

That transport signature is distinct from the audit log's own `signature` field in the JSON body. Queue payloads can carry the persisted entity signature while `SignatureStamp` protects the serialized message in transit.

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;

// In your message handler
$signature = $envelope->last(SignatureStamp::class)?->signature;
```
