<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Field;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Field\AuditActionField;

#[CoversClass(AuditActionField::class)]
final class AuditActionFieldTest extends TestCase
{
    public function testConfiguresEasyAdminFieldMetadata(): void
    {
        $field = AuditActionField::new('action', 'Action');
        $dto = $field->getAsDto();

        self::assertSame(AuditActionField::class, $dto->getFieldFqcn());
        self::assertSame('action', $dto->getProperty());
        self::assertSame('Action', $dto->getLabel());
        self::assertSame('@AuditTrail/admin/field/audit_action.html.twig', $dto->getTemplatePath());
        self::assertSame('col-md-6 col-xxl-5', $dto->getDefaultColumns());
    }

    public function testFormatsEnumAndStringValuesIntoHumanLabels(): void
    {
        $field = AuditActionField::new();
        $formatter = $field->getAsDto()->getFormatValueCallable();

        self::assertNotNull($formatter);
        self::assertSame('Soft Delete', $formatter(AuditAction::SoftDelete));
        self::assertSame('Restore', $formatter('restore'));
        self::assertSame('unexpected', $formatter('unexpected'));
    }
}
