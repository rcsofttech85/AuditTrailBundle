# AuditTrailBundle Benchmark Snapshot

Performance metrics for AuditTrailBundle measured with PHPBench via `composer benchmark:phpbench`.
This is a point-in-time local benchmark snapshot from April 4, 2026, not a release-wide performance guarantee.

## Environment

- **PHP**: 8.4.12
- **PHPBench**: 1.6.1
- **Database**: SQLite (in-memory)
- **Executor**: local
- **Xdebug**: Off
- **OPcache**: Off
- **Date**: 2026-04-04

## Methodology

- Benchmark suite: [benchmarks/AuditTrailBench.php](/home/rahul/AuditTrailBundle/benchmarks/AuditTrailBench.php)
- Bootstrap: [benchmarks/bootstrap.php](/home/rahul/AuditTrailBundle/benchmarks/bootstrap.php)
- Command:

```bash
composer benchmark:phpbench
```

- PHPBench reports below use:
  - `mode`: the modal execution time
  - `rstdev`: relative standard deviation
  - `revs`: revolutions per iteration
  - `its`: iteration count

## Results

### Entity Creation

| Subject | Revs | Its | Mode | Rstdev |
| :--- | ---: | ---: | ---: | ---: |
| `benchSingleInsertIntegerAuditingOn` | 10 | 5 | 2.440 ms | ±13.86% |
| `benchSingleInsertUuidAuditingOn` | 10 | 5 | 2.123 ms | ±18.49% |
| `benchSingleInsertAuditingOff` | 10 | 5 | 0.228 ms | ±15.01% |
| `benchBulkInsertTenAuditingOn` | 5 | 5 | 14.545 ms | ±5.25% |

### Transport Dispatch

These measurements isolate bundle-side dispatch overhead only. They do not include real broker, queue, or network latency.

| Subject | Revs | Its | Mode | Rstdev |
| :--- | ---: | ---: | ---: | ---: |
| `benchHttpTransportDispatch` | 1000 | 5 | 0.021 ms | ±11.90% |
| `benchQueueTransportDispatch` | 1000 | 5 | 0.028 ms | ±6.81% |
| `benchAsyncDatabaseDispatch` | 1000 | 5 | 0.018 ms | ±4.44% |

### Audit Log Queries

| Subject | Revs | Its | Mode | Rstdev |
| :--- | ---: | ---: | ---: | ---: |
| `benchFindByEntity` | 100 | 5 | 0.896 ms | ±15.62% |
| `benchFindByUser` | 100 | 5 | 1.570 ms | ±6.60% |
| `benchFindWithFilters` | 100 | 5 | 1.221 ms | ±3.35% |

### Purge

| Subject | Revs | Its | Mode | Rstdev |
| :--- | ---: | ---: | ---: | ---: |
| `benchDeleteThousandLogs` | 10 | 5 | 0.337 ms | ±56.98% |

### Integrity

| Subject | Revs | Its | Mode | Rstdev |
| :--- | ---: | ---: | ---: | ---: |
| `benchSignatureGeneration` | 1000 | 5 | 0.032 ms | ±9.94% |
| `benchSignatureVerification` | 1000 | 5 | 0.033 ms | ±7.11% |

## Takeaways

1. The audited write path is healthy. Single audited inserts stayed around `2.1-2.4 ms`, while audited bulk insert of 10 entities completed in about `14.5 ms`.
2. UUID-backed inserts were slightly faster than integer-ID inserts in this run.
3. Auditing disabled is substantially faster at `0.228 ms`, which gives a rough sense of the bundle overhead on the write path.
4. Transport dispatch overhead inside the bundle is extremely small, all below `0.03 ms` in this isolated benchmark.
5. Query performance is reasonable overall, with `findByUser` slower than `findByEntity` and `findWithFilters`.
6. Integrity signing and verification remain inexpensive at roughly `0.03 ms`.
7. The purge benchmark is noisy in this run. Because the deviation is high (`±56.98%`), treat that specific number as directional rather than stable.

## Notes

1. The benchmark suite boots a fresh test kernel and schema for each scenario, then measures the target operation with PHPBench iterations and revolutions.
2. Query benchmarks run against seeded audit-log fixtures in an in-memory SQLite database.
3. Transport benchmarks use in-memory doubles, so they reflect serialization and dispatch overhead inside the bundle, not external IO.
