<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;
use function is_array;

abstract class AbstractFunctionalTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$useThrowingTransport = false;

        $this->clearTestCache();
    }

    protected function tearDown(): void
    {
        // Reset any static state on the TestKernel
        TestKernel::$useThrowingTransport = false;

        parent::tearDown();
    }

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
            if (isset($options['audit_config'])) {
                assert(is_array($options['audit_config']));
                $kernel->setAuditConfig($options['audit_config']);
            }
            if (isset($options['doctrine_config'])) {
                assert(is_array($options['doctrine_config']));
                $kernel->setDoctrineConfig($options['doctrine_config']);
            }
        }

        return $kernel;
    }

    protected function getService(string $id): object
    {
        return self::getContainer()->get($id);
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        $em = $this->getService('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * Clears the default test cache pool if it exists.
     */
    protected function clearTestCache(): void
    {
        if (self::getContainer()->has('audit_test.cache')) {
            $cache = $this->getService('audit_test.cache');
            if ($cache instanceof CacheItemPoolInterface) {
                $cache->clear();
            }
        }
    }
}
