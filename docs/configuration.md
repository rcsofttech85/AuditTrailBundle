# Configuration Reference

Create a configuration file at `config/packages/audit_trail.yaml`.

For detailed transport configuration and usage, see [Audit Transports](symfony-audit-transports.md).

```yaml
audit_trail:
    # Enable or disable the bundle globally
    enabled: true

    # Global list of properties to ignore (e.g., timestamps)
    ignored_properties: ['updatedAt', 'updated_at', 'password', 'token']

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

        # Dispatch logs to a message queue
        queue:
            enabled: false
            bus: 'messenger.bus.default' # Optional: specify a custom bus
            
    # Integrity & Signing
    # -------------------
    # Enable cryptographic signing of audit logs to prevent tampering.
    integrity:
        enabled: true
        secret: '%env(AUDIT_INTEGRITY_SECRET)%'
        algorithm: 'sha256'

    # Transaction Safety & Performance
    # --------------------------------
    # true (Default): Audits are sent AFTER the transaction commits (postFlush).
    #   - Pros: High performance, non-blocking. Main transaction succeeds even if audit fails.
    #   - Cons: Small risk of "data without audit" if app crashes between commit and audit.
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

## Collection Serialization Guide

Choose the mode that best fits your performance and audit requirements:

| Mode | Database Impact | Audit Detail | Recommended Use Case |
| :--- | :--- | :--- | :--- |
| **`lazy`** (Default) | **None** (Zero queries) | Low (shows placeholder) | High-traffic apps where performance is the #1 priority. |
| **`ids_only`** | **Low** (1 targeted query) | High (shows all item IDs) | **Best for most apps.** Relationship visibility with low memory. |
| **`eager`** | **Medium** (Full hydration) | High (Full entities) | Only if custom code needs to inspect full entity state. |
