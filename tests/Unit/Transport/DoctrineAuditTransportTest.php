<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\DoctrineAuditTransport;

class DoctrineAuditTransportTest extends TestCase
{
    private DoctrineAuditTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new DoctrineAuditTransport();
    }

    public function testSendOnFlushPersistsLog(): void
    {
        $log = new AuditLog();
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $meta = $this->createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);

        $em->expects($this->once())->method('persist')->with($log);
        $uow->expects($this->once())->method('computeChangeSet')->with($meta, $log);

        $this->transport->send($log, [
            'phase' => 'on_flush',
            'em' => $em,
            'uow' => $uow,
        ]);
    }

    public function testSendPostFlushUpdatesId(): void
    {
        // Use real instance instead of stub because AuditLog is final
        $log = new AuditLog();
        $log->setEntityId('pending');
        // Since properties are public private(set), we can't mock them easily with createStub unless we use __get or reflection,
        // but AuditLog is final now.
        // We should use a real instance and set properties via setters (which we kept).
        $log = new AuditLog();
        $log->setEntityId('pending');
        // We need to set ID, but ID is private(set) and has no setter.
        // We must use reflection to set ID for testing.
        $reflection = new \ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, 1);

        $entity = new \stdClass();
        $em = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $meta = $this->createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('getConnection')->willReturn($connection);
        $meta->method('getIdentifierValues')->willReturn(['id' => 100]);
        $meta->method('getTableName')->willReturn('audit_log');

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE audit_log SET entity_id = ? WHERE id = ?',
                ['100', 1]
            );

        $this->transport->send($log, [
            'phase' => 'post_flush',
            'em' => $em,
            'entity' => $entity,
        ]);
    }
}
