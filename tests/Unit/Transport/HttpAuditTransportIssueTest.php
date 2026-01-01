<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AllowMockObjectsWithoutExpectations]
class HttpAuditTransportIssueTest extends TestCase
{
    public function testSupportsMethod(): void
    {
        $client = self::createStub(HttpClientInterface::class);
        $logger = self::createStub(LoggerInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $logger);

        self::assertFalse($transport->supports('on_flush'), 'Should not support on_flush');
        self::assertTrue($transport->supports('post_flush'), 'Should support post_flush');
    }
}
