<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestUser;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Fixtures\PublicIdSecurityUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function assert;

final class UserProviderIntegrationTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        if (self::$booted) {
            $tokenStorage = $this->getService(TokenStorageInterface::class);
            assert($tokenStorage instanceof TokenStorageInterface);
            $tokenStorage->setToken(null);

            $requestStack = $this->getService(RequestStack::class);
            assert($requestStack instanceof RequestStack);
            while ($requestStack->getCurrentRequest() !== null) {
                $requestStack->pop();
            }
        }

        parent::tearDown();
    }

    public function testAuditLogCapturesCurrentUser(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        // Create a user
        $user = new TestUser('test_user');
        $em->persist($user);
        $em->flush();

        // Simulate logged in user
        $tokenStorage = $this->getService(TokenStorageInterface::class);
        assert($tokenStorage instanceof TokenStorageInterface);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);

        // Simulate Request for IP and UA
        $requestStack = $this->getService(RequestStack::class);
        assert($requestStack instanceof RequestStack);
        $request = new Request([], [], [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'TestAgent',
        ]);
        $requestStack->push($request);

        // Perform an action
        $entity = new TestEntity('User Test');
        $em->persist($entity);
        $em->flush();

        // Verify Audit Log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
        ]);

        self::assertNotNull($auditLog);
        self::assertEquals($user->getId(), $auditLog->userId);
        self::assertSame('test_user', $auditLog->username);
        self::assertSame('127.0.0.1', $auditLog->ipAddress);
        self::assertSame('TestAgent', $auditLog->userAgent);
    }

    public function testAuditLogCapturesPublicIdSecurityUser(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $user = new PublicIdSecurityUser('public-id-42', 'public_user');

        $tokenStorage = $this->getService(TokenStorageInterface::class);
        assert($tokenStorage instanceof TokenStorageInterface);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $requestStack = $this->getService(RequestStack::class);
        assert($requestStack instanceof RequestStack);
        $requestStack->push(new Request([], [], [], [], [], [
            'REMOTE_ADDR' => '127.0.0.2',
            'HTTP_USER_AGENT' => 'PublicIdAgent',
        ]));

        $entity = new TestEntity('Public ID User Test');
        $em->persist($entity);
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
            'entityId' => (string) $entity->getId(),
        ]);

        self::assertNotNull($auditLog);
        self::assertSame('public-id-42', $auditLog->userId);
        self::assertSame('public_user', $auditLog->username);
        self::assertSame('127.0.0.2', $auditLog->ipAddress);
        self::assertSame('PublicIdAgent', $auditLog->userAgent);
    }

    public function testAuditLogWithNoUser(): void
    {
        self::bootKernel([
            'audit_config' => [
                'track_ip_address' => false,
            ],
        ]);
        $em = $this->getEntityManager();

        // No token set

        $entity = new TestEntity('No User Test');
        $em->persist($entity);
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
        ]);

        self::assertNotNull($auditLog);
        self::assertStringStartsWith('cli:', (string) $auditLog->userId);
        self::assertStringStartsWith('cli:', (string) $auditLog->username);
        self::assertNull($auditLog->ipAddress);
    }
}
