<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class HttpAuditTransportTest extends TestCase
{
    public function testSendPostFlushSendsRequest(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $this->createStub(LoggerInterface::class));

        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('1');
        $log->setAction('create');

        $client->expects($this->once())
            ->method('request')
            ->withAnyParameters()
            ->willReturnCallback(function () {
                return $this->createStub(ResponseInterface::class);
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testSendResolvesPendingId(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $this->createStub(LoggerInterface::class));

        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('pending');
        $log->setAction('create');

        $entity = new \stdClass();
        $em = $this->createStub(EntityManagerInterface::class);
        $meta = $this->createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);
        $meta->method('getIdentifierValues')->willReturn(['id' => 100]);

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', $this->callback(function ($options) {
                return isset($options['json']) && '100' === $options['json']['entity_id'];
            }))
            ->willReturn($this->createStub(ResponseInterface::class));

        $transport->send($log, [
            'phase' => 'post_flush',
            'em' => $em,
            'entity' => $entity,
        ]);
    }
}
