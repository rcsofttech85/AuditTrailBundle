<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

/**
 * Keeps EasyAdmin page setup separate from request handling.
 */
final readonly class AuditLogAdminCrudConfigurator
{
    public function __construct(
        private AuditLogAdminFieldProvider $fieldProvider,
        private AuditLogAdminLocator $locator,
        private string $adminPermission,
    ) {
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Audit Log')
            ->setEntityLabelInPlural('Audit Logs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['entityClass', 'entityId', 'action', 'username', 'changedFields', 'transactionHash'])
            ->setPaginatorPageSize(30)
            ->overrideTemplates([
                'crud/index' => '@AuditTrail/admin/audit_log/index.html.twig',
                'crud/detail' => '@AuditTrail/admin/audit_log/detail.html.twig',
            ]);
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        /** @var \EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto<AuditLog> $entityDto */
        $entityDto = $responseParameters->get('entity');
        /** @var AuditLog $log */
        $log = $entityDto->getInstance();
        $isReverted = $this->locator->isReverted($log);
        $responseParameters->set('is_reverted', $isReverted);
        $responseParameters->set('can_revert', $this->locator->isUiRevertable($log, $isReverted));

        return $responseParameters;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->setPermission(Action::INDEX, $this->adminPermission)
            ->setPermission(Action::DETAIL, $this->adminPermission)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addCssFile('bundles/audittrail/css/audit-trail-admin.css');
    }

    /**
     * @return iterable<FieldInterface>
     */
    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_INDEX) {
            yield from $this->fieldProvider->indexFields();

            return;
        }

        if ($pageName !== Crud::PAGE_DETAIL) {
            return;
        }

        yield from $this->fieldProvider->overviewFields();
        yield from $this->fieldProvider->changesFields();
        yield from $this->fieldProvider->technicalContextFields();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('entityClass'))
            ->add(TextFilter::new('action'))
            ->add(TextFilter::new('username'))
            ->add(TextFilter::new('transactionHash'))
            ->add(DateTimeFilter::new('createdAt'));
    }
}
