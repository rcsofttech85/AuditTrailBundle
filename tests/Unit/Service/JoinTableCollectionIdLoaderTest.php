<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;

final class JoinTableCollectionIdLoaderTest extends TestCase
{
    public function testLoadOriginalCollectionIdsFromDatabaseBuildsQueryAndNormalizesIds(): void
    {
        $owner = new class {
        };
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturn('10');

        $mapping = ManyToManyOwningSideMapping::fromMappingArrayAndNamingStrategy([
            'fieldName' => 'tags',
            'sourceEntity' => $owner::class,
            'targetEntity' => TestJoinTarget::class,
            'isOwningSide' => true,
            'joinTable' => [
                'name' => 'post_tag',
                'joinColumns' => [['name' => 'post_id', 'referencedColumnName' => 'id']],
                'inverseJoinColumns' => [['name' => 'tag_id', 'referencedColumnName' => 'id']],
            ],
        ], new DefaultNamingStrategy());

        $ownerMetadata = self::createStub(ClassMetadata::class);
        $ownerMetadata->method('getAssociationMapping')->willReturn($mapping);
        $ownerMetadata->method('getFieldForColumn')->willReturn('id');
        $ownerMetadata->method('getTypeOfField')->willReturn('integer');

        $targetMetadata = self::createStub(ClassMetadata::class);
        $targetMetadata->method('getFieldForColumn')->willReturn('id');
        $targetMetadata->method('getTypeOfField')->willReturn('integer');

        $result = self::createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn(['1', '2']);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->with('tag_id')->willReturnSelf();
        $queryBuilder->method('from')->with('post_tag')->willReturnSelf();
        $queryBuilder->method('where')->with('post_id = :ownerId')->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('ownerId', 'db-10', 'integer')
            ->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connection = self::createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);
        $connection->method('convertToDatabaseValue')->willReturnCallback(static fn (mixed $value): mixed => 'db-'.$value);
        $connection->method('convertToPHPValue')->willReturnCallback(static fn (mixed $value): int => (int) $value);

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturnCallback(static function (string $class) use ($owner, $ownerMetadata, $targetMetadata): ClassMetadata {
            if ($class === $owner::class) {
                return $ownerMetadata;
            }

            if ($class === TestJoinTarget::class) {
                return $targetMetadata;
            }

            throw new InvalidArgumentException('Unexpected metadata lookup for '.$class);
        });

        $loader = new JoinTableCollectionIdLoader($idResolver);

        self::assertSame([1, 2], $loader->loadOriginalCollectionIdsFromDatabase($owner, 'tags', $em));
    }
}

final class TestJoinTarget
{
}
