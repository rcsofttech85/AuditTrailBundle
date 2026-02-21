# AuditTrailBundle v2 — Benchmarks

Performance metrics for the AuditTrailBundle v2, measured with [PHPBench](https://github.com/phpbench/phpbench).

## Environment

- **PHP**: 8.4.12 (CLI, NTS)
- **Database**: SQLite (in-memory)
- **OPcache**: Off
- **Xdebug**: Off
- **Tool**: PHPBench 1.4.3
- **Date**: 2026-02-21

## Results

### Entity Creation (Flush Overhead)

This compares the overhead of creating a single audited entity, side-by-side for entities with standard **Integer IDs** vs **UUIDs**, as well as with HMAC Integrity **ON** vs **OFF**.

| Operation | Integer ID (Time) | UUID (Time) | Memory (peak) |
| :--- | :--- | :--- | :--- |
| **Single Insert (Auditing ON + HMAC ON)** | ~10.2ms / flush | ~7.3ms / flush | ~14.3 MB |
| **Single Insert (Auditing ON + HMAC OFF)** | ~10.6ms / flush | ~7.4ms / flush | ~14.3 MB |
| **Single Insert (Auditing OFF)** | ~0.8ms / flush | ~0.9ms / flush | ~13.5 MB |
| **Bulk Insert ×10 (Auditing ON + HMAC ON)** | ~31.3ms / flush | ~31.7ms / flush | ~13.7 MB |

*(Note: Small variance in total times across runs is normal due to environmental noise, but relative differences remain stable).*

### Transport Lifecycle Overhead

This compares the synchronous CPU cost of dispatching the audit event to the three supported transports, and then measures the Consumer Worker throughput.

| Transport / Component | Time / item | Description |
| :--- | :--- | :--- |
| **Doctrine (Sync write)** | ~9.5ms | Full synchronous SQL insert loop natively. |
| **HTTP (Async Dispatch)** | ~11.4ms | Payload serialization + HTTP Client network dispatch. |
| **Queue (Async Dispatch)** | ~12.2ms | Payload instantiation + Messenger Stamp Event + Bus dispatch. |
| **Queue Worker (Consumer)** | ~1.45ms | Extract `AuditLogMessage`, instantiate entity, generate signature, insert to DB. |

### Audit Log Retrieval (50 logs seeded)

| Operation | Revs | Iterations | Time (mode) | Deviation | Memory (peak) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **findByEntity** | 10 | 5 | 7.67ms | ±3.34% | 15.15 MB |
| **findByUser** | 10 | 5 | 6.11ms | ±1.65% | 15.13 MB |
| **findWithFilters** | 10 | 5 | 5.20ms | ±1.61% | 15.15 MB |

### Purge

| Operation | Revs | Iterations | Time (mode) | Deviation | Memory (peak) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Delete 1,000 logs** | 1 | 5 | 27.84ms | ±2.96% | 17.65 MB |

### HMAC Integrity Overhead (Pure CPU)

| Operation | Revs | Iterations | Time (mode) | Deviation | Memory (peak) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Signature Generation** | 100 | 5 | 0.028ms | ±1.45% | 4.30 MB |
| **Signature Verification** | 100 | 5 | 0.030ms | ±2.40% | 4.30 MB |

## Analysis & v1 vs v2 Comparison

| Metric | v1 (Old) | v2 (New) | Difference |
| --- | --- | --- | --- |
| Audit Overhead | ~1.66ms | ~7ms - ~10ms | Slower (due to HMAC, Context, Voters, Split-phase processing) |
| Baseline (Disabled) | ~0.68ms | ~0.8ms - ~0.9ms | Comparable |
| Retrieval | ~5.60ms (10 logs) | ~5.20ms (50 logs) | Faster (Same time for 5x more data due to indexes) |
| Purge (1,000 logs) | ~44.14ms | ~27.84ms | Faster (Optimized DQL DELETE) |

### Key Takeaways

1. **Async Worker Throughput**: When using the Queue transport, a background Messenger worker converting an `AuditLogMessage` back into an `AuditLog` entity, verifying the payload, and flushing it to the database takes roughly **~1.45ms per message**. That equals a staggering **~690 messages per second** per worker thread, making it perfect for high-traffic environments.
2. **Transport Impact**: Interestingly, the synchronous **Doctrine Transport (~9.5ms) is currently the fastest** synchronous path in typical PHP setups because in-memory SQLite executes SQL virtually instantly. The **Queue (~12.2ms)** and **HTTP (~11.4ms)** transports are slightly "slower" during the synchronous execution phase strictly because they require deep object serialization (`json_encode` for HTTP, full `AuditLogMessage` instantiation + Messenger routing for Queue). *However, in a real-world production app with a networked database, Doctrine's latency would skyrocket, making Queue/HTTP effectively faster for the end-user request by offloading the actual IO to the highly efficient workers.*
3. **HMAC Integrity Impact**: Disabling HMAC Integrity has **virtually no impact** on flush performance. The `AuditIntegrityBench` isolates the pure CPU cost of signing at ~0.03ms. Given flush operations take 7-10ms, this 30-microsecond sub-operation is lost in the noise. **Recommendation**: Leave Integrity ON.
4. **Integer ID vs UUID**: Audited **UUID entities consistently perform slightly faster** (~7.3ms) on insert than Integer ID entities (~10.2ms). This is because the bundle's `EntityIdResolver` doesn't have to wait for Doctrine to assign an auto-incremented ID post-flush to build the audit log reference.
5. **Bulk Efficiency**: At scale (10 entities per flush), the cost drops significantly, averaging under **~3ms per entity** (30ms total for 10), demonstrating efficient batched processing in the UnitOfWork.

## Time per Operation (ms)

```text
Int ID Insert (ON + HMAC ON)   ██████████████████████████████████████████████████ 10.2 ms
UUID Insert (ON + HMAC ON)     ████████████████████████████████████ 7.3 ms
Int ID Insert (Audit OFF)      ████ 0.8 ms
Bulk Insert ×10 (ON)           ██████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████ 31.3 ms
Transport: Doctrine            ███████████████████████████████████████████████ 9.5 ms
Transport: HTTP (Dispatch)     ████████████████████████████████████████████████████████ 11.4 ms
Transport: Queue (Dispatch)    ████████████████████████████████████████████████████████████ 12.2 ms
Queue Worker Extractor         ███████ 1.45 ms
Purge 1,000 logs               █████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████████ 27.8 ms
```
