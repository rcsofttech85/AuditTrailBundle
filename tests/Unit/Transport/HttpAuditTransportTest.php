<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use stdClass;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function array_key_exists;

#[AllowMockObjectsWithoutExpectations]
class HttpAuditTransportTest extends TestCase
{
    public function testSendPostFlushSendsRequest(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport(
            $client,
            'http://example.com',
            $integrityService,
            $idResolver,
            null,
            ['X-Test' => 'value'],
            10
        );

        $log = new AuditLog('TestEntity', '1', 'create');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function ($options) {
                return $options['headers']['X-Test'] === 'value'
                    && $options['timeout'] === 10
                    && isset($options['body']);
            }))
            ->willReturnCallback(function () {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testSendResolvesPendingId(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $log = new AuditLog('TestEntity', 'pending', 'create');

        $idResolver->method('resolve')->willReturn('100');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function ($options) {
                $body = json_decode($options['body'], true);

                return isset($body['entity_id'])
                    && $body['entity_id'] === '100'
                    && array_key_exists('changed_fields', $body);
            }))
            ->willReturnCallback(function () {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);

        $transport->send($log, [
            'phase' => 'post_flush',
            'em' => $em,
            'entity' => $entity,
        ]);
    }
}
