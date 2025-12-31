<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Service\MetadataCache;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Auditable]
class SensitiveUser
{
    public function getId(): int
    {
        return 1;
    }

    #[Sensitive]
    public string $password = 'secret';

    public string $username = 'user';
}

class SensitiveDataUpdateTest extends TestCase
{
    public function testUpdateMasksSensitiveData(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $userResolver = self::createStub(UserResolverInterface::class);
        $clock = new MockClock();
        $transactionIdGenerator = self::createStub(TransactionIdGenerator::class);
        $transactionIdGenerator->method('getTransactionId')->willReturn('test-transaction-id');

        $metadataCache = new MetadataCache();
        $serializer = new ValueSerializer(null); // No logger
        $extractor = new EntityDataExtractor($em, $serializer, $metadataCache);

        $auditService = new AuditService(
            $em,
            $userResolver,
            $clock,
            $transactionIdGenerator,
            $extractor,
            $metadataCache
        );

        $transport = $this->createMock(AuditTransportInterface::class);
        $transport->method('supports')->willReturn(true);
        $dispatcher = new AuditDispatcher($transport, null); // No logger
        $auditManager = new ScheduledAuditManager(self::createStub(
            EventDispatcherInterface::class
        ));
        $changeProcessor = new ChangeProcessor($auditService, true, 'deletedAt');

        $entityProcessor = new EntityProcessor(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
            false
        );

        $subscriber = new AuditSubscriber(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
            $entityProcessor
        );

        $entity = new SensitiveUser();
        $entity->password = 'new_secret';

        $uow = self::createStub(UnitOfWork::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $metadata->method('getName')->willReturn(SensitiveUser::class);
        $metadata->method('getFieldNames')->willReturn(['id', 'password', 'username']);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('getReflectionClass')->willReturn(new \ReflectionClass($entity));
        $metadata->method('getReflectionProperty')->willReturnCallback(fn ($p) => new \ReflectionProperty($entity, $p));

        $em->method('getClassMetadata')->willReturn($metadata);

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn([
            'password' => ['old_secret', 'new_secret'],
            'username' => ['user', 'user'],
        ]);

        $transport->expects($this->once())
            ->method('send')
            ->with(self::callback(function (AuditLog $log) {
                $old = $log->getOldValues();
                $new = $log->getNewValues();

                return '**REDACTED**' === ($old['password'] ?? '') && '**REDACTED**' === ($new['password'] ?? '');
            }));

        $subscriber->onFlush(new OnFlushEventArgs($em));
    }
}
