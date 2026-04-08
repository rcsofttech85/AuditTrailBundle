# AuditReader API Documentation

The **AuditReader** provides a fluent, immutable, and expressive API to query audit logs programmatically in your Symfony application.

It is designed for:

- Entity-level audit history
- Advanced filtering (actions, users, fields, transactions)
- Diff inspection
- Pagination and collections
- Read-only, side-effect-free querying

All query methods are chainable and return **new immutable query instances**.

---

## 1. Injecting the AuditReader

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Contract\AuditReaderInterface;

class UserController extends AbstractController
{
    public function __construct(
        private readonly AuditReaderInterface $auditReader
    ) {
    }
}
```

## Get complete audit history for a specific entity instance

```php
<?php

declare(strict_types=1);

$user = $userRepository->find(123);
$history = $this->auditReader->getHistoryFor($user);

foreach ($history as $entry) {
    printf(
        "%s: %s by %s at %s\n",
        $entry->action,          // create, update, delete
        $entry->entityShortName, // User
        $entry->username,        // admin@example.com
        $entry->createdAt->format('Y-m-d H:i:s')
    );
}
```

## Building Custom Queries

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

// Find all updates to User entities in the last 30 days
$recentUpdates = $this->auditReader
    ->forEntity(User::class)
    ->updates()
    ->since(new \DateTimeImmutable('-30 days'))
    ->limit(50)
    ->getResults();

// Find all deletions by a specific admin
$deletions = $this->auditReader
    ->byUser('1')
    ->action(
        AuditLogInterface::ACTION_DELETE,
        AuditLogInterface::ACTION_SOFT_DELETE
    )
    ->getResults();
```

## Find everything that happened in a single transaction

```php
<?php

declare(strict_types=1);

$transactionAudits = $this->auditReader
    ->byTransaction('019b5aca-60ed-70bf-b139-255aa96c96cb')
    ->getResults();
```

## Find updates where a specific field was changed

```php
<?php

declare(strict_types=1);

$emailChanges = $this->auditReader
    ->forEntity(User::class)
    ->updates()
    ->changedField('email')
    ->getResults();
```

## Working with Diffs

```php
<?php

declare(strict_types=1);

$entry = $this->auditReader
    ->forEntity(User::class, '123')
    ->updates()
    ->getFirstResult();

if ($entry) {
    $diff = $entry->diff;
    /*
     * Example:
     * [
     *   'name'  => ['old' => 'John', 'new' => 'Jane'],
     *   'email' => ['old' => 'a@x.com', 'new' => 'b@x.com']
     * ]
     */

    if ($entry->hasFieldChanged('email')) {
        $oldEmail = $entry->getOldValue('email');
        $newEmail = $entry->getNewValue('email');

        echo "Email changed from $oldEmail to $newEmail";
    }

    $changedFields = $entry->changedFields; // ['name', 'email']
}
```

## Working with Collections

```php
<?php

declare(strict_types=1);

$audits = $this->auditReader
    ->forEntity(Order::class)
    ->between(
        new \DateTimeImmutable('2024-01-01'),
        new \DateTimeImmutable('2024-12-31')
    )
    ->getResults();
```

## Filter results in memory

```php
<?php

declare(strict_types=1);

$priceChanges = $audits->filter(
    fn ($entry) => $entry->hasFieldChanged('price')
);
```

## Grouping results

```php
<?php

declare(strict_types=1);

$byAction = $audits->groupByAction();
// ['create' => [...], 'update' => [...], 'delete' => [...]

$byEntity = $audits->groupByEntity();
// ['App\Entity\Order' => [...]]
```

## Collection utilities

```php
<?php

declare(strict_types=1);

$first = $audits->first();
$last = $audits->last();
$count = $audits->count();
$empty = $audits->isEmpty();
```

## Cursor Pagination

```php
<?php

declare(strict_types=1);

$page1 = $this->auditReader
    ->forEntity(Product::class)
    ->limit(25)
    ->getResults();

$nextCursor = $this->auditReader
    ->forEntity(Product::class)
    ->limit(25)
    ->getNextCursor();

$page2 = $this->auditReader
    ->forEntity(Product::class)
    ->after($nextCursor)
    ->limit(25)
    ->getResults();
```

Cursor and limit rules:

- `limit()` must be greater than `0`, otherwise the query throws an `InvalidArgumentException`.
- `after()` and `before()` expect a valid audit-log UUID string.
- `changedField()` supports forward keyset pagination with `after()`, but reverse pagination with `before()` is intentionally rejected because the bundle cannot guarantee stable changed-field filtering in that direction.

```php
<?php

declare(strict_types=1);

use InvalidArgumentException;
use LogicException;

try {
    $this->auditReader
        ->forEntity(Product::class)
        ->changedField('price')
        ->before('not-a-uuid')
        ->limit(0)
        ->getResults();
} catch (InvalidArgumentException|LogicException $e) {
    // invalid limit, invalid cursor, or unsupported reverse changedField() pagination
}
```

## Check if matching records exist

```php
<?php

declare(strict_types=1);

$hasDeletes = $this->auditReader
    ->forEntity(User::class)
    ->deletes()
    ->exists();
```

## Check if an entity has any audit history

```php
$hasHistory = $this->auditReader->hasHistoryFor($entity);
```
