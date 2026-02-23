<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Security;

use DateTimeImmutable;
use Error;
use LogicException;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditRendererInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use Rcsofttech\AuditTrailBundle\Service\DataMasker;
use Rcsofttech\AuditTrailBundle\Service\ExpressionLanguageVoter;
use Rcsofttech\AuditTrailBundle\Tests\Functional\AbstractFunctionalTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Comprehensive Security Audit Suite for AuditTrailBundle.
 * Covers internal mechanics (property hooks, reflection, serialization)
 * and external attack vectors (SQLi, CSRF, DoS, XSS).
 */
final class SecurityAuditTest extends AbstractFunctionalTestCase
{
    private AuditIntegrityService $standaloneIntegrityService;

    private string $testSecret = 'super-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->standaloneIntegrityService = new AuditIntegrityService($this->testSecret, true);
    }

    // --- INTERNAL MECHANICS & PROPERTY HOOKS ---

    /**
     * The Reflection Penetration
     * Can we use Reflection to bypass the 'set' hook and modify a sealed log?
     */
    public function testReflectionHookBypassAttempt(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);
        $log->entityId = 'ORIGINAL';
        $log->seal();

        // Standard assignment fails
        try {
            $log->entityId = 'TAMPERED';
            self::fail('Standard assignment should have failed on sealed log');
        } catch (LogicException $e) {
            self::assertEquals('Cannot modify a sealed audit log.', $e->getMessage());
        }

        // Reflection attempt to set backing value
        // Note: In PHP 8.4, ReflectionProperty::setValue() triggers hooks.
        $rp = new ReflectionProperty(AuditLog::class, 'entityId');

        try {
            $rp->setValue($log, 'REFLECTED');
            self::assertEquals('REFLECTED', $log->entityId);
        } catch (LogicException $e) {
            self::assertEquals('Cannot modify a sealed audit log.', $e->getMessage());
        }
    }

    /**
     * Serialization Tampering
     * Can we modify the state via serialized string manipulation?
     */
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
        self::assertEquals('EVIL', $unserialized->entityId);

        // Ultimate defense: Signature verification MUST fail
        self::assertFalse($this->standaloneIntegrityService->verifySignature($unserialized), 'Persistence tampering must be detected by signature');
    }

    /**
     * The Asymmetric Visibility Breach
     * Can we modify a public private(set) property from outside?
     */
    public function testAsymmetricVisibilityBreach(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);

        $rp = new ReflectionProperty(AuditLog::class, 'action');
        self::assertTrue($rp->isPublic(), 'Should be public for reading');

        try {
            /** @phpstan-ignore-next-line */
            $log->action = AuditLogInterface::ACTION_DELETE;
            self::fail('Should not be able to set private(set) property from outside');
        } catch (Error $e) {
            self::assertStringContainsString('Cannot modify', $e->getMessage());
        }
    }

    /**
     * Indirect Array Modification
     * Does $log->context['key'] = 'val' bypass the 'set' hook?
     */
    public function testIndirectArrayModification(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);
        $log->context = ['initial' => true];
        $log->seal();

        try {
            $log->context['tamper'] = true;
            self::fail('Indirect modification should be blocked');
        } catch (Error $e) {
            self::assertStringContainsString('not allowed', $e->getMessage());
        }

        self::assertArrayNotHasKey('tamper', $log->context);
    }

    // --- EXTERNAL ATTACK VECTORS & SERVICE SECURITY ---

    /**
     * The "Sneaky Property" Bypass.
     */
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

        self::assertEquals('********', $redacted['password']);
        self::assertEquals('********', $redacted['PASSWORD']);
        self::assertEquals('********', $redacted['user_token']);
        self::assertEquals('********', $redacted['api_key']);
        self::assertEquals('********', $redacted['nested']['cookie']);
    }

    /**
     * Replay / Timestamp Manipulation.
     */
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

    /**
     * SQL Injection in Filters.
     */
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

    /**
     * Expression Language Injection.
     */
    public function testExpressionLanguageVoterAttack(): void
    {
        self::bootKernel();
        /** @var ExpressionLanguageVoter $voter */
        $voter = self::getContainer()->get(ExpressionLanguageVoter::class);

        $reflection = new ReflectionMethod($voter, 'isExpressionSafe');

        self::assertFalse($reflection->invoke($voter, "system('whoami')"), 'system() must be blocked');
        self::assertFalse($reflection->invoke($voter, "exec('ls')"), 'exec() must be blocked');
        self::assertFalse($reflection->invoke($voter, "constant('PHP_VERSION')"), 'constant() must be blocked');
    }

    /**
     * Mass Ingestion (DoS).
     */
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

    /**
     * Circular Reference (DoS).
     */
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
