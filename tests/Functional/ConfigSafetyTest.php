<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditKernelSubscriber;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\CooldownPost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;

final class ConfigSafetyTest extends AbstractFunctionalTestCase
{
    public function testDeferredFailureWithoutFallbackDoesNotCrashAndDoesNotRollbackEntity(): void
    {
        TestKernel::$useThrowingTransport = true;

        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'fail_on_transport_error' => false,
                'fallback_to_database' => false,
                'transports' => [
                    'database' => ['enabled' => true],
                ],
            ],
        ];

        self::bootKernel($options);
        $em = $this->getEntityManager();

        $entity = new TestEntity('No Fallback Crash Safety');
        $em->persist($entity);
        $em->flush();
        $em->clear();

        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'No Fallback Crash Safety']);
        self::assertNotNull($savedEntity, 'Entity should still be persisted when deferred transport fails without fallback.');

        $auditLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $savedEntity->getId(),
        ]);
        self::assertSame([], $auditLogs, 'No audit log should be persisted when fallback is disabled and transport fails.');
    }

    public function testDisabledBundleWithIntegrityEnabledDoesNotCrashAndDoesNotAudit(): void
    {
        $options = [
            'audit_config' => [
                'enabled' => false,
                'integrity' => [
                    'enabled' => true,
                    'secret' => 'disabled-bundle-secret',
                ],
                'transports' => [
                    'database' => ['enabled' => false],
                    'http' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                ],
            ],
        ];

        self::bootKernel($options);
        $em = $this->getEntityManager();

        $entity = new TestEntity('Disabled Bundle Safety');
        $em->persist($entity);
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findAll();
        self::assertCount(0, $auditLogs, 'Disabled bundle must not emit audit logs even if other features are configured.');
    }

    public function testTrackIpAndUserAgentFlagsSuppressCapturedRequestMetadata(): void
    {
        $options = [
            'audit_config' => [
                'track_ip_address' => false,
                'track_user_agent' => false,
            ],
        ];

        self::bootKernel($options);
        $em = $this->getEntityManager();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(new Request([], [], [], [], [], [
            'REMOTE_ADDR' => '127.0.0.77',
            'HTTP_USER_AGENT' => 'SafetyAgent',
        ]));

        $entity = new TestEntity('No Request Metadata');
        $em->persist($entity);
        $em->flush();

        /** @var AuditLog|null $auditLog */
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $entity->getId(),
            'action' => AuditLogInterface::ACTION_CREATE,
        ]);

        self::assertNotNull($auditLog);
        self::assertNull($auditLog->ipAddress);
        self::assertNull($auditLog->userAgent);
    }

    public function testAuditedMethodsCanSuppressAccessLoggingWithoutCrashingRequestLifecycle(): void
    {
        $options = [
            'audit_config' => [
                'audited_methods' => ['HEAD'],
            ],
        ];

        self::bootKernel($options);
        $em = $this->getEntityManager();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $request = Request::create('/', 'GET');
        $requestStack->push($request);

        $post = new CooldownPost();
        $post->setTitle('Suppressed Access Log');
        $em->persist($post);
        $em->flush();
        $em->clear();

        $postId = $post->getId();
        self::assertNotNull($postId);

        $em->find(CooldownPost::class, $postId);

        $subscriber = $this->getService(AuditKernelSubscriber::class);
        assert($subscriber instanceof AuditKernelSubscriber);

        $kernel = $this->getService('kernel');
        assert($kernel instanceof KernelInterface);

        $subscriber->onKernelTerminate(new TerminateEvent(
            $kernel,
            $request,
            new Response()
        ));

        $auditLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CooldownPost::class,
            'entityId' => (string) $postId,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);

        self::assertCount(0, $auditLogs, 'GET requests must not produce access logs when audited_methods excludes GET.');
    }
}
