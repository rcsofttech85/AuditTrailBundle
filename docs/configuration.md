# Configuration Reference

Create `config/packages/audit_trail.yaml`.

For transport setup details, see
[Audit Transports](symfony-audit-transports.md).

```yaml
audit_trail:
    # Enable or disable the bundle globally
    enabled: true

    # Global list of properties to ignore
    # Defaults to common timestamp fields only
    ignored_properties: ['updatedAt', 'updated_at']

    # Optional prefix/suffix for the audit table name.
    # Empty values are allowed.
    # Non-empty values may contain only letters, numbers, and underscores
    # and must not start with a digit.
    table_prefix: ''
    table_suffix: ''

    # Global list of entities to ignore.
    # Use mapped entity classes such as App\Entity\Order.
    # The bundle maps Doctrine proxy/lazy subclasses back to that class automatically.
    ignored_entities: []

    # Retention period for database logs (in days)
    retention_days: 365

    # Context tracking
    track_ip_address: true
    track_user_agent: true

    # enable or disable delete tracking
    enable_hard_delete: true
    enable_soft_delete: true
    # The built-in restore flow clears this field by setting it back to null,
    # so use a nullable timestamp-like field such as deletedAt or archivedAt.
    soft_delete_field: 'deletedAt'

    # Attempt database-backed fallback persistence if another transport fails.
    # This uses the bundle's fallback path for the current phase and may persist
    # immediately or defer safely depending on the audit phase.
    fallback_to_database: true

    # Optional cache pool used for cross-request access-audit cooldowns.
    # If null, request-level deduplication still works, but cooldowns are not
    # persisted across requests.
    cache_pool: null

    easyadmin:
        # Required role/permission for EasyAdmin audit actions
        permission: 'ROLE_ADMIN'

        # Maximum number of rows a single EasyAdmin export request will stream.
        # This keeps browser-triggered exports bounded. Use the CLI export
        # command for larger or full-history exports.
        export_limit: 50000

    # Deprecated in 4.1; use the nested easyadmin config above instead.
    # admin_permission: 'ROLE_ADMIN'
    # admin_export_limit: 50000

    # HTTP methods eligible for access auditing
    audited_methods: ['GET']

    # Collection Serialization Performance
    # -----------------------------------
    collection_serialization_mode: 'lazy'
    max_collection_items: 100

    # In-memory queue limits for scheduled and deferred audit work.
    # These defaults protect long-running workers from unbounded growth if
    # audit delivery keeps failing and work must be retained for a later flush.
    queue_limits:
        scheduled_audits: 1000
        pending_audit_plans: 1000
        pending_deletions: 1000

    transports:
        # Store logs in the local database
        database:
            enabled: true
            async: false   # Set to true to persist via Messenger worker (requires symfony/messenger and an 'audit_trail_database' transport)

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
        secret: '%env(string:AUDIT_INTEGRITY_SECRET)%'
        algorithm: 'sha256'

    # Transaction Safety & Performance
    # --------------------------------
    # true (Default): Audits are delivered after the Doctrine postFlush boundary.
    #   - Pros: High performance, non-blocking. Main flush succeeds even if deferred audit delivery fails.
    #   - Pros: Avoids nested Doctrine flushes; deferred database writes use a dedicated writer path.
    #   - Cons: Small risk of "data without audit" if the process fails after the main flush and before delivery completes.
    #   - Recommended for: External transports (HTTP, Queue), or database transport in default deferred mode.
    #
    # false: Eligible transports may be attempted during onFlush.
    #   - Pros: Strict atomicity. Data and audit are committed together.
    #   - Cons: Slower. If an in-transaction transport fails, the entire transaction rolls back.
    #   - Note: Transport support is phase-specific. HTTP and queue transports still
    #     run in deferred phases such as postFlush/postLoad even when this is false.
    #   - Recommended for: Doctrine transport (when strict compliance is required).
    defer_transport_until_commit: true

    # If true, an exception in the transport will stop execution (and rollback if defer=false).
    # If false (default), transport errors are logged but execution continues.
    fail_on_transport_error: false
```

## Default Notes

- At least one transport must be enabled when `audit_trail.enabled` is `true`
- The database transport is enabled by default
- If `transports.http.enabled` or `transports.queue.enabled` is `true` and you
  leave `fail_on_transport_error` / `fallback_to_database` unset, the bundle
  sets them to `true` / `false` by default. Explicit values still win.
