<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Security;

use DateTimeImmutable;
use Error;
use LogicException;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditRendererInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use Rcsofttech\AuditTrailBundle\Service\DataMasker;
use Rcsofttech\AuditTrailBundle\Service\ExpressionLanguageVoter;
use Rcsofttech\AuditTrailBundle\Service\MetadataCache;
use Rcsofttech\AuditTrailBundle\Tests\Functional\AbstractFunctionalTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use ReflectionProperty;

final class SecurityAuditTest extends AbstractFunctionalTestCase
{
    private AuditIntegrityService $standaloneIntegrityService;

    private string $testSecret = 'super-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->standaloneIntegrityService = new AuditIntegrityService($this->testSecret, true);
    }

    public function testSealedLogRejectsDirectMutation(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);
        $log->entityId = 'ORIGINAL';
        $log->seal();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify a sealed audit log.');

        $log->entityId = 'TAMPERED';
    }

    public function testReflectionCannotBypassSealProtection(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);
        $log->entityId = 'ORIGINAL';
        $log->seal();

        $rp = new ReflectionProperty(AuditLog::class, 'entityId');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify a sealed audit log.');

        $rp->setValue($log, 'REFLECTED');
    }

    public function testSerializationTamperAttempt(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);
        $log->entityId = 'SAFE';
        $log->signature = $this->standaloneIntegrityService->generateSignature($log);
        $log->seal();

        $serialized = serialize($log);
        $tampered = str_replace('SAFE', 'EVIL', $serialized);

        /** @var AuditLog $unserialized */
        $unserialized = unserialize($tampered);
        self::assertSame('EVIL', $unserialized->entityId);

        self::assertFalse($this->standaloneIntegrityService->verifySignature($unserialized), 'Persistence tampering must be detected by signature');
    }

    public function testAsymmetricVisibilityBreach(): void
    {
        $rp = new ReflectionProperty(AuditLog::class, 'action');
        self::assertTrue($rp->isPublic(), 'Should be public for reading');
        self::assertTrue($rp->isPrivateSet(), 'Property should be write-protected outside the class.');
        self::assertFalse($rp->isReadOnly(), 'This test covers asymmetric visibility, not readonly semantics.');
    }

    public function testIndirectArrayModification(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);
        $log->context = ['initial' => true];
        $log->seal();

        $this->expectException(Error::class);
        $this->expectExceptionMessage('not allowed');

        $log->context['tamper'] = true;
    }

    public function testDataMaskingBypass(): void
    {
        $masker = new DataMasker();

        $payload = [
            'password' => 'secret123',
            'PASSWORD' => 'secret456',
            'user_token' => 'abc-def',
            'api_key' => '12345',
            'nested' => ['cookie' => 'session_id'],
        ];

        $redacted = $masker->redact($payload);

        self::assertSame('********', $redacted['password']);
        self::assertSame('********', $redacted['PASSWORD']);
        self::assertSame('********', $redacted['user_token'] ?? null);
        self::assertSame('********', $redacted['api_key'] ?? null);
        self::assertIsArray($redacted['nested'] ?? null);
        self::assertSame('********', $redacted['nested']['cookie'] ?? null);
    }

    public function testReplayAttackPrevention(): void
    {
        $log1 = new AuditLog('App\Entity\User', '1', 'create');
        $rp = new ReflectionProperty(AuditLog::class, 'createdAt');
        $rp->setValue($log1, new DateTimeImmutable('2020-01-01 10:00:00'));

        $sig1 = $this->standaloneIntegrityService->generateSignature($log1);
        $log1->signature = $sig1;

        $log2 = new AuditLog('App\Entity\User', '1', 'create');
        $rp->setValue($log2, new DateTimeImmutable('2024-01-01 10:00:00'));
        $log2->signature = $sig1;

        self::assertFalse($this->standaloneIntegrityService->verifySignature($log2), 'Signature must be tied to the timestamp');
    }

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

    public function testExpressionLanguageVoterAttack(): void
    {
        $metadataCache = self::createStub(MetadataCache::class);
        $userResolver = self::createStub(UserResolverInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $metadataCache->method('getAuditCondition')->willReturnCallback(
            static fn (): AuditCondition => new AuditCondition("system('whoami')")
        );

        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Blocked potentially dangerous AuditCondition expression.',
                self::callback(static fn (array $context): bool => ($context['expression'] ?? null) === "system('whoami')")
            );

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver, $logger);

        self::assertFalse($voter->vote(new TestEntity('attack'), AuditLogInterface::ACTION_UPDATE, []));
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

        $startMemory = memory_get_usage();
        $em->flush();
        $endMemory = memory_get_usage();

        $diffKb = ($endMemory - $startMemory) / 1024;
        self::assertLessThan(50000, $diffKb, "Audit processing shouldn't leak excessive memory");
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

    /**
     * Log Injection (XSS / Terminal Injection).
     */
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
