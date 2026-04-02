<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use RuntimeException;
use stdClass;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function array_key_exists;

final class HttpAuditTransportTest extends TestCase
{
    public function testSendPostFlushSendsRequest(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
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
            ->with('POST', 'http://example.com', self::callback(static function (array $options) {
                /** @var array{headers: array<string, string>, timeout: int, body: string} $options */
                if (($options['headers']['X-Test'] ?? null) !== 'value' || $options['timeout'] !== 10) {
                    return false;
                }

                /** @var array<string, mixed> $body */
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

                return $body['context'] === [];
            }))
            ->willReturnCallback(static function () {
                $response = self::createStub(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testSendResolvesPendingId(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $log = new AuditLog('TestEntity', 'pending', 'create');

        $idResolver->method('resolve')->willReturn('100');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function (array $options) {
                /** @var array{headers: array<string, string>, timeout: int, body: string} $options */
                /** @var array<string, mixed> $body */
                $body = json_decode($options['body'], true);

                return isset($body['entity_id'])
                    && $body['entity_id'] === '100'
                    && array_key_exists('changed_fields', $body);
            }))
            ->willReturnCallback(static function () {
                $response = self::createStub(ResponseInterface::class);
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
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        // Use default timeout (no explicit timeout in constructor)
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $log = new AuditLog('TestEntity', '1', 'create');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function (array $options) {
                /** @var array{headers: array<string, string>, timeout: int, body: string} $options */
                return $options['timeout'] === 5;
            }))
            ->willReturnCallback(static function () {
                $response = self::createStub(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testIntegrityServiceEnabledAddsSignatureHeader(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->method('signPayload')->willReturn('sig-abc');

        $log = new AuditLog('TestEntity', '1', 'create');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function (array $options) {
                /** @var array{headers: array<string, string>, timeout: int, body: string} $options */
                return isset($options['headers']['X-Signature'])
                    && $options['headers']['X-Signature'] === 'sig-abc';
            }))
            ->willReturnCallback(static function () {
                $response = self::createStub(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testIntegrityServiceDisabledNoSignatureHeader(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $integrityService->method('isEnabled')->willReturn(false);

        $log = new AuditLog('TestEntity', '1', 'create');

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function (array $options) {
                /** @var array{headers: array<string, string>, timeout: int, body: string} $options */
                return !isset($options['headers']['X-Signature']);
            }))
            ->willReturnCallback(static function () {
                $response = self::createStub(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testContextUsesPersistedAuditContextOnly(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $log = new AuditLog('TestEntity', '1', 'create');
        $log->context = ['source' => 'cli'];

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function (array $options) {
                /** @var array{headers: array<string, string>, timeout: int, body: string} $options */
                /** @var array<string, mixed> $body */
                $body = json_decode($options['body'], true);

                return $body['context'] === ['source' => 'cli'];
            }))
            ->willReturnCallback(static function () {
                $response = self::createStub(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testInternalRuntimeContextIsNotLeakedToHttpPayload(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        $log = new AuditLog('TestEntity', '1', 'create', context: ['source' => 'cli']);

        $client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://example.com', self::callback(static function (array $options) {
                /** @var array{headers: array<string, string>, timeout: int, body: string} $options */
                /** @var array<string, mixed> $body */
                $body = json_decode($options['body'], true);

                return $body['context'] === ['source' => 'cli'];
            }))
            ->willReturnCallback(static function () {
                $response = self::createStub(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(200);

                return $response;
            });

        $transport->send($log, [
            'phase' => 'post_flush',
            'em' => self::createStub(EntityManagerInterface::class),
            'entity' => new stdClass(),
            'audit' => $log,
            'uow' => new stdClass(),
        ]);
    }

    public function testErrorResponseLogsError(): void
    {
        $client = self::createStub(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->willReturn('Internal Server Error');

        $client->method('request')->willReturn($response);

        $logger->expects($this->once())
            ->method('error')
            ->with(self::stringContains('HTTP audit transport failed'));

        $this->expectException(RuntimeException::class);
        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testBoundaryStatusCode199LogsError(): void
    {
        $client = self::createStub(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(199);
        $response->method('getContent')->willReturn('');

        $client->method('request')->willReturn($response);

        $logger->expects($this->once())->method('error');

        $this->expectException(RuntimeException::class);
        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testBoundaryStatusCode200NoError(): void
    {
        $client = self::createStub(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('');

        $client->method('request')->willReturn($response);

        $logger->expects($this->never())->method('error');

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testBoundaryStatusCode299NoError(): void
    {
        $client = self::createStub(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(299);
        $response->method('getContent')->willReturn('');

        $client->method('request')->willReturn($response);

        $logger->expects($this->never())->method('error');

        $transport->send($log, ['phase' => 'post_flush']);
    }

    public function testBoundaryStatusCode300LogsError(): void
    {
        $client = self::createStub(HttpClientInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver, $logger);

        $log = new AuditLog('TestEntity', '1', 'create');

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(300);
        $response->method('getContent')->willReturn('');

        $client->method('request')->willReturn($response);

        $logger->expects($this->once())->method('error');

        $this->expectException(RuntimeException::class);
        $transport->send($log, ['phase' => 'post_flush']);
    }
}
