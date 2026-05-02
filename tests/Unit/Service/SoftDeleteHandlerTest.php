<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\SoftDeleteHandler;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\DummySoftDeleteableFilter;

#[AllowMockObjectsWithoutExpectations]
final class SoftDeleteHandlerTest extends TestCase
{
    public function testDisableSoftDeleteFiltersDisablesConfiguredFilterNames(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $handler = new SoftDeleteHandler($em, ['custom_soft_delete']);

        $em->method('getFilters')->willReturn($filters);
        $filters->method('getEnabledFilters')->willReturn([
            'custom_soft_delete' => new class {},
            'tenant' => new class {},
        ]);
        $filters->expects($this->once())->method('disable')->with('custom_soft_delete');

        self::assertSame(['custom_soft_delete'], $handler->disableSoftDeleteFilters());
    }

    public function testDisableSoftDeleteFiltersRecognizesGedmoFilterClassExactly(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $handler = new SoftDeleteHandler($em, ['custom_soft_delete']);

        $em->method('getFilters')->willReturn($filters);
        $filters->method('getEnabledFilters')->willReturn([
            'gedmo_alias' => self::createStub(DummySoftDeleteableFilter::class),
            'tenant' => new class {},
        ]);
        $filters->expects($this->once())->method('disable')->with('gedmo_alias');

        self::assertSame(['gedmo_alias'], $handler->disableSoftDeleteFilters());
    }

    public function testEnableFiltersReenablesEachProvidedName(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $handler = new SoftDeleteHandler($em);

        $em->method('getFilters')->willReturn($filters);
        $filters->expects($this->exactly(2))
            ->method('enable')
            ->willReturnCallback(static function (string $name): SQLFilter {
                self::assertContains($name, ['softdeleteable', 'tenant']);

                return self::createStub(SQLFilter::class);
            });

        $handler->enableFilters(['softdeleteable', 'tenant']);
    }
}
