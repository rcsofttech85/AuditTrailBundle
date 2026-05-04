# Contributing to AuditTrailBundle

Thanks for taking the time to contribute.

This bundle has a fairly broad feature surface now: Doctrine flush processing,
transports, admin UI, integrity checks, revert support, and extension points
for custom integrations. Good contributions are usually small, focused, and
well-tested.

## Before You Open a PR

1. Fork the repository and branch from `main`.
2. Install dependencies with `composer install`.
3. Make your change.
4. Run the local checks:

```bash
composer test
composer stan
composer cs
```

Or run everything in one step:

```bash
composer check
```

1. If you changed documentation, run:

```bash
composer lint:md
```

## What CI Checks

GitHub Actions currently checks:

- PHPUnit
- PHPStan
- PHP-CS-Fixer in dry-run mode
- Composer security audit
- Symfony compatibility on `7.4` and `8.0`
- Lowest and highest dependency sets
- Backward compatibility for public API changes

You do not need to reproduce the full matrix locally before every change, but
your PR should pass the standard local checks and should not knowingly break the
public API without a clear reason.

## Backward Compatibility

This bundle now has a CI workflow that checks backward compatibility for public
API changes.

For contributors, that usually means:

- changing public contracts in `src/Contract` is a BC-sensitive change
- changing public entities, value objects, or extension interfaces may be
  BC-sensitive too
- major releases can include intentional BC breaks, but they should still be
  documented clearly in the changelog and upgrade guide

If you are intentionally changing public API, say so clearly in the PR.

## Where To Make Changes

If you are new to the codebase, these are the main places to look:

- flush-time audit capture:
  `src/EventSubscriber/AuditSubscriber.php`,
  `src/Service/AuditOnFlushProcessor.php`,
  `src/Service/AuditPostFlushProcessor.php`,
  `src/Service/EntityProcessor.php`
- delivery and transports:
  `src/Service/AuditDispatcher.php`,
  `src/Transport/*`
- admin UI:
  `src/Controller/Admin/*`,
  `src/Service/AuditLogAdmin*`,
  `src/Resources/views/admin/*`
- integrity and verification:
  `src/Service/AuditIntegrity*`,
  `src/Command/VerifyIntegrityCommand.php`
- revert support:
  `src/Service/AuditReverter.php`,
  `src/Service/Revert*`
- queries and reader APIs:
  `src/Query/*`,
  `src/Repository/*`

For a broader map of the codebase and extension points, see
[`docs/advanced-usage.md`](docs/advanced-usage.md) and
[`docs/architecture.md`](docs/architecture.md).

## Extension Points

These are the main supported extension points:

- `AuditVoterInterface`
- `AuditContextContributorInterface`
- `AuditTransportInterface`
- `AuditLogAiProcessorInterface`
- `RevertActionHandlerInterface`
- `ScheduledAuditManagerInterface`

If your goal can be solved through one of these, prefer that over changing core
internals.

## Testing Guidance

As a rule of thumb:

- if you change flush behavior, add or update functional tests
- if you change service-level logic, add or update unit tests
- if you change a contract, update tests and docs
- if you change admin behavior, cover both logic and rendered/admin-facing paths

The current test suite is a better guide than old assumptions. If you are not
sure where coverage belongs, start by looking for the closest existing test in
`tests/Unit` or `tests/Functional`.

## Coding Standards

We follow Symfony coding standards and keep the code strongly typed.

Please keep changes:

- small and focused
- explicit rather than clever
- covered by tests when behavior changes
- documented when they affect public API or upgrade paths

## Reporting Issues

If you find a bug or have a feature request, please open an issue on GitHub
with reproduction steps and context.

## License

By contributing, you agree that your contributions will be licensed under the
MIT License.
