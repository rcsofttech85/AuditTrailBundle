<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\SensitiveUser;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
class SensitiveDataUpdateTest extends AbstractAuditTestCase
{
    /** @var EntityManagerInterface&Stub */
    private EntityManagerInterface $entityManager;

    /** @var AuditTransportInterface&MockObject */
    private AuditTransportInterface $transport;

    /** @var TransactionIdGenerator&Stub */
    private TransactionIdGenerator $transactionIdGenerator;

    protected function setUp(): void
    {
        $this->entityManager = self::createStub(EntityManagerInterface::class);
        $this->transport = $this->createMock(AuditTransportInterface::class);
        $this->transport->method('supports')->willReturn(true);

        $this->transactionIdGenerator = self::createStub(TransactionIdGenerator::class);
        $this->transactionIdGenerator->method('getTransactionId')->willReturn('test-transaction-id');
    }

    public function testUpdateMasksSensitiveData(): void
    {
        $subscriber = $this->createSubscriber();
        $entity = $this->createSensitiveUserEntity();
        $this->configureMockUnitOfWork($entity);

        $this->transport->expects($this->once())
            ->method('send')
            ->with(self::callback(static function (AuditLog $log): bool {
                $old = $log->oldValues;
                $new = $log->newValues;

                return ($old['password'] ?? '') === '**REDACTED**' && ($new['password'] ?? '') === '**REDACTED**';
            }));

        $subscriber->onFlush(new OnFlushEventArgs($this->entityManager));
    }

    private function createSubscriber(): AuditSubscriber
    {
        $auditService = $this->createAuditService($this->entityManager, $this->transactionIdGenerator);
        $dispatcher = $this->createAuditDispatcher($this->transport);
        $auditManager = new ScheduledAuditManager(self::createStub(EventDispatcherInterface::class));

        $metadataManager = self::createStub(AuditMetadataManagerInterface::class);
        $metadataManager->method('getSensitiveFields')->willReturn(['password' => '**REDACTED**']);

        $changeProcessor = new ChangeProcessor($metadataManager, new ValueSerializer(null), true, 'deletedAt');

        $entityProcessor = $this->createEntityProcessor(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager
        );

        return new AuditSubscriber(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
            $entityProcessor,
            $this->transactionIdGenerator,
            self::createStub(AuditAccessHandler::class),
            self::createStub(EntityIdResolverInterface::class)
        );
    }

    private function createSensitiveUserEntity(): SensitiveUser
    {
        $entity = new SensitiveUser();
        $entity->password = 'new_secret';

        return $entity;
    }

    private function configureMockUnitOfWork(SensitiveUser $entity): void
    {
        $uow = self::createStub(UnitOfWork::class);
        $this->entityManager->method('getUnitOfWork')->willReturn($uow);

        $metadata = $this->createEntityMetadataStub(SensitiveUser::class, $entity, ['id' => 1]);
        $metadata->method('getFieldNames')->willReturn(['id', 'password', 'username']);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn([
            'password' => ['old_secret', 'new_secret'],
            'username' => ['user', 'user'],
        ]);
    }
}
