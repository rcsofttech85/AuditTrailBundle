<?php

namespace Rcsofttech\AuditTrailBundle\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

/**
 * Responsible for providing a read-only view of audit logs in EasyAdmin.
 *
 * @codeCoverageIgnore
 *
 * @extends AbstractCrudController<AuditLog>
 */
class AuditLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Audit Log')
            ->setEntityLabelInPlural('Audit Logs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['entityClass', 'entityId', 'action', 'username', 'changedFields'])
            ->setPaginatorPageSize(30);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('entityClass', 'Entity');
        yield TextField::new('entityId', 'Entity ID');

        yield TextField::new('action', 'Action');

        yield TextField::new('username', 'User');
        yield DateTimeField::new('createdAt', 'Date');

        // Use CodeEditor with JavaScript for JSON syntax highlighting
        yield CodeEditorField::new('changedFields', 'Changes')
            ->setLanguage('javascript')  // Changed from 'json' to 'javascript'
            ->setNumOfRows(15)
            ->hideOnIndex();

        yield TextField::new('ipAddress', 'IP Address')->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('entityClass'))
            ->add(TextFilter::new('action'))
            ->add(TextFilter::new('username'))
            ->add(DateTimeFilter::new('createdAt'));
    }
}
