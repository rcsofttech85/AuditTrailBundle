<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminFieldProvider;

use function sprintf;

final class AuditLogAdminFieldProviderTest extends TestCase
{
    public function testIndexFieldsExposeExpectedMetadataAndFormatters(): void
    {
        $provider = new AuditLogAdminFieldProvider(self::createStub(AuditIntegrityServiceInterface::class));
        $fields = iterator_to_array($provider->indexFields(), false);

        self::assertCount(6, $fields);

        $idDto = $this->fieldDto($fields, 'id');
        self::assertTrue($idDto->isDisplayedOn(Crud::PAGE_INDEX));
        self::assertFalse($idDto->isDisplayedOn(Crud::PAGE_DETAIL));

        $entityClassDto = $this->fieldDto($fields, 'entityClass');
        self::assertSame('Entity', $entityClassDto->getLabel());
        self::assertSame('The PHP class of the modified entity', $entityClassDto->getHelp());

        $formatter = $entityClassDto->getFormatValueCallable();
        self::assertNotNull($formatter);
        self::assertSame('Order', $formatter('App\\Entity\\Order'));
        self::assertSame('', $formatter(['not', 'scalar']));
    }

    public function testOverviewFieldsIncludeExpectedLayoutAndIpFormatter(): void
    {
        $provider = new AuditLogAdminFieldProvider(self::createStub(AuditIntegrityServiceInterface::class));
        $fields = iterator_to_array($provider->overviewFields(), false);

        self::assertCount(12, $fields);

        $ipAddressDto = $this->fieldDto($fields, 'ipAddress');
        self::assertSame('IP Address', $ipAddressDto->getLabel());
        self::assertSame('col-md-6', $ipAddressDto->getColumns());

        $formatter = $ipAddressDto->getFormatValueCallable();
        self::assertNotNull($formatter);
        self::assertSame('N/A', $formatter(null));
        self::assertSame('127.0.0.1', $formatter('127.0.0.1'));
        self::assertSame('', $formatter(['unsupported']));
    }

    public function testChangesFieldsShowDisabledIntegrityBadgeWhenIntegrityChecksAreOff(): void
    {
        $integrity = $this->createMock(AuditIntegrityServiceInterface::class);
        $integrity->expects(self::once())
            ->method('isEnabled')
            ->willReturn(false);
        $integrity->expects(self::never())
            ->method('verifySignature');

        $provider = new AuditLogAdminFieldProvider($integrity);
        $signatureDto = $this->fieldDto(iterator_to_array($provider->changesFields(), false), 'signature');
        $formatter = $signatureDto->getFormatValueCallable();

        self::assertNotNull($formatter);
        self::assertStringContainsString('Integrity Disabled', $formatter(null, $this->createAuditLog()));
    }

    public function testChangesFieldsShowVerifiedIntegrityBadgeWhenSignatureIsValid(): void
    {
        $integrity = $this->createMock(AuditIntegrityServiceInterface::class);
        $integrity->expects(self::once())
            ->method('isEnabled')
            ->willReturn(true);
        $integrity->expects(self::once())
            ->method('verifySignature')
            ->with(self::isInstanceOf(AuditLog::class))
            ->willReturn(true);

        $provider = new AuditLogAdminFieldProvider($integrity);
        $signatureDto = $this->fieldDto(iterator_to_array($provider->changesFields(), false), 'signature');
        $formatter = $signatureDto->getFormatValueCallable();

        self::assertNotNull($formatter);
        self::assertStringContainsString('Verified Authentic', $formatter(null, $this->createAuditLog()));
    }

    public function testChangesFieldsShowTamperedBadgeWhenSignatureVerificationFails(): void
    {
        $integrity = $this->createMock(AuditIntegrityServiceInterface::class);
        $integrity->expects(self::once())
            ->method('isEnabled')
            ->willReturn(true);
        $integrity->expects(self::once())
            ->method('verifySignature')
            ->with(self::isInstanceOf(AuditLog::class))
            ->willReturn(false);

        $provider = new AuditLogAdminFieldProvider($integrity);
        $fields = iterator_to_array($provider->changesFields(), false);
        self::assertCount(5, $fields);

        $signatureDto = $this->fieldDto($fields, 'signature');
        $formatter = $signatureDto->getFormatValueCallable();
        self::assertNotNull($formatter);
        self::assertStringContainsString('Tampered / Invalid', $formatter(null, $this->createAuditLog()));

        $changedFieldsDto = $this->fieldDto($fields, 'changedFields');
        self::assertSame('@AuditTrail/admin/audit_log/field/diff_view.html.twig', $changedFieldsDto->getTemplatePath());
        self::assertSame('col-md-12', $changedFieldsDto->getColumns());
    }

    public function testTechnicalContextFieldsUseExpectedTemplatesAndHelpText(): void
    {
        $provider = new AuditLogAdminFieldProvider(self::createStub(AuditIntegrityServiceInterface::class));
        $fields = iterator_to_array($provider->technicalContextFields(), false);

        self::assertCount(4, $fields);

        $transactionHashDto = $this->fieldDto($fields, 'transactionHash');
        self::assertSame('@AuditTrail/admin/audit_log/field/transaction_link.html.twig', $transactionHashDto->getTemplatePath());
        self::assertSame('Click to see all changes in this transaction.', $transactionHashDto->getHelp());

        $contextDto = $this->fieldDto($fields, 'context');
        self::assertSame('@AuditTrail/admin/audit_log/field/context_view.html.twig', $contextDto->getTemplatePath());
        self::assertSame('Structured view of context metadata.', $contextDto->getHelp());
    }

    /**
     * @param list<FieldInterface> $fields
     */
    private function fieldDto(array $fields, string $property): FieldDto
    {
        foreach ($fields as $field) {
            $dto = $field->getAsDto();
            if ($property === $dto->getProperty()) {
                return $dto;
            }
        }

        self::fail(sprintf('Field "%s" was not found.', $property));
    }

    private function createAuditLog(AuditAction $action = AuditAction::Update): AuditLog
    {
        return new AuditLog(
            'App\\Entity\\Order',
            '42',
            $action,
        );
    }
}
