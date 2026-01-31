<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class PrependExtensionTest extends KernelTestCase
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
        if ($kernel instanceof TestKernel) {
            $kernel->setDoctrineConfig([
                'orm' => [
                    'auto_mapping' => false,
                ],
            ]);
        }

        return $kernel;
    }

    #[RunInSeparateProcess]
    public function testAuditLogIsMappedWithAutoMappingFalse(): void
    {
        self::bootKernel();
        try {
            $container = self::getContainer();
            $em = $container->get('doctrine.orm.entity_manager');
            assert($em instanceof EntityManagerInterface);

            $metadata = $em->getClassMetadata(AuditLog::class);
            self::assertSame(AuditLog::class, $metadata->getName());
        } finally {
            self::ensureKernelShutdown();
        }
    }
}
