<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use PHPUnit\Framework\MockObject\MockObject;

class HttpAuditTransportTest extends TestCase
{
    private HttpAuditTransport $transport;
    private HttpClientInterface&MockObject $client;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->transport = new HttpAuditTransport($this->client, 'http://example.com', $this->logger);
    }

    public function testSendPostFlushSendsRequest(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        // ID is private(set) and no setter, but we need to set it for test?
        // Wait, setEntityId IS available.
        $log->setEntityId('1');
        $log->setAction('create');

        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', $this->callback(function ($options) {
                return isset($options['json']) && '1' === $options['json']['entity_id'];
            }));

        $this->transport->send($log, ['phase' => 'post_flush']);
    }

    public function testSendResolvesPendingId(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('pending');
        $log->setAction('create');

        $entity = new \stdClass();
        $em = $this->createStub(EntityManagerInterface::class);
        $meta = $this->createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);
        $meta->method('getIdentifierValues')->willReturn(['id' => 100]);

        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', $this->callback(function ($options) {
                return isset($options['json']) && '100' === $options['json']['entity_id'];
            }));

        $this->transport->send($log, [
            'phase' => 'post_flush',
            'em' => $em,
            'entity' => $entity,
        ]);
    }
}
