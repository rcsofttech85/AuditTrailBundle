<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\SoftDeleteFilterManager;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\DummySoftDeleteableFilter;

use function in_array;

final class SoftDeleteFilterManagerTest extends TestCase
{
    public function testDisableDisablesConfiguredFilterNames(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $entityManager = self::createStub(EntityManagerInterface::class);
        $manager = new SoftDeleteFilterManager(['custom_soft_delete']);

        $entityManager->method('getFilters')->willReturn($filters);
        $filters->method('getEnabledFilters')->willReturn([
            'custom_soft_delete' => new class {},
            'tenant' => new class {},
        ]);
        $filters->expects($this->once())->method('suspend')->with('custom_soft_delete');

        self::assertSame(['custom_soft_delete'], $manager->disable($entityManager));
    }

    public function testDisableRecognizesGedmoFilterClassExactly(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $entityManager = self::createStub(EntityManagerInterface::class);
        $manager = new SoftDeleteFilterManager(['custom_soft_delete']);

        $entityManager->method('getFilters')->willReturn($filters);
        $filters->method('getEnabledFilters')->willReturn([
            'gedmo_alias' => self::createStub(DummySoftDeleteableFilter::class),
            'tenant' => new class {},
        ]);
        $filters->expects($this->once())->method('suspend')->with('gedmo_alias');

        self::assertSame(['gedmo_alias'], $manager->disable($entityManager));
    }

    public function testEnableRestoresSuspendedFilters(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $entityManager = self::createStub(EntityManagerInterface::class);
        $manager = new SoftDeleteFilterManager();

        $entityManager->method('getFilters')->willReturn($filters);
        $filters->method('isSuspended')->willReturnCallback(static fn (string $name): bool => in_array($name, ['softdeleteable', 'tenant'], true));
        $filters->expects($this->exactly(2))
            ->method('restore')
            ->willReturnCallback(static function (string $name): SQLFilter {
                self::assertContains($name, ['softdeleteable', 'tenant']);

                return self::createStub(SQLFilter::class);
            });

        $manager->enable($entityManager, ['softdeleteable', 'tenant']);
    }

    public function testEnableFallsBackToEnableForNonSuspendedFilters(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $entityManager = self::createStub(EntityManagerInterface::class);
        $manager = new SoftDeleteFilterManager();

        $entityManager->method('getFilters')->willReturn($filters);
        $filters->expects($this->once())->method('isSuspended')->with('softdeleteable')->willReturn(false);
        $filters->expects($this->once())
            ->method('enable')
            ->with('softdeleteable')
            ->willReturn(self::createStub(SQLFilter::class));
        $filters->expects($this->never())->method('restore');

        $manager->enable($entityManager, ['softdeleteable']);
    }
}
