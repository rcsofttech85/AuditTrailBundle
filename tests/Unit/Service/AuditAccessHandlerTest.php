<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Http\AuditRequestAttributes;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessContextProvider;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessCooldownManager;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessIntentResolver;
use ReflectionClass;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditAccessHandlerTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private AuditDispatcherInterface&MockObject $dispatcher;

    private UserResolverInterface&Stub $userResolver;

    private EntityIdResolverInterface&Stub $idResolver;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $this->userResolver = self::createStub(UserResolverInterface::class);
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);
        $this->requestStack = new RequestStack();
    }

    /**
     * @param array<string> $auditedMethods
     */
    private function createHandler(
        ?CacheItemPoolInterface $cache = null,
        array $auditedMethods = ['GET'],
    ): AuditAccessHandler {
        return new AuditAccessHandler(
            $this->auditService,
            $this->dispatcher,
            $this->requestStack,
            $this->idResolver,
            new AuditAccessIntentResolver(),
            new AuditAccessCooldownManager($cache),
            new AuditAccessContextProvider($this->userResolver),
            null,
            $auditedMethods,
        );
    }

    public function testHandleAccessRespectsConfiguredMethods(): void
    {
        $handler = $this->createHandler(null, ['POST']);

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        // Should skip because it's a GET request
        $this->auditService->expects($this->never())->method('getAccessAttribute');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessAllowsMultipleMethods(): void
    {
        $handler = $this->createHandler(null, ['GET', 'POST']);

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        // Should NOT skip, it should proceed to check attributes
        $this->auditService->expects($this->once())->method('getAccessAttribute')->willReturn(null);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessSkipsUnsafeMethodsEvenIfConfigured(): void
    {
        $handler = $this->createHandler(null, ['GET', 'POST', 'DELETE']);

        $request = Request::create('/test', 'POST');
        $this->requestStack->push($request);

        $this->auditService->expects($this->never())->method('getAccessAttribute');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessSkipsEditRouteIntent(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/posts/1/edit', 'GET');
        $request->attributes->set('_route', 'post_edit');
        $this->requestStack->push($request);

        $this->auditService->expects($this->never())->method('getAccessAttribute');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessAllowsDetailCrudAction(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/admin/post/1', 'GET');
        $request->attributes->set('crudAction', 'detail');
        $this->requestStack->push($request);

        $this->auditService->expects($this->once())->method('getAccessAttribute')->willReturn(null);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessAllowsExplicitReadIntentOverride(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/posts/1/edit', 'GET');
        $request->attributes->set('_route', 'post_edit');
        $request->attributes->set(AuditRequestAttributes::ACCESS_INTENT, true);
        $this->requestStack->push($request);

        $this->auditService->expects($this->once())->method('getAccessAttribute')->willReturn(null);
        $this->dispatcher->expects($this->never())->method('dispatch');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessSkipsWhenExplicitReadIntentOverrideIsFalse(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/posts/1', 'GET');
        $request->attributes->set('crudAction', 'detail');
        $request->attributes->set(AuditRequestAttributes::ACCESS_INTENT, false);
        $this->requestStack->push($request);

        $this->auditService->expects($this->never())->method('getAccessAttribute');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessSkipsSubRequests(): void
    {
        $handler = $this->createHandler();

        $mainRequest = Request::create('/page', 'GET');
        $subRequest = Request::create('/_fragment', 'GET');
        $this->requestStack->push($mainRequest);
        $this->requestStack->push($subRequest);

        $this->auditService->expects($this->never())->method('getAccessAttribute');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $handler->handleAccess($entity, $om);
    }

    public function testHandleAccessCachesAndSkips(): void
    {
        $cache = self::createStub(CacheItemPoolInterface::class);
        $item = self::createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $cache->method('getItem')->willReturn($item);

        $handler = $this->createHandler($cache);

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 60);

        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        // Since it's a hit, it should NOT dispatch
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->auditService->expects($this->never())->method('createAuditLog');

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

        $handler = $this->createHandler($cache);

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 60);

        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $auditLog = self::createStub(AuditLog::class);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($auditLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($auditLog, $om, AuditPhase::PostLoad, null, $entity)->willReturn(true);
        $om->method('isOpen')->willReturn(true);

        $handler->handleAccess($entity, $om);
        $handler->flushPendingAccesses();
    }

    public function testHandleAccessDoesNotPersistCooldownWhenDispatchReturnsFalse(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->expects($this->never())->method('set');
        $item->expects($this->never())->method('expiresAfter');
        $cache->method('getItem')->willReturn($item);
        $cache->expects($this->never())->method('save');

        $handler = $this->createHandler($cache);

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);
        $om->method('isOpen')->willReturn(true);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 60);
        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');
        $auditLog = self::createStub(AuditLog::class);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($auditLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($auditLog, $om, AuditPhase::PostLoad, null, $entity)->willReturn(false);

        $handler->handleAccess($entity, $om);
        $handler->flushPendingAccesses();
    }

    public function testMarkAsAudited(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $handler->markAsAudited('stdClass:1');

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 0);

        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1'); // matches key

        // It's marked as audited so it should NOT dispatch
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->auditService->expects($this->never())->method('createAuditLog');

        $handler->handleAccess($entity, $om);
    }

    public function testReset(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);
        $handler->markAsAudited('App\Entity\User:1');

        $handler->reset();

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 0);
        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        // It has been reset, so it should queue and dispatch again
        $auditLog = self::createStub(AuditLog::class);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($auditLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($auditLog, $om, AuditPhase::PostLoad, null, $entity);
        $om->method('isOpen')->willReturn(true);

        $handler->handleAccess($entity, $om);
        $handler->flushPendingAccesses();
    }

    public function testFlushPendingAccessesDispatchesQueuedAudit(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);
        $om->method('isOpen')->willReturn(true);
        $om->method('getClassMetadata')->willReturn(new ClassMetadata(stdClass::class));

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 0);
        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $auditLog = self::createStub(AuditLog::class);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($auditLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($auditLog, $om, AuditPhase::PostLoad, null, $entity);

        $handler->handleAccess($entity, $om);
        $handler->flushPendingAccesses();
    }

    public function testFlushPendingAccessesUsesCapturedIpAndUserAgentContext(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/test', 'GET');
        $request->server->set('REMOTE_ADDR', '127.0.0.9');
        $request->headers->set('User-Agent', 'CapturedAgent');
        $this->requestStack->push($request);

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);
        $om->method('isOpen')->willReturn(true);
        $om->method('getClassMetadata')->willReturn(new ClassMetadata(stdClass::class));

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 0);
        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');
        $this->userResolver->method('getUserId')->willReturn('u1');
        $this->userResolver->method('getUsername')->willReturn('admin');
        $this->userResolver->method('getIpAddress')->willReturn('127.0.0.9');
        $this->userResolver->method('getUserAgent')->willReturn('CapturedAgent');

        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $entity,
                AuditLogInterface::ACTION_ACCESS,
                null,
                null,
                self::callback(static function (array $context): bool {
                    return ($context[AuditLogInterface::CONTEXT_USER_ID] ?? null) === 'u1'
                        && ($context[AuditLogInterface::CONTEXT_USERNAME] ?? null) === 'admin'
                        && ($context[AuditLogInterface::CONTEXT_IP_ADDRESS] ?? null) === '127.0.0.9'
                        && ($context[AuditLogInterface::CONTEXT_USER_AGENT] ?? null) === 'CapturedAgent'
                        && ($context['message'] ?? null) === 'test'
                        && ($context['level'] ?? null) === 'read';
                })
            )
            ->willReturn(self::createStub(AuditLog::class));

        $this->dispatcher->expects($this->once())->method('dispatch');

        $handler->handleAccess($entity, $om);
        $handler->flushPendingAccesses();
    }

    public function testMarkAsAuditedCancelsPendingAccessAudit(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $entity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);
        $om->method('isOpen')->willReturn(true);

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 0);
        $this->auditService->method('getAccessAttribute')->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $handler->handleAccess($entity, $om);

        $reflection = new ReflectionClass($handler);
        $property = $reflection->getProperty('pendingAccesses');
        /** @var array<string, mixed> $pendingAccesses */
        $pendingAccesses = $property->getValue($handler);
        $pendingKey = array_key_first($pendingAccesses);
        self::assertNotNull($pendingKey);

        $handler->markAsAudited($pendingKey);
        $handler->flushPendingAccesses();
    }

    public function testHandleAccessDeduplicatesProxyAndRealClassLoads(): void
    {
        $handler = $this->createHandler();

        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $proxyEntity = new class extends stdClass {
            public function getId(): int
            {
                return 1;
            }
        };
        $realEntity = new stdClass();
        $om = self::createStub(EntityManagerInterface::class);
        $om->method('isOpen')->willReturn(true);
        $om->method('getClassMetadata')->willReturn(new ClassMetadata(stdClass::class));

        $accessAttr = new AuditAccess(level: 'read', message: 'test', cooldown: 0);
        $this->auditService->expects($this->exactly(2))
            ->method('getAccessAttribute')
            ->with(stdClass::class)
            ->willReturn($accessAttr);
        $this->auditService->method('passesVoters')->willReturn(true);
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $auditLog = self::createStub(AuditLog::class);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($auditLog);
        $this->dispatcher->expects($this->once())->method('dispatch');

        $handler->handleAccess($proxyEntity, $om);
        $handler->handleAccess($realEntity, $om);
        $handler->flushPendingAccesses();
    }
}
