<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function assert;

class UserProviderIntegrationTest extends AbstractFunctionalTestCase
{
    public function testAuditLogCapturesCurrentUser(): void
    {
        $this->bootTestKernel();
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
        self::assertEquals($user->getId(), $auditLog->getUserId());
        self::assertSame('test_user', $auditLog->getUsername());
        self::assertSame('127.0.0.1', $auditLog->getIpAddress());
        self::assertSame('TestAgent', $auditLog->getUserAgent());
    }

    public function testAuditLogWithNoUser(): void
    {
        $this->bootTestKernel();
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
        self::assertStringStartsWith('cli:', (string) $auditLog->getUserId());
        self::assertStringStartsWith('cli:', (string) $auditLog->getUsername());
    }
}
