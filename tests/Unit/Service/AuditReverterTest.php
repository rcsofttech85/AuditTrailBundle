<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditReverter;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;
use Rcsofttech\AuditTrailBundle\Service\SoftDeleteHandler;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DummySoftDeleteableFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return '';
    }
}

class TestUser
{
    public function getId(): int
    {
        return 1;
    }

    public function getDeletedAt(): ?\DateTimeInterface
    {
        return null;
    }

    public function setDeletedAt(?\DateTimeInterface $d): void
    {
    }
}

// Mock class for testing if not present
if (!class_exists('Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter')) {
    class_alias(DummySoftDeleteableFilter::class, 'Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter');
}

#[AllowMockObjectsWithoutExpectations()]
class AuditReverterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ValidatorInterface&MockObject $validator;
    private AuditService&MockObject $auditService;
    private AuditReverter $reverter;
    private FilterCollection&MockObject $filterCollection;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->filterCollection = $this->createMock(FilterCollection::class);

        $this->em->method('getFilters')->willReturn($this->filterCollection);

        $this->reverter = new AuditReverter(
            $this->em,
            $this->validator,
            $this->auditService,
            new RevertValueDenormalizer($this->em),
            new SoftDeleteHandler($this->em)
        );
    }

    public function testRevertUpdateSuccess(): void
    {
        $log = new AuditLog();
        $log->setEntityClass(TestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setOldValues(['name' => 'Old Name']);

        $entity = new class () {
            public string $name = 'New Name';

            public function getId(): int
            {
                return 1;
            }
        };

        // Mock getEnabledFilters to return empty array
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);

        $this->em->expects($this->once())->method('find')->with(TestUser::class, '1')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('isIdentifier')->willReturn(false);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getFieldValue')->willReturn('New Name');
        $metadata->expects($this->once())->method('setFieldValue')->with($entity, 'name', 'Old Name');

        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->validator->expects($this->once())->method('validate')->willReturn(new ConstraintViolationList());

        $this->em->expects($this->exactly(2))->method('persist')
            ->with(self::callback(fn ($arg) => $arg === $entity || $arg instanceof AuditLog));

        $this->em->expects($this->exactly(2))->method('flush'); // Once for entity, once for log

        // Mock transaction wrapper
        $this->em->method('wrapInTransaction')->willReturnCallback(function ($callback) {
            return $callback();
        });

        // Mock AuditService to create revert log
        $revertLog = new AuditLog();
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);

        $changes = $this->reverter->revert($log);

        self::assertEquals(['name' => 'Old Name'], $changes);
    }

    public function testRevertCreateRequiresForce(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_CREATE);
        $log->setEntityClass(TestUser::class);
        $log->setEntityId('1');

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires --force');

        $this->reverter->revert($log);
    }

    public function testRevertCreateWithForce(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_CREATE);
        $log->setEntityClass(TestUser::class);
        $log->setEntityId('1');

        $entity = new \stdClass();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(function ($callback) {
            return $callback();
        });

        $this->em->expects($this->once())->method('remove')->with($entity);
        $this->em->expects($this->once())->method('persist')->with(self::isInstanceOf(AuditLog::class));

        $revertLog = new AuditLog();
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);

        $changes = $this->reverter->revert($log, false, true);

        self::assertEquals(['action' => 'delete'], $changes);
    }

    public function testRevertSoftDeleteRestores(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_SOFT_DELETE);
        $log->setEntityClass(TestUser::class);
        $log->setEntityId('1');

        $entity = new class () {
            private ?\DateTimeInterface $deletedAt = null;

            public function __construct()
            {
                $this->deletedAt = new \DateTime();
            }

            public function getDeletedAt(): ?\DateTimeInterface
            {
                return $this->deletedAt;
            }

            public function setDeletedAt(?\DateTimeInterface $d): void
            {
                $this->deletedAt = $d;
            }
        };

        // Mock Filter
        $filter = $this->createMock(DummySoftDeleteableFilter::class);
        $this->filterCollection->method('getEnabledFilters')->willReturn(['soft_delete' => $filter]);

        $this->filterCollection->expects($this->once())->method('disable')->with('soft_delete');
        $this->filterCollection->expects($this->once())->method('enable')->with('soft_delete');

        $this->em->method('find')->willReturn($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(function ($callback) {
            return $callback();
        });

        $revertLog = new AuditLog();
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);

        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $this->em->expects($this->exactly(2))->method('persist')
            ->with(self::callback(fn ($arg) => $arg === $entity || $arg instanceof AuditLog));

        $changes = $this->reverter->revert($log);

        self::assertEquals(['action' => 'restore'], $changes);
        self::assertNull($entity->getDeletedAt());
    }
}