- Enabling `transports.database.async` or `transports.queue` without `symfony/messenger` installed throws a clear `LogicException`
- Enabling `transports.http` without `symfony/http-client` installed throws a clear `LogicException`
- `integrity.secret` is required only when `integrity.enabled` is `true`
- `http.endpoint` must start with `http://` or `https://` when HTTP transport is enabled
- `table_prefix` and `table_suffix` must be strings; non-empty values may contain only letters, numbers, and underscores and must not start with a digit
- `soft_delete_field` must not be empty and should point to a nullable timestamp-like field such as `deletedAt` or `archivedAt`
- the built-in restore flow clears that field by setting it to `null`; boolean or status-based soft-delete markers need a custom restore handler
- `max_collection_items` must be at least `1`
- `easyadmin.export_limit` must be at least `1`
- each `queue_limits` value must be at least `1`
- If `cache_pool` is `null`, access-audit cooldowns are request-local only; cross-request cooldown persistence is disabled

## Package Requirements By Feature

Install additional packages only for the features you enable:

- Synchronous database transport: no extra package required
- `transports.database.async: true`: install `symfony/messenger`
- `transports.queue.enabled: true`: install `symfony/messenger`
- `transports.http.enabled: true`: install `symfony/http-client`
- EasyAdmin UI: install `easycorp/easyadmin-bundle` and enable `EasyAdminBundle`

## Transaction Safety Guide

These three options control how the bundle behaves when delivery fails:

| Option | Default | What It Changes |
| :--- | :--- | :--- |
| `defer_transport_until_commit` | `true` | Delivers audits after the Doctrine `postFlush` boundary instead of during `onFlush`. |
| `fail_on_transport_error` | `false` | Escalates transport exceptions instead of logging and continuing. |
| `fallback_to_database` | `true` | Tries database-backed fallback persistence when another transport fails. |

These are the base defaults. When HTTP or queue transport is enabled and you
leave the failure-handling flags unset, the bundle changes them to
`fail_on_transport_error: true` and `fallback_to_database: false`.

### Recommended combinations

| Goal | Recommended Settings | Result |
| :--- | :--- | :--- |
| Best default safety/performance | `defer_transport_until_commit: true`, `fail_on_transport_error: false` | Main writes succeed even if HTTP/queue delivery fails after the main flush. |
| Strict in-transaction auditing | `defer_transport_until_commit: false`, `fail_on_transport_error: true`, `database.enabled: true`, `database.async: false` | For the synchronous database transport path, data and audit fail together when the audit cannot be recorded safely. |
| External transport with local safety net | `defer_transport_until_commit: false`, `fail_on_transport_error: false`, `fallback_to_database: true`, `database.enabled: true` | External transport failures can still be captured locally without requiring a nested `flush()` during `onFlush`. |

### Important behavior notes

- When `defer_transport_until_commit` is `false`, the bundle still does not call `flush()` from inside Doctrine `onFlush`.
- Transport support is still phase-specific when `defer_transport_until_commit` is `false`. For example, HTTP and queue delivery still happen in deferred phases rather than inside the Doctrine transaction, so this setting does not put every enabled transport in the same transaction boundary.
- If fallback is needed during `onFlush`, the database audit entity is attached through Doctrine `UnitOfWork` change-set computation and joins the application's existing flush.
- If `defer_transport_until_commit` is `true`, there is a small but real window where the main transaction can commit and the audit delivery can fail afterward. This is the default performance trade-off for HTTP and queue transports.
- In deferred database mode, the bundle no longer performs a follow-up ORM `flush()` from `postFlush`. Deferred `AuditLog` rows are written through a dedicated database writer instead.
- Because deferred database writes use a dedicated writer, Doctrine ORM lifecycle callbacks/listeners on `AuditLog` are not involved in that deferred path.
- When `fallback_to_database` is enabled, the dispatcher uses the bundle's own fallback path for each phase. On `onFlush` it joins the current `UnitOfWork`; on deferred and manual phases it writes through the dedicated database writer; and on failure it logs the fallback failure clearly.
- If transport delivery keeps failing in a long-running process, the bundle retains failed work for a later flush. The `queue_limits` settings cap that in-memory retention so workers fail loudly instead of growing without bound.
- EasyAdmin exports respect the active admin filters first, then apply `easyadmin.export_limit`. If you need a larger export, prefer the CLI command.

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
