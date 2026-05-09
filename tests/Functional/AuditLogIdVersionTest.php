<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use Symfony\Component\Uid\Factory\UuidFactory;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV7;

final class AuditLogIdVersionTest extends AbstractFunctionalTestCase
{
    public function testAuditLogIdsRemainV7WhenHostAppDefaultUuidVersionIsV4(): void
    {
        TestKernel::$publicServiceIds = [AuditLogMessageFactory::class];

        self::bootKernel([
            'framework_config' => [
                'uid' => [
                    'default_uuid_version' => 4,
                ],
            ],
        ]);

        $container = self::getContainer();
        $globalUuidFactory = $container->get(UuidFactory::class);
        self::assertInstanceOf(UuidFactory::class, $globalUuidFactory);
        self::assertInstanceOf(UuidV4::class, $globalUuidFactory->create());

        $messageFactory = $container->get(AuditLogMessageFactory::class);
        self::assertInstanceOf(AuditLogMessageFactory::class, $messageFactory);

        $log = new AuditLog('App\Entity\Post', '42', AuditAction::Create);
        $message = $messageFactory->createPersistMessage(
            new AuditTransportContext(
                AuditPhase::PostFlush,
                self::createStub(EntityManagerInterface::class),
                $log,
            ),
        );

        self::assertNotNull($log->id);
        self::assertInstanceOf(UuidV7::class, $log->id);
        self::assertSame($log->id->toRfc4122(), $message->auditId);
    }
}
