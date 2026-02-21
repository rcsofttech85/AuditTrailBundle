<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AllowMockObjectsWithoutExpectations]
class HttpAuditTransportIssueTest extends TestCase
{
    public function testSupportsMethod(): void
    {
        $client = self::createStub(HttpClientInterface::class);
        $logger = self::createStub(LoggerInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        self::assertFalse($transport->supports('on_flush'), 'Should not support on_flush');
        self::assertTrue($transport->supports('post_flush'), 'Should support post_flush');
    }
}
