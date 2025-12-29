<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Attribute\Sensitive;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use PHPUnit\Framework\MockObject\Stub;

#[Auditable(enabled: true)]
class TestEntity
{
    public function getId(): int
    {
        return 1;
    }
}

/**
 * Test entity with #[Sensitive] attribute on property.
 */
#[Auditable(enabled: true)]
class SensitivePropertyEntity
{
    private string $name = 'John';

    #[Sensitive]
    private string $password = 'secret';

    #[Sensitive(mask: '****')]
    private string $ssn = '123-45-6789';

    public function getId(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getSsn(): string
    {
        return $this->ssn;
    }
}

/**
 * Test entity with #[SensitiveParameter] on constructor (promoted property).
 */
#[Auditable(enabled: true)]
class SensitiveConstructorEntity
{
    public function __construct(
        private string $name = 'John',
        #[\SensitiveParameter] private string $apiKey = 'secret-key',
    ) {
    }

    public function getId(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}

class AuditServiceTest extends TestCase
{
    // - FIXED: Changed to plain types - create stubs/mocks per test as needed
    private EntityManagerInterface&Stub $entityManager;
    private UserResolverInterface $userResolver;
    private ClockInterface $clock;
    private LoggerInterface $logger;
    private TransactionIdGenerator $transactionIdGenerator;
    private AuditService $service;

    protected function setUp(): void
    {
        // - Create stubs by default
        $this->entityManager = self::createStub(EntityManagerInterface::class);
        $this->userResolver = self::createStub(UserResolverInterface::class);
        $this->clock = self::createStub(ClockInterface::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->transactionIdGenerator = self::createStub(TransactionIdGenerator::class);
        $this->transactionIdGenerator->method('getTransactionId')->willReturn('test-transaction-id');

        $this->service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            ['ignoredField'], // global ignored
            [], // ignored entities
            $this->logger
        );
    }

    public function testShouldAuditReturnsTrueForAuditableEntity(): void
    {
        $entity = new TestEntity();
        self::assertTrue($this->service->shouldAudit($entity));
    }

    public function testShouldAuditReturnsFalseForIgnoredEntity(): void
    {
        $service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            self::createStub(ClockInterface::class),
            self::createStub(TransactionIdGenerator::class),
            [],
            [TestEntity::class], // Ignore TestEntity
            $this->logger
        );

        $entity = new TestEntity();
        self::assertFalse($service->shouldAudit($entity));
    }

    public function testGetEntityDataExtractsFields(): void
    {
        // - Create mock only for this test since we verify getClassMetadata
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new AuditService(
            $entityManager,
            $this->userResolver,
            $this->clock,
            $this->transactionIdGenerator,
            ['ignoredField'],
            [],
            $this->logger
        );

        $entity = new TestEntity();
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['name', 'ignoredField']);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('getFieldValue')->willReturnMap([
            [$entity, 'name', 'Test Name'],
            [$entity, 'ignoredField', 'Ignored'],
        ]);

        $entityManager->expects($this->once())->method('getClassMetadata')->willReturn($metadata);

        $data = $service->getEntityData($entity);

        self::assertArrayHasKey('name', $data);
        self::assertEquals('Test Name', $data['name']);
        self::assertArrayNotHasKey('ignoredField', $data);
    }

    public function testCreateAuditLog(): void
    {
        // - Create mocks only for this test since we verify expectations
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $userResolver = $this->createMock(UserResolverInterface::class);
        $clock = $this->createMock(ClockInterface::class);
        $transactionIdGenerator = $this->createMock(TransactionIdGenerator::class);

        $service = new AuditService(
            $entityManager,
            $userResolver,
            $clock,
            $transactionIdGenerator,
            ['ignoredField'],
            [],
            $this->logger
        );

        $entity = new TestEntity();
        $entityManager->expects($this->once())->method('getClassMetadata')->willReturn(self::createStub(ClassMetadata::class));

        $userResolver->expects($this->once())->method('getUserId')->willReturn(123);
        $userResolver->expects($this->once())->method('getUsername')->willReturn('testuser');

        $now = new \DateTimeImmutable('2024-01-01 12:00:00');
        $clock->expects($this->once())->method('now')->willReturn($now);

        $transactionIdGenerator->expects($this->once())->method('getTransactionId')->willReturn('test-transaction-id');

        $log = $service->createAuditLog(
            $entity,
            AuditLog::ACTION_UPDATE,
            ['name' => 'Old'],
            ['name' => 'New']
        );

        self::assertEquals(TestEntity::class, $log->getEntityClass());
        self::assertEquals(AuditLog::ACTION_UPDATE, $log->getAction());
        self::assertEquals(['name' => 'Old'], $log->getOldValues());
        self::assertEquals(['name' => 'New'], $log->getNewValues());
        self::assertEquals(['name'], $log->getChangedFields());
        self::assertEquals(123, $log->getUserId());
        self::assertEquals('testuser', $log->getUsername());
        self::assertEquals($now, $log->getCreatedAt());
        self::assertEquals('test-transaction-id', $log->getTransactionHash());
    }

