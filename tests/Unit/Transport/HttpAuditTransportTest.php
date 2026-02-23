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
                if ($options['headers']['X-Test'] !== 'value' || $options['timeout'] !== 10) {
                    return false;
                }

                $body = json_decode($options['body'], true);
                $expectedKeys = [
                    'entity_class',
                    'entity_id',
                    'action',
                    'old_values',
                    'new_values',
                    'changed_fields',
                    'user_id',
                    'username',
                    'ip_address',
                    'user_agent',
                    'transaction_hash',
                    'signature',
                    'context',
                    'created_at',
                ];

                foreach ($expectedKeys as $key) {
                    if (!array_key_exists($key, $body)) {
                        return false;
                    }
                }

                return $body['context'] === ['phase' => 'post_flush'];
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

        // Mock integrity service so we test the IF block for signing payload
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->method('signPayload')->willReturn('test-signature');

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);

        $transport->send($log, [
            'phase' => 'post_flush',
            'em' => $em,
            'entity' => $entity,
        ]);
    }

    public function testDefaultTimeoutIsFive(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        // Use default timeout (no explicit timeout in constructor)
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $log = new AuditLog('TestEntity', '1', 'create');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function ($options) {
                return $options['timeout'] === 5;
            }))
            ->willReturnCallback(function () {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testIntegrityServiceEnabledAddsSignatureHeader(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->method('signPayload')->willReturn('sig-abc');

        $log = new AuditLog('TestEntity', '1', 'create');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function ($options) {
                return isset($options['headers']['X-Signature'])
                    && $options['headers']['X-Signature'] === 'sig-abc';
            }))
            ->willReturnCallback(function () {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testIntegrityServiceDisabledNoSignatureHeader(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $integrityService->method('isEnabled')->willReturn(false);

        $log = new AuditLog('TestEntity', '1', 'create');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function ($options) {
                return !isset($options['headers']['X-Signature']);
            }))
            ->willReturnCallback(function () {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testContextMergesLogContextWithParam(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $log = new AuditLog('TestEntity', '1', 'create');
        $log->context = ['source' => 'cli'];

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function ($options) {
                $body = json_decode($options['body'], true);

                // context should be merged: log.context + param context
                return $body['context'] === ['source' => 'cli', 'phase' => 'post_flush'];
            }))
            ->willReturnCallback(function () {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testErrorResponseLogsError(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->willReturn('Internal Server Error');

        $client->method('request')->willReturn($response);

        $logger->expects($this->once())
            ->method('error')
            ->with(self::stringContains('HTTP audit transport failed'));

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testBoundaryStatusCode199LogsError(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(199);
        $response->method('getContent')->willReturn('');

        $client->method('request')->willReturn($response);

        $logger->expects($this->once())->method('error');

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testBoundaryStatusCode200NoError(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('');

        $client->method('request')->willReturn($response);

        $logger->expects($this->never())->method('error');

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testBoundaryStatusCode299NoError(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(299);
        $response->method('getContent')->willReturn('');

        $client->method('request')->willReturn($response);

        $logger->expects($this->never())->method('error');

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testBoundaryStatusCode300LogsError(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(300);
        $response->method('getContent')->willReturn('');

        $client->method('request')->willReturn($response);

        $logger->expects($this->once())->method('error');

        $transport->send($log, ['phase' => 'post_flush']);
    }
}
