# Integrations

## EasyAdmin Integration

Add the `AuditLogCrudController` to your `DashboardController`:

```php
<?php

declare(strict_types=1);

use Rcsofttech\AuditTrailBundle\Controller\Admin\AuditLogCrudController;

yield MenuItem::linkTo(AuditLogCrudController::class, 'Audit Logs', 'fas fa-history');
```

### Publishing Assets

To ensure the custom styling for the Audit Log UI (diffs, action badges, and revert modals) loads correctly, you must install the bundle's public assets:

```bash
php bin/console assets:install
```

![EasyAdmin Integration Showcase](../.github/assets/easyadmin_integration_dark.png)
