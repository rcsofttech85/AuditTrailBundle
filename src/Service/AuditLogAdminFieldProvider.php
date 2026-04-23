<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;
use Stringable;

use function array_flip;
use function is_scalar;

final class AuditLogAdminFieldProvider
{
    use ClassNameHelperTrait;

    private const array ACTION_LABELS = [
        AuditLogInterface::ACTION_CREATE => 'Create',
        AuditLogInterface::ACTION_UPDATE => 'Update',
        AuditLogInterface::ACTION_DELETE => 'Delete',
        AuditLogInterface::ACTION_SOFT_DELETE => 'Soft Delete',
        AuditLogInterface::ACTION_RESTORE => 'Restore',
        AuditLogInterface::ACTION_REVERT => 'Revert',
        AuditLogInterface::ACTION_ACCESS => 'Access',
    ];

    private const array ACTION_BADGES = [
        AuditLogInterface::ACTION_CREATE => 'success',
        AuditLogInterface::ACTION_UPDATE => 'warning',
        AuditLogInterface::ACTION_DELETE => 'danger',
        AuditLogInterface::ACTION_SOFT_DELETE => 'danger',
        AuditLogInterface::ACTION_RESTORE => 'info',
        AuditLogInterface::ACTION_REVERT => 'primary',
        AuditLogInterface::ACTION_ACCESS => 'secondary',
    ];

    public function __construct(
        private readonly AuditIntegrityServiceInterface $integrityService,
    ) {
    }

    /**
     * @return iterable<FieldInterface>
     */
    public function indexFields(): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield ChoiceField::new('action', 'Action')
            ->setChoices(array_flip(self::ACTION_LABELS))
            ->renderAsBadges(self::ACTION_BADGES)
            ->onlyOnIndex();

        yield TextField::new('entityClass', 'Entity')
            ->formatValue(fn ($value): string => $this->shortenClass((string) (is_scalar($value) || $value instanceof Stringable ? $value : '')))
            ->setHelp('The PHP class of the modified entity')
            ->onlyOnIndex();

        yield TextField::new('entityId', 'Entity ID')->onlyOnIndex();
        yield TextField::new('username', 'User')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Occurred At')
            ->setFormat('dd MMM yyyy | HH:mm:ss')
            ->onlyOnIndex();
    }

    /**
     * @return iterable<FieldInterface>
     */
    public function overviewFields(): iterable
    {
        yield FormField::addTab('Overview')->setIcon('fa fa-info-circle');
        yield FormField::addFieldset()->setHelp('Basic information about the audit event.');

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
            ->formatValue(static fn ($value): string => ($value !== null && $value !== '') ? (string) (is_scalar($value) || $value instanceof Stringable ? $value : '') : 'N/A')
            ->onlyOnDetail()
            ->setColumns(6);

        yield TextField::new('userAgent', 'User Agent')->onlyOnDetail()->setColumns(6);
    }

    /**
     * @return iterable<FieldInterface>
     */
    public function changesFields(): iterable
    {
        yield FormField::addTab('Changes')->setIcon('fa fa-exchange-alt');
        yield FormField::addFieldset()->setHelp('Visual comparison of the entity state before and after the change.');

        yield TextField::new('signature', 'Integrity Signature')
            ->formatValue($this->formatIntegrityStatus(...))
            ->renderAsHtml()
            ->onlyOnDetail();

        yield FormField::addRow();
        yield CodeEditorField::new('changedFields', 'Visual Diff')
            ->setTemplatePath('@AuditTrail/admin/audit_log/field/diff_view.html.twig')
            ->setColumns(12)
            ->onlyOnDetail();
    }

    /**
     * @return iterable<FieldInterface>
     */
    public function technicalContextFields(): iterable
    {
        yield FormField::addTab('Context')->setIcon('fa fa-cogs');
        yield FormField::addFieldset()->setHelp('Low-level transaction details and custom context metadata.');

        yield TextField::new('transactionHash', 'Transaction Hash')
            ->setTemplatePath('@AuditTrail/admin/audit_log/field/transaction_link.html.twig')
            ->onlyOnDetail()
            ->setHelp('Click to see all changes in this transaction.');

        yield CodeEditorField::new('context', 'Context Details')
            ->setTemplatePath('@AuditTrail/admin/audit_log/field/context_view.html.twig')
            ->onlyOnDetail()
            ->setHelp('Structured view of context metadata.');
    }

    private function formatIntegrityStatus(mixed $value, AuditLog $log): string
    {
        if (!$this->integrityService->isEnabled()) {
            return '<span class="badge badge-secondary text-muted"><i class="fa fa-shield-alt me-1"></i> Integrity Disabled</span>';
        }

        if ($this->integrityService->verifySignature($log)) {
            return '<span class="badge badge-success text-success" style="background: rgba(25, 135, 84, 0.1);"><i class="fa fa-check-circle me-1"></i> Verified Authentic</span>';
        }

        return '<span class="badge badge-danger text-danger" style="background: rgba(220, 53, 69, 0.1);"><i class="fa fa-times-circle me-1"></i> Tampered / Invalid</span>';
    }
}
