<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Security;

use DateTimeImmutable;
use Error;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use Rcsofttech\AuditTrailBundle\Service\DataMasker;
use Rcsofttech\AuditTrailBundle\Service\ExpressionLanguageVoter;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use ReflectionProperty;

final class SecurityAuditTest extends TestCase
{
    private const TEST_HMAC_SECRET = 'super-secret-key';

    private AuditIntegrityService $integrityService;

    protected function setUp(): void
    {
        $this->integrityService = new AuditIntegrityService(self::TEST_HMAC_SECRET, true);
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

        $property = new ReflectionProperty(AuditLog::class, 'entityId');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify a sealed audit log.');

        $property->setValue($log, 'REFLECTED');
    }

    public function testSerializationTamperAttempt(): void
    {
        $log = new AuditLog('App\Entity\User', '1', AuditLogInterface::ACTION_CREATE);
        $log->entityId = 'SAFE';
        $log->signature = $this->integrityService->generateSignature($log);
        $log->seal();

        $serialized = serialize($log);
        $tampered = str_replace('SAFE', 'EVIL', $serialized);

        /** @var AuditLog $unserialized AuditLog does not defend the serialization layer itself. */
        $unserialized = unserialize($tampered);
        self::assertSame('EVIL', $unserialized->entityId);
        self::assertFalse($this->integrityService->verifySignature($unserialized));
    }

    public function testAsymmetricVisibilityBreach(): void
    {
        $property = new ReflectionProperty(AuditLog::class, 'action');

        self::assertTrue($property->isPublic());
        self::assertTrue($property->isPrivateSet());
        self::assertFalse($property->isReadOnly());
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
            'access_token' => 'abc-def',
            'api_key' => '12345',
            'nested' => ['cookie' => 'session_id'],
        ];

        $redacted = $masker->redact($payload);

        self::assertSame('********', $redacted['password']);
        self::assertSame('********', $redacted['PASSWORD']);
        self::assertSame('********', $redacted['access_token'] ?? null);
        self::assertSame('********', $redacted['api_key'] ?? null);
        self::assertSame('********', $redacted['nested']['cookie'] ?? null);
    }

    public function testReplayAttackPrevention(): void
    {
        $property = new ReflectionProperty(AuditLog::class, 'createdAt');

        $log1 = new AuditLog('App\Entity\User', '1', 'create');
        $property->setValue($log1, new DateTimeImmutable('2020-01-01 10:00:00'));
        $log1->signature = $this->integrityService->generateSignature($log1);

        $log2 = new AuditLog('App\Entity\User', '1', 'create');
        $property->setValue($log2, new DateTimeImmutable('2024-01-01 10:00:00'));
        $log2->signature = $log1->signature;

        self::assertFalse($this->integrityService->verifySignature($log2));
    }

    public function testExpressionLanguageVoterAttack(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $userResolver = self::createStub(UserResolverInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition("system('whoami')"));
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Blocked potentially dangerous AuditCondition expression.',
                self::callback(static fn (array $context): bool => ($context['expression'] ?? null) === "system('whoami')")
            );

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver, $logger);

        self::assertFalse($voter->vote(new TestEntity('attack'), AuditLogInterface::ACTION_UPDATE, []));
    }
}