    public function testCompositeKeySerialization(): void
    {
        $entity = new TestEntity();
        $metadata = self::createStub(ClassMetadata::class);

        // Mock composite key
        $metadata->method('getIdentifierValues')->willReturn(['id1' => 'uuid-1', 'id2' => 'uuid-2']);

        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $id = $this->service->getEntityId($entity);

        // Should be JSON encoded array
        self::assertJson($id);
        self::assertEquals('["uuid-1","uuid-2"]', $id);
    }

    public function testSingleKeySerialization(): void
    {
        $entity = new TestEntity();
        $metadata = self::createStub(ClassMetadata::class);

        // Mock single key
        $metadata->method('getIdentifierValues')->willReturn(['id' => 41]);

        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $id = $this->service->getEntityId($entity);

        // Should be plain string
        self::assertEquals('41', $id);
    }

    public function testFloatComparisonWithEpsilon(): void
    {
        // Use reflection to access private method valuesAreDifferent
        $reflection = new \ReflectionClass(AuditService::class);
        $method = $reflection->getMethod('valuesAreDifferent');
        $method->setAccessible(true);

        // Difference less than epsilon (1e-9) should be false
        self::assertFalse($method->invoke($this->service, 1.0000000001, 1.0000000002));

        // Significant difference should be true
        self::assertTrue($method->invoke($this->service, 1.0, 1.000001));
    }

    public function testCollectionTruncation(): void
    {
        $entity = new TestEntity();
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn([]);
        $metadata->method('getAssociationNames')->willReturn(['items']);

        // Create large collection > 100 items
        $items = new ArrayCollection(array_fill(0, 105, new TestEntity()));

        $metadata->method('getFieldValue')->willReturn($items);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $data = $this->service->getEntityData($entity);

        self::assertArrayHasKey('items', $data);
        self::assertIsArray($data['items']);
        self::assertArrayHasKey('_truncated', $data['items']);
        self::assertTrue($data['items']['_truncated']);
        self::assertEquals(105, $data['items']['_total_count']);
        self::assertCount(100, $data['items']['_sample']);
    }

    public function testExtractionFailureHandling(): void
    {
        $entity = new TestEntity();

        // Force exception
        $this->entityManager->method('getClassMetadata')->willThrowException(new \RuntimeException('DB Error'));

        $data = $this->service->getEntityData($entity);

        self::assertArrayHasKey('_extraction_failed', $data);
        self::assertTrue($data['_extraction_failed']);
        self::assertEquals('DB Error', $data['_error']);
    }

    // ========================================
    // NEW TESTS - Validation Coverage
    // ========================================

    /**
     * Test 1: Invalid Action Validation.
     */
    public function testInvalidActionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid action "invalid_action"');

