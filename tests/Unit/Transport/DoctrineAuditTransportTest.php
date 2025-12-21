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
        $log = $this->createStub(AuditLog::class);
        $log->method('getId')->willReturn(1);
        $log->method('getEntityId')->willReturn('pending');

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
