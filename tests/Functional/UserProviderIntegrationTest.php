<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function assert;
use function is_array;

class UserProviderIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = parent::createKernel($options);
        if ($kernel instanceof TestKernel && isset($options['audit_config'])) {
            assert(is_array($options['audit_config']));
            $kernel->setAuditConfig($options['audit_config']);
        }

        return $kernel;
    }

    private function setupDatabase(EntityManagerInterface $em): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    #[RunInSeparateProcess]
    public function testAuditLogCapturesCurrentUser(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        // 1. Create a user
        $user = new TestUser('test_user');
        $em->persist($user);
        $em->flush();

        // 2. Simulate logged in user
        $tokenStorage = $container->get(TokenStorageInterface::class);
        assert($tokenStorage instanceof TokenStorageInterface);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);

        // 3. Simulate Request for IP and UA
        $requestStack = $container->get(RequestStack::class);
        assert($requestStack instanceof RequestStack);
        $request = new Request([], [], [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'TestAgent',
        ]);
        $requestStack->push($request);

        // 4. Perform an action
        $entity = new TestEntity('User Test');
        $em->persist($entity);
        $em->flush();

        // 5. Verify Audit Log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
        ]);

        self::assertNotNull($auditLog);
        self::assertEquals($user->getId(), $auditLog->getUserId());
        self::assertSame('test_user', $auditLog->getUsername());
        self::assertSame('127.0.0.1', $auditLog->getIpAddress());
        self::assertSame('TestAgent', $auditLog->getUserAgent());
    }

    #[RunInSeparateProcess]
    public function testAuditLogWithNoUser(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        // No token set

        $entity = new TestEntity('No User Test');
        $em->persist($entity);
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
        ]);

        self::assertNotNull($auditLog);
        self::assertNull($auditLog->getUserId());
        self::assertNull($auditLog->getUsername());
    }
}