        $log = new AuditLog();
        $log->setAction('invalid_action');
    }

    /**
     * Test 2: Valid Actions.
     */
    public function testAllValidActionsAreAccepted(): void
    {
        $log = new AuditLog();

        $validActions = [
            AuditLog::ACTION_CREATE,
            AuditLog::ACTION_UPDATE,
            AuditLog::ACTION_DELETE,
            AuditLog::ACTION_SOFT_DELETE,
            AuditLog::ACTION_RESTORE,
        ];

        foreach ($validActions as $action) {
            $log->setAction($action);
            self::assertEquals($action, $log->getAction());
        }
    }

    /**
     * Test 3: Invalid IP Address Validation.
     */
    public function testInvalidIpAddressThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IP address format');

        $log = new AuditLog();
        $log->setIpAddress('not-an-ip-address');
    }

    /**
     * Test 4: Valid IPv4 Address.
     */
    public function testValidIpv4AddressIsAccepted(): void
    {
        $log = new AuditLog();
        $log->setIpAddress('192.168.1.1');

        self::assertEquals('192.168.1.1', $log->getIpAddress());
    }

    /**
     * Test 5: Valid IPv6 Address.
     */
    public function testValidIpv6AddressIsAccepted(): void
    {
        $log = new AuditLog();
        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $log->setIpAddress($ipv6);

        self::assertEquals($ipv6, $log->getIpAddress());
    }

    /**
     * Test 6: Null IP Address is Allowed.
     */
    public function testNullIpAddressIsAccepted(): void
    {
        $log = new AuditLog();
        $log->setIpAddress(null);

        self::assertNull($log->getIpAddress());
    }

    /**
     * Test 7: Empty Entity Class Validation.
     */
    public function testEmptyEntityClassThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity class cannot be empty');

        $log = new AuditLog();
        $log->setEntityClass('   '); // Whitespace only
    }

    /**
     * Test 8: Entity Class is Trimmed.
     */
    public function testEntityClassIsTrimmed(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('  App\Entity\User  ');

        self::assertEquals('App\Entity\User', $log->getEntityClass());
    }

    /**
     * Test 9: Empty Entity ID Validation.
     */
    public function testEmptyEntityIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity ID cannot be empty');

        $log = new AuditLog();
        $log->setEntityId('   '); // Whitespace only
    }

    /**
     * Test 10: Entity ID is Trimmed.
     */
    public function testEntityIdIsTrimmed(): void
    {
        $log = new AuditLog();
        $log->setEntityId('  123  ');

        self::assertEquals('123', $log->getEntityId());
    }

    /**
     * Test 12: Fluent Interface on Setters.
     */
    public function testSettersReturnSelfForFluentInterface(): void
    {
        $log = new AuditLog();

        $result = $log->setEntityClass('Test')
            ->setEntityId('123')
            ->setAction(AuditLog::ACTION_CREATE)
            ->setUserId(1)
            ->setUsername('testuser');

        self::assertSame($log, $result);
    }

    /**
     * Test 13: Changed Fields Detection.
     */
    public function testChangedFieldsDetectionIgnoresUnchangedFields(): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $clock = self::createStub(ClockInterface::class);

        $service = new AuditService(
            $entityManager,
            $this->userResolver,
            $clock,
            $this->transactionIdGenerator,
            [],
            [],
            $this->logger
        );

        $entity = new TestEntity();
        $entityManager->method('getClassMetadata')->willReturn(self::createStub(ClassMetadata::class));
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $log = $service->createAuditLog(
            $entity,
            AuditLog::ACTION_UPDATE,
            ['name' => 'Old', 'email' => 'test@test.com', 'status' => 'active'],
            ['name' => 'New', 'email' => 'test@test.com', 'status' => 'active']
        );

        // Only 'name' changed
        self::assertEquals(['name'], $log->getChangedFields());
    }

    // ========================================
    // SENSITIVE FIELD MASKING TESTS
    // ========================================

    /**
     * Test #[Sensitive] attribute masks fields with default value.
     */
    public function testSensitiveAttributeMasksFieldsWithDefaultValue(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new AuditService(
            $entityManager,
            $this->userResolver,
            $this->clock,
            self::createStub(TransactionIdGenerator::class),
            [],
            [],
            $this->logger
        );

        $entity = new SensitivePropertyEntity();
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['name', 'password', 'ssn']);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('getFieldValue')->willReturnCallback(fn ($e, $f) => match ($f) {
            'name' => 'John',
            'password' => 'secret',
            'ssn' => '123-45-6789',
            default => null,
        });

        $entityManager->expects($this->once())->method('getClassMetadata')->willReturn($metadata);

        $data = $service->getEntityData($entity);

        self::assertEquals('John', $data['name']);
        self::assertEquals('**REDACTED**', $data['password']);
        self::assertEquals('****', $data['ssn']);
    }

    /**
     * Test #[SensitiveParameter] on constructor masks promoted properties.
     */
    public function testSensitiveParameterMasksPromotedProperties(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new AuditService(
            $entityManager,
            $this->userResolver,
            $this->clock,
            self::createStub(TransactionIdGenerator::class),
            [],
            [],
            $this->logger
        );

        $entity = new SensitiveConstructorEntity();
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['name', 'apiKey']);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('getFieldValue')->willReturnCallback(fn ($e, $f) => match ($f) {
            'name' => 'John',
            'apiKey' => 'secret-key',
            default => null,
        });

        $entityManager->expects($this->once())->method('getClassMetadata')->willReturn($metadata);

        $data = $service->getEntityData($entity);

        self::assertEquals('John', $data['name']);
        self::assertEquals('**REDACTED**', $data['apiKey']);
    }
}
