<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AllowMockObjectsWithoutExpectations]
class HttpAuditTransportTest extends TestCase
{
    public function testSendPostFlushSendsRequest(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $transport = new HttpAuditTransport(
            $client,
            'http://example.com',
            $integrityService,
            ['X-Test' => 'value'],
            10
        );

        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('1');
        $log->setAction('create');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(function ($options) {
                return 'value' === $options['headers']['X-Test']
                    && 10 === $options['timeout']
                    && isset($options['body']);
            }))
            ->willReturnCallback(function () {
                return self::createStub(ResponseInterface::class);
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testSendResolvesPendingId(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService);

        $log = new AuditLog();
        $log->setEntityClass('TestEntity');
        $log->setEntityId('pending');
        $log->setAction('create');

        $entity = new \stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);
        $meta->method('getIdentifierValues')->willReturn(['id' => 100]);

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(function ($options) {
                $body = json_decode($options['body'], true);

                return isset($body['entity_id'])
                    && '100' === $body['entity_id']
                    && array_key_exists('changed_fields', $body);
            }))
            ->willReturn(self::createStub(ResponseInterface::class));

        $transport->send($log, [
            'phase' => 'post_flush',
            'em' => $em,
            'entity' => $entity,
        ]);
    }
}
