<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Event;

use LogicException;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditIntegrityService;
use Rcsofttech\AuditTrailBundle\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class AuditLogCreatedEventTest extends AbstractFunctionalTestCase
{
    public function testModifyLogInEventPreservesSignature(): void
    {
        $options = [
            'audit_config' => [
                'integrity' => [
                    'enabled' => true,
                    'secret' => 'test-secret',
                ],
            ],
        ];

        $this->bootTestKernel($options);
        $container = self::getContainer();

        $integrityService = $container->get(AuditIntegrityService::class);
        self::assertInstanceOf(AuditIntegrityServiceInterface::class, $integrityService);

        $transport = $container->get('rcsofttech_audit_trail.transport.doctrine');
        self::assertInstanceOf(AuditTransportInterface::class, $transport);

        $eventDispatcher = new EventDispatcher();

        //  Add a listener that modifies the log (a signed field)
        $eventDispatcher->addListener(AuditLogCreatedEvent::class, static function (AuditLogCreatedEvent $event) {
            $log = $event->auditLog;
            $log->entityId = 'MODIFIED';
        });

        $dispatcher = new AuditDispatcher(
            $transport,
            $eventDispatcher,
            $integrityService,
            null,
            true, // failOnTransportError
            true  // fallbackToDatabase
        );

        $log = new AuditLog('App\Entity\User', '1', 'create');

        $em = $this->getEntityManager();

        //  Dispatch
        $dispatcher->dispatch($log, $em, 'post_flush');

        //  Verify modification
        self::assertSame('MODIFIED', $log->entityId, 'Log should be modified in the event listener');

        //  Verify signature integrity
        self::assertTrue($integrityService->verifySignature($log), 'Signature should be valid even after modification in event listener');
    }

    public function testCannotModifyAfterSealInEvent(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'create');
        $log->seal();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify a sealed audit log.');

        $log->context = ['foo' => 'bar'];
    }
}
