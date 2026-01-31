# Security & Integrity

## Masking Sensitive Fields

Sensitive data is automatically masked in audit logs.

### Option 1: Use PHP's `#[SensitiveParameter]`

```php
public function __construct(
    #[\SensitiveParameter] private string $password,
) {}
```

### Option 2: Use `#[Sensitive]`

```php
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;

#[Sensitive(mask: '****')]
private string $ssn;
```

## Audit Log Integrity

Ensure the integrity of your audit logs by detecting any unauthorized tampering or modifications. This command validates cryptographic hashes to identify compromised records.

```bash
php bin/console audit:verify-integrity
```

**Use Cases:**

- **Compliance Audits**: Verify that audit logs haven't been altered for regulatory compliance (SOX, HIPAA, GDPR).
- **Security Monitoring**: Detect unauthorized tampering after security incidents.
- **Historical Data Verification**: Confirm past records are accurate and trustworthy.

> [!WARNING]
> Any tampered logs indicate a serious security breach. Investigate immediately and review access controls to your database.

## Transport Payload Signing

When using **HTTP** or **Queue** transports with integrity enabled, the bundle automatically signs the payload to ensure data authenticity during transit.

### HTTP Transport

The bundle adds an `X-Signature` header to the HTTP request containing the HMAC signature of the JSON body.

```http
POST /api/logs HTTP/1.1
X-Signature: a1b2c3d4...
Content-Type: application/json

{ ... }
```

### Queue Transport

The bundle adds a `SignatureStamp` to the Messenger envelope containing the signature of the serialized `AuditLogMessage`.

```php
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;

// In your message handler
$signature = $envelope->last(SignatureStamp::class)?->signature;
```
