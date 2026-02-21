<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Car;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Dog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

#[AllowMockObjectsWithoutExpectations]
class InheritanceTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestKernel::$useThrowingTransport = false;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = parent::createKernel($options);
        if ($kernel instanceof TestKernel && isset($options['audit_config'])) {
            $kernel->setAuditConfig($options['audit_config']);
        }

        return $kernel;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSTIInheritanceAudit(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');

        $car = new Car('Tesla Model S');
        $car->setDoors(4);
        $em->persist($car);
        $em->flush();

        $auditRepo = $em->getRepository(AuditLog::class);
        $logs = $auditRepo->findAll();

        self::assertCount(1, $logs, 'Should have 1 audit log for Car (STI)');
        self::assertSame(Car::class, $logs[0]->entityClass);
        $newValues = $logs[0]->newValues;
        self::assertNotNull($newValues);
        self::assertSame('Tesla Model S', $newValues['model']);
        self::assertSame(4, $newValues['doors']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testJTIInheritanceAudit(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');

        $dog = new Dog('Buddy');
        $dog->setBreed('Golden Retriever');
        $em->persist($dog);
        $em->flush();

        $auditRepo = $em->getRepository(AuditLog::class);
        $logs = $auditRepo->findAll();

        self::assertCount(1, $logs, 'Should have 1 audit log for Dog (JTI)');
        self::assertSame(Dog::class, $logs[0]->entityClass);
        $newValues = $logs[0]->newValues;
        self::assertNotNull($newValues);
        self::assertSame('Buddy', $newValues['name']);
        self::assertSame('Golden Retriever', $newValues['breed']);
    }
}
