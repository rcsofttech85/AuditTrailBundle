<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;

/**
 * Responsible for providing a read-only view of audit logs in EasyAdmin.
 *
 * @codeCoverageIgnore
 *
 * @extends AbstractCrudController<AuditLog>
 */
class AuditLogCrudController extends AbstractCrudController
{
    use ClassNameHelperTrait;

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
            ->setSearchFields(['entityClass', 'entityId', 'action', 'username', 'changedFields', 'transactionHash'])
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
        yield from $this->configureIndexFields();
        yield from $this->configureOverviewTabFields();
        yield from $this->configureChangesTabFields();
        yield from $this->configureTechnicalContextTabFields();
    }

    /**
     * @return iterable<int, mixed>
     */
    private function configureIndexFields(): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield ChoiceField::new('action', 'Action')
            ->setChoices([
                'Create' => AuditLogInterface::ACTION_CREATE,
                'Update' => AuditLogInterface::ACTION_UPDATE,
                'Delete' => AuditLogInterface::ACTION_DELETE,
                'Soft Delete' => AuditLogInterface::ACTION_SOFT_DELETE,
                'Restore' => AuditLogInterface::ACTION_RESTORE,
                'Revert' => AuditLogInterface::ACTION_REVERT,
            ])
            ->renderAsBadges([
                AuditLogInterface::ACTION_CREATE => 'success',
                AuditLogInterface::ACTION_UPDATE => 'warning',
                AuditLogInterface::ACTION_DELETE => 'danger',
                AuditLogInterface::ACTION_SOFT_DELETE => 'danger',
                AuditLogInterface::ACTION_RESTORE => 'info',
                AuditLogInterface::ACTION_REVERT => 'primary',
            ])
            ->onlyOnIndex();

        yield TextField::new('entityClass', 'Entity')
            ->formatValue(fn ($value): string => $this->shortenClass((string) $value))
            ->setHelp('The PHP class of the modified entity')
            ->onlyOnIndex();

        yield TextField::new('entityId', 'ID')->onlyOnIndex();
        yield TextField::new('username', 'User')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Occurred At')
            ->setFormat('dd MMM yyyy | HH:mm:ss')
            ->onlyOnIndex();
    }

    /**
     * @return iterable<int, mixed>
     */
    private function configureOverviewTabFields(): iterable
    {
        yield FormField::addTab('Overview')->setIcon('fa fa-info-circle');
        yield FormField::addPanel()->setHelp('Basic information about the audit event.');

        yield IdField::new('id', 'Audit Log ID')->onlyOnDetail();
        yield TextField::new('entityClass', 'Entity Class')->onlyOnDetail();
        yield TextField::new('entityId', 'Entity ID')->onlyOnDetail();
        yield TextField::new('action', 'Action Type')->onlyOnDetail();

        yield FormField::addRow();
        yield TextField::new('username', 'Performed By')->onlyOnDetail()->setColumns(6);
        yield DateTimeField::new('createdAt', 'Timestamp')
            ->setFormat('dd MMM yyyy | HH:mm:ss')
            ->onlyOnDetail()
            ->setColumns(6);

        yield FormField::addRow();
        yield TextField::new('ipAddress', 'IP Address')
            ->formatValue(static fn ($value): string => (null !== $value && '' !== $value) ? $value : 'N/A')
            ->renderAsHtml()
            ->onlyOnDetail()
            ->setColumns(6);

        yield TextField::new('userAgent', 'User Agent')->onlyOnDetail()->setColumns(6);
    }

    /**
     * @return iterable<int, mixed>
     */
    private function configureChangesTabFields(): iterable
    {
        yield FormField::addTab('Changes')->setIcon('fa fa-exchange-alt');
        yield FormField::addPanel()->setHelp('Visual comparison of the entity state before and after the change.');

        yield FormField::addRow();
        yield $this->createJsonField('changedFields', 'Changed Fields')
            ->setColumns(12)
            ->setHelp('List of properties that were modified in this transaction.');

        yield FormField::addRow();
        yield $this->createJsonField('oldValues', 'Old Values')
            ->setColumns(6)
            ->setHelp('State of the entity <strong>before</strong> the change.');

        yield $this->createJsonField('newValues', 'New Values')
            ->setColumns(6)
            ->setHelp('State of the entity <strong>after</strong> the change.');
    }

    /**
     * @return iterable<int, mixed>
     */
    private function configureTechnicalContextTabFields(): iterable
    {
        yield FormField::addTab('Technical Context')->setIcon('fa fa-cogs');
        yield FormField::addPanel()->setHelp('Low-level transaction details and custom context metadata.');

        yield TextField::new('transactionHash', 'Transaction Hash')
            ->onlyOnDetail()
            ->setHelp('Unique identifier grouping all changes that happened in the same database transaction.');

        yield $this->createJsonField('context', 'Full Context')
            ->setHelp('Additional metadata such as impersonation details, request ID, or custom attributes.');
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

    private function createJsonField(string $propertyName, string $label): CodeEditorField
    {
        return CodeEditorField::new($propertyName, $label)
            ->setLanguage('javascript')
            ->formatValue(fn ($value): string => $this->formatJson($value))
            ->onlyOnDetail();
    }

    private function formatJson(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_array($value) => (false !== ($json = json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )) ? $json : ''),
            default => (string) $value,
        };
    }
}
