<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditSubscriber;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Symfony\Component\Clock\MockClock;
use PHPUnit\Framework\Attributes\CoversClass;

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

#[CoversClass(AuditSubscriber::class)]
class SensitiveDataUpdateTest extends TestCase
{
    public function testUpdateMasksSensitiveData(): void
    {
        // Setup Service
        $em = $this->createMock(EntityManagerInterface::class);
        $userResolver = $this->createMock(\Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface::class);
        $clock = new MockClock();

        // We need a real AuditService to test the attribute reading logic,
        // but we can mock the dependencies.
        $auditService = new AuditService($em, $userResolver, $clock);

        // Setup Subscriber
        $transport = $this->createMock(AuditTransportInterface::class);
        $transport->method('supports')->willReturn(true);

        $subscriber = new AuditSubscriber(
            $auditService,
            $transport,
            deferTransportUntilCommit: false // Send immediately to verify easily
        );

        // Setup Entity & ChangeSet
        $entity = new SensitiveUser();
        $entity->password = 'new_secret';

        // Setup Doctrine Event
        $uow = $this->createMock(UnitOfWork::class);
        $args = new OnFlushEventArgs($em);

        $em->method('getUnitOfWork')->willReturn($uow);

        // Mock ClassMetadata to avoid warnings and ensure getEntityId works
        $metadata = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $metadata->method('getName')->willReturn(SensitiveUser::class);
        $metadata->method('getFieldNames')->willReturn(['id', 'password', 'username']);
        $metadata->method('getAssociationNames')->willReturn([]);

        $em->method('getClassMetadata')->willReturn($metadata);

        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        // The ChangeSet shows the password changing from 'old_secret' to 'new_secret'
        $uow->method('getEntityChangeSet')->willReturn([
            'password' => ['old_secret', 'new_secret'],
            'username' => ['user', 'user'], // Unchanged
        ]);

        // Expectation: The transport should receive an audit log where values are MASKED
        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (AuditLog $log) {
                $old = $log->getOldValues();
                $new = $log->getNewValues();

                if (!\is_array($old) || !\is_array($new)) {
                    return false;
                }

                if (!isset($old['password']) || !isset($new['password'])) {
                    return false;
                }

                // Check if password is masked
                $oldMasked = '**REDACTED**' === $old['password'];
                $newMasked = '**REDACTED**' === $new['password'];

                if (!$oldMasked || !$newMasked) {
                    fwrite(STDERR, "\nLeak detected! Old: ".var_export($old['password'], true).', New: '.var_export($new['password'], true)."\n");
                }

                return $oldMasked && $newMasked;
            }));

        $subscriber->onFlush($args);
    }
}
