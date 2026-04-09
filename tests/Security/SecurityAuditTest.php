<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Security;

use Rcsofttech\AuditTrailBundle\Contract\AuditRendererInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\AbstractFunctionalTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use ReflectionProperty;

final class SecurityAuditTest extends AbstractFunctionalTestCase
{
    public function testRepositoryFilterInjection(): void
    {
        self::bootKernel();
        $repository = $this->getEntityManager()->getRepository(AuditLog::class);

        $filters = ['entityId' => "1' OR '1'='1", 'action' => "create'--"];

        $log = new AuditLog('Test', '1', 'create');
        $this->getEntityManager()->persist($log);
        $this->getEntityManager()->flush();

        $results = $repository->findWithFilters($filters);
        self::assertCount(0, $results, 'SQL injection in filters should not return results');
    }

    public function testMassIngestionDoS(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('DoS Test');
        $em->persist($entity);
        $em->flush();

        for ($i = 0; $i < 100; ++$i) {
            $entity->setName("DoS Test $i");
        }

        gc_collect_cycles();
        $peakBeforeFlush = memory_get_peak_usage(true);
        $em->flush();
        $peakAfterFlush = memory_get_peak_usage(true);

        $deltaKb = ($peakAfterFlush - $peakBeforeFlush) / 1024;
        self::assertLessThan(2048, $deltaKb, 'Audit flush should not grow peak memory by more than 2 MB.');
    }

    public function testCircularReferenceDoS(): void
    {
        self::bootKernel();
        /** @var ValueSerializerInterface $serializer */
        $serializer = self::getContainer()->get(ValueSerializerInterface::class);

        $a = ['name' => 'root'];
        $b = ['name' => 'child'];
        $a['child'] = &$b;
        $b['parent'] = &$a;

        $result = $serializer->serialize($a);
        self::assertStringContainsString('max depth reached', (string) json_encode($result));
    }

    public function testLogInjectionSanitization(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $xss = "<script>alert('xss')</script>";
        $terminal = "\e[31mRED TEXT\e[0m";

        $log = new AuditLog('Test', '1', 'create');

        $rpUser = new ReflectionProperty(AuditLog::class, 'username');
        $rpUser->setValue($log, $xss);

        $rpUA = new ReflectionProperty(AuditLog::class, 'userAgent');
        $rpUA->setValue($log, $terminal);

        $em->persist($log);
        $em->flush();

        /** @var AuditLog $stored */
        $stored = $em->getRepository(AuditLog::class)->find($log->id);

        /** @var AuditRendererInterface $renderer */
        $renderer = self::getContainer()->get(AuditRendererInterface::class);
        $sanitized = $renderer->formatValue($terminal);
        self::assertStringNotContainsString("\e[", $sanitized, 'Terminal escapes should be stripped');

        $details = $renderer->formatChangedDetails($stored);
        self::assertNotSame('', $details);
    }
}
