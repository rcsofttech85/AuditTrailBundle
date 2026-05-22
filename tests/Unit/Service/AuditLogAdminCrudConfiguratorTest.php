<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminCrudConfigurator;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminFieldProvider;
use Rcsofttech\AuditTrailBundle\Bridge\EasyAdmin\Service\AuditLogAdminLocator;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

final class AuditLogAdminCrudConfiguratorTest extends TestCase
{
    public function testConfigureCrudAppliesExpectedMetadata(): void
    {
        $configurator = $this->createConfigurator(self::createStub(AuditLogRepositoryInterface::class));
        $dto = $configurator->configureCrud(Crud::new())->getAsDto();

        self::assertSame('Audit Log', $dto->getEntityLabelInSingular());
        self::assertSame('Audit Logs', $dto->getEntityLabelInPlural());
        self::assertSame(['createdAt' => 'DESC'], $dto->getDefaultSort());
        self::assertSame(['entityClass', 'entityId', 'action', 'username', 'changedFields', 'transactionHash'], $dto->getSearchFields());
        self::assertSame(30, $dto->getPaginator()->getPageSize());
        self::assertSame([
            'crud/index' => '@AuditTrail/admin/audit_log/index.html.twig',
            'crud/detail' => '@AuditTrail/admin/audit_log/detail.html.twig',
        ], $dto->getOverriddenTemplates());
    }

    public function testConfigureResponseParametersAddsRevertFlagsOnDetailPage(): void
    {
        $repository = $this->createMock(AuditLogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('isReverted')
            ->willReturn(false);
        $repository->expects(self::once())
            ->method('hasNewerStateChangingLogs')
            ->willReturn(false);

        $configurator = $this->createConfigurator($repository);
        $responseParameters = KeyValueStore::new([
            'pageName' => Crud::PAGE_DETAIL,
            'entity' => $this->createEntityDto($this->createAuditLog()),
        ]);

        $configured = $configurator->configureResponseParameters($responseParameters);

        self::assertFalse($configured->get('is_reverted'));
        self::assertTrue($configured->get('can_revert'));
    }

    public function testConfigureResponseParametersLeavesNonDetailPagesUntouched(): void
    {
        $configurator = $this->createConfigurator(self::createStub(AuditLogRepositoryInterface::class));
        $responseParameters = KeyValueStore::new(['pageName' => Crud::PAGE_INDEX]);

        $configured = $configurator->configureResponseParameters($responseParameters);

        self::assertFalse($configured->has('can_revert'));
        self::assertFalse($configured->has('is_reverted'));
    }

    public function testConfigureActionsAssetsFieldsAndFilters(): void
    {
        $configurator = $this->createConfigurator(self::createStub(AuditLogRepositoryInterface::class));

        $actionsDto = $configurator->configureActions(Actions::new())->getAsDto(null);
        self::assertContains(Action::NEW, $actionsDto->getDisabledActions());
        self::assertContains(Action::EDIT, $actionsDto->getDisabledActions());
        self::assertContains(Action::DELETE, $actionsDto->getDisabledActions());
        self::assertSame('ROLE_AUDIT', $actionsDto->getActionPermissions()[Action::INDEX]);
        self::assertSame('ROLE_AUDIT', $actionsDto->getActionPermissions()[Action::DETAIL]);
        self::assertNotNull($actionsDto->getAction(Crud::PAGE_INDEX, Action::DETAIL));

        $assetsDto = $configurator->configureAssets(Assets::new())->getAsDto();
        self::assertCount(1, $assetsDto->getCssAssets());
        self::assertSame('bundles/audittrail/css/audit-trail-admin.css', array_values($assetsDto->getCssAssets())[0]->getValue());

        self::assertCount(6, iterator_to_array($configurator->configureFields(Crud::PAGE_INDEX), false));
        self::assertCount(21, iterator_to_array($configurator->configureFields(Crud::PAGE_DETAIL), false));
        self::assertCount(0, iterator_to_array($configurator->configureFields(Crud::PAGE_NEW), false));

        $filters = $configurator->configureFilters(Filters::new())->getAsDto()->all();
        self::assertSame(['entityClass', 'action', 'username', 'transactionHash', 'createdAt'], array_keys($filters));
    }

    private function createConfigurator(AuditLogRepositoryInterface $repository): AuditLogAdminCrudConfigurator
    {
        $integrity = self::createStub(AuditIntegrityServiceInterface::class);

        return new AuditLogAdminCrudConfigurator(
            new AuditLogAdminFieldProvider($integrity),
            new AuditLogAdminLocator($repository),
            'ROLE_AUDIT',
        );
    }

    /**
     * @return EntityDto<AuditLog>
     */
    private function createEntityDto(AuditLog $log): EntityDto
    {
        return new EntityDto(
            AuditLog::class,
            new ClassMetadata(AuditLog::class),
            null,
            $log,
        );
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
