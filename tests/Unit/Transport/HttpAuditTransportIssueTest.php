<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use Rcsofttech\AuditTrailBundle\Transport\HttpAuditTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpAuditTransportIssueTest extends TestCase
{
    public function testSupportsMethod(): void
    {
        $client = self::createStub(HttpClientInterface::class);
        $logger = self::createStub(LoggerInterface::class);
        $integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $transport = new HttpAuditTransport($client, 'http://example.com', $integrityService, $idResolver);

        self::assertFalse($transport->supports($this->createContext(AuditPhase::OnFlush)), 'Should not support on_flush');
        self::assertTrue($transport->supports($this->createContext(AuditPhase::PostFlush)), 'Should support post_flush');
    }

    private function createContext(AuditPhase $phase): AuditTransportContext
    {
        return new AuditTransportContext(
            $phase,
            self::createStub(EntityManagerInterface::class),
            new AuditLog('TestEntity', '1', 'create'),
        );
    }
}
