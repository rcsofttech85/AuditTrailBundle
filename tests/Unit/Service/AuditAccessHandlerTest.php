<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
final class AuditAccessHandlerTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private AuditDispatcherInterface&MockObject $dispatcher;

    private UserResolverInterface&MockObject $userResolver;

    private EntityIdResolverInterface&MockObject $idResolver;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $this->userResolver = $this->createMock(UserResolverInterface::class);
        $this->idResolver = $this->createMock(EntityIdResolverInterface::class);
        $this->requestStack = new RequestStack();
    }

    public function testHandleAccessRespectsConfiguredMethods(): void
    {
        $handler = new AuditAccessHandler(
            $this->auditService,
            $this->dispatcher,
            $this->userResolver,
            $this->requestStack,
            $this->idResolver,
            null, // cache
            null, // logger
            ['POST'] // Only audit POST
        );

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        // Should skip because it's a GET request
        $this->auditService->expects($this->never())->method('getAccessAttribute');

        $entity = new stdClass();
        $om = $this->createMock(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessAllowsMultipleMethods(): void
    {
        $handler = new AuditAccessHandler(
            $this->auditService,
            $this->dispatcher,
            $this->userResolver,
            $this->requestStack,
            $this->idResolver,
            null, // cache
            null, // logger
            ['GET', 'POST']
        );

        $request = Request::create('/test', 'POST');
        $this->requestStack->push($request);

        // Should NOT skip, it should proceed to check attributes
        $this->auditService->expects($this->once())->method('getAccessAttribute')->willReturn(null);

        $entity = new stdClass();
        $om = $this->createMock(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessCachesAndSkips(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $cache->method('getItem')->willReturn($item);

        $handler = new AuditAccessHandler(
            $this->auditService,
            $this->dispatcher,
            $this->userResolver,
            $this->requestStack,
            $this->idResolver,
            $cache, // mock cache that returns a hit
            null,
            ['GET']
        );

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $entity = new stdClass();
        $om = $this->createMock(EntityManagerInterface::class);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 60);

        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        // Since it's a hit, it should NOT dispatch
        $this->dispatcher->expects($this->never())->method('dispatch');

        $handler->handleAccess($entity, $om);

        // Calling it again relies on runtime memory `$auditedEntities` array and also skips
        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessSavesToCacheAndDispatchesOnMiss(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false); // Cache Miss
        $item->expects($this->once())->method('set')->with(true);
        $item->expects($this->once())->method('expiresAfter')->with(60);
        $cache->method('getItem')->willReturn($item);
        $cache->expects($this->once())->method('save')->with($item); // Verify it saves!

        $handler = new AuditAccessHandler(
            $this->auditService,
            $this->dispatcher,
            $this->userResolver,
            $this->requestStack,
            $this->idResolver,
            $cache, // mock cache
            null,
            ['GET']
        );

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $entity = new stdClass();
        $om = $this->createMock(EntityManagerInterface::class);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 60);

        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $auditLog = $this->createMock(AuditLog::class);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($auditLog);

        // Since it's a miss, it MUST dispatch
        $this->dispatcher->expects($this->once())->method('dispatch');

        $handler->handleAccess($entity, $om);
    }

    public function testMarkAsAudited(): void
    {
        $handler = new AuditAccessHandler(
            $this->auditService,
            $this->dispatcher,
            $this->userResolver,
            $this->requestStack,
            $this->idResolver,
            null,
            null,
            ['GET']
        );

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $handler->markAsAudited('stdClass:1');

        $entity = new stdClass();
        $om = $this->createMock(EntityManagerInterface::class);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 0);

        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1'); // matches key

        // It's marked as audited so it should NOT dispatch
        $this->dispatcher->expects($this->never())->method('dispatch');

        $handler->handleAccess($entity, $om);
    }

    public function testReset(): void
    {
        $handler = new AuditAccessHandler($this->auditService, $this->dispatcher, $this->userResolver, $this->requestStack, $this->idResolver);

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);
        $handler->markAsAudited('App\Entity\User:1');

        $handler->reset();

        $entity = new stdClass();
        $om = $this->createMock(EntityManagerInterface::class);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 0);
        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        // It has been reset, so it should dispatch again
        $auditLog = $this->createMock(AuditLog::class);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($auditLog);
        $this->dispatcher->expects($this->once())->method('dispatch');

        $handler->handleAccess($entity, $om);
    }
}
