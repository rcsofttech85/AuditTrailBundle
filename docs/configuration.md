# Configuration Reference

Create a configuration file at `config/packages/audit_trail.yaml`.

For detailed transport configuration and usage, see [Audit Transports](symfony-audit-transports.md).

```yaml
audit_trail:
    # Enable or disable the bundle globally
    enabled: true

    # Global list of properties to ignore
    # Defaults to common timestamp fields only
    ignored_properties: ['updatedAt', 'updated_at']

    # Global list of entities to ignore
    ignored_entities: []

    # Retention period for database logs (in days)
    retention_days: 365

    # Context tracking
    track_ip_address: true
    track_user_agent: true

    # enable or disable delete tracking
    enable_hard_delete: true
    enable_soft_delete: true
    soft_delete_field: 'deletedAt'

    # Fallback to the database transport if another transport fails
    fallback_to_database: true

    # Cache pool used for access-audit cooldowns
    cache_pool: null

    # Required role/permission for EasyAdmin audit actions
    admin_permission: 'ROLE_ADMIN'

    # HTTP methods eligible for access auditing
    audited_methods: ['GET']

    # Collection Serialization Performance
    # -----------------------------------
    collection_serialization_mode: 'lazy'
    max_collection_items: 100

    transports:
        # Store logs in the local database
        database:
            enabled: true
            async: false   # Set to true to persist via Messenger worker (requires 'audit_trail_database' transport)

        # Send logs to an external API
        http:
            enabled: false
            endpoint: 'https://audit-service.internal/api/logs'
            headers: { }
            timeout: 5

        # Dispatch logs to a message queue
        queue:
            enabled: false
            bus: null # Optional: specify a custom bus
            api_key: null
            
    # Integrity & Signing
    # -------------------
    # Enable cryptographic signing of audit logs to prevent tampering.
    integrity:
        enabled: false
        secret: '%env(AUDIT_INTEGRITY_SECRET)%'
        algorithm: 'sha256'

    # Transaction Safety & Performance
    # --------------------------------
    # true (Default): Audits are sent at the Doctrine postFlush boundary.
    #   - Pros: High performance, non-blocking. Main flush succeeds even if deferred audit delivery fails.
    #   - Cons: Small risk of "data without audit" if the process fails after the main flush and before delivery completes.
    #   - Recommended for: External transports (HTTP, Queue).
    #
    # false: Audits are sent DURING the transaction (onFlush).
    #   - Pros: Strict atomicity. Data and audit are committed together.
    #   - Cons: Slower. If audit transport fails, the entire transaction rolls back.
    #   - Recommended for: Doctrine transport (when strict compliance is required).
    defer_transport_until_commit: true

    # If true, an exception in the transport will stop execution (and rollback if defer=false).
    # If false (default), transport errors are logged but execution continues.
    fail_on_transport_error: false
```

## Default Notes

- At least one transport must be enabled when `audit_trail.enabled` is `true`
- The database transport is enabled by default
- `integrity.secret` is required only when `integrity.enabled` is `true`
- `http.endpoint` must start with `http://` or `https://` when HTTP transport is enabled
- `max_collection_items` must be at least `1`

## Transaction Safety Guide

These three options control the bundle's failure boundary:

| Option | Default | What It Changes |
| :--- | :--- | :--- |
| `defer_transport_until_commit` | `true` | Sends audits after the Doctrine `postFlush` boundary instead of during `onFlush`. |
| `fail_on_transport_error` | `false` | Escalates transport exceptions instead of logging and continuing. |
| `fallback_to_database` | `true` | Falls back to the database transport when another transport fails and database transport support is available. |

### Recommended combinations

| Goal | Recommended Settings | Result |
| :--- | :--- | :--- |
| Best default safety/performance | `defer_transport_until_commit: true`, `fail_on_transport_error: false` | Main writes succeed even if HTTP/queue delivery fails after the main flush. |
| Strict in-transaction auditing | `defer_transport_until_commit: false`, `fail_on_transport_error: true`, `database.enabled: true`, `database.async: false` | Data and audit fail together when the audit cannot be recorded safely. |
| External transport with local safety net | `defer_transport_until_commit: false`, `fail_on_transport_error: false`, `fallback_to_database: true`, `database.enabled: true` | External transport failures can still be captured locally without requiring a nested `flush()` during `onFlush`. |

### Important behavior notes

- When `defer_transport_until_commit` is `false`, the bundle still avoids calling `flush()` from inside Doctrine `onFlush`.
- If fallback is needed during `onFlush`, the database audit entity is attached through Doctrine `UnitOfWork` change-set computation and joins the application's existing flush.
- If `defer_transport_until_commit` is `true`, there is a small but real window where the main transaction can commit and the audit delivery can fail afterward. This is the default performance trade-off for HTTP and queue transports.
- `fallback_to_database` only helps when database transport support is enabled and usable in the current phase.

## Collection Serialization Guide

Choose the mode that best fits your performance and audit requirements:

| Mode | Database Impact | Audit Detail | Recommended Use Case |
| :--- | :--- | :--- | :--- |
| **`lazy`** (Default) | **None** for uninitialized collections | Low | Returns an uninitialized placeholder for lazy Doctrine collections. |
| **`ids_only`** | Low | Medium to High | Serializes collection members as identifiers when IDs are available. |
| **`eager`** | Medium | High | Initializes lazy Doctrine collections before serializing them. |

If a collection exceeds `max_collection_items`, the stored payload is truncated to:

```json
{
  "_truncated": true,
  "_total_count": 250,
  "_sample": [1, 2, 3]
}
```
