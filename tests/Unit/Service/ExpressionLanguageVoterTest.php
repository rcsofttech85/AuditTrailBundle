<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\ExpressionLanguageVoter;
use Rcsofttech\AuditTrailBundle\Service\MetadataCache;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class ExpressionLanguageVoterTest extends TestCase
{
    public function testVoteWithNoCondition(): void
    {
        $metadataCache = $this->createMock(MetadataCache::class);
        $metadataCache->method('getAuditCondition')->willReturn(null);
        $userResolver = $this->createMock(UserResolverInterface::class);

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, 'update', []));
    }

    public function testVoteWithConditionTrue(): void
    {
        $metadataCache = $this->createMock(MetadataCache::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('action == "update"'));
        $userResolver = $this->createMock(UserResolverInterface::class);

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, 'update', []));
    }

    public function testVoteWithConditionFalse(): void
    {
        $metadataCache = $this->createMock(MetadataCache::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('action == "create"'));
        $userResolver = $this->createMock(UserResolverInterface::class);

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertFalse($voter->vote($entity, 'update', []));
    }

    public function testVoteWithObjectAccess(): void
    {
        $metadataCache = $this->createMock(MetadataCache::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('object.price > 100'));
        $userResolver = $this->createMock(UserResolverInterface::class);

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new class {
            public int $price = 150;
        };

        self::assertTrue($voter->vote($entity, 'update', []));

        $entity->price = 50;
        self::assertFalse($voter->vote($entity, 'update', []));
    }

    public function testVoteWithUserContext(): void
    {
        $metadataCache = $this->createMock(MetadataCache::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('user.username == "admin"'));

        $userResolver = $this->createMock(UserResolverInterface::class);
        $userResolver->method('getUsername')->willReturn('admin');

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, 'update', []));

        $userResolver = $this->createMock(UserResolverInterface::class);
        $userResolver->method('getUsername')->willReturn('guest');
        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);

        self::assertFalse($voter->vote($entity, 'update', []));
    }

    public function testIsExpressionSafeBlocksDangerousPatterns(): void
    {
        $metadataCache = $this->createMock(MetadataCache::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('system("rm -rf /")'));

        $voter = new ExpressionLanguageVoter(
            $metadataCache,
            $this->createMock(UserResolverInterface::class)
        );

        self::assertFalse($voter->vote(new stdClass(), 'update', []));
    }

    public function testVoteHandlesSyntaxError(): void
    {
        $metadataCache = $this->createMock(MetadataCache::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('invalid expression ++'));

        $voter = new ExpressionLanguageVoter(
            $metadataCache,
            $this->createMock(UserResolverInterface::class)
        );

        self::assertFalse($voter->vote(new stdClass(), 'update', []));
    }

    public function testVoteHandlesEvaluationError(): void
    {
        $metadataCache = $this->createMock(MetadataCache::class);
        // This will cause an evaluation error because 'undefined_var' is not in ALLOWED_VARIABLES
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('undefined_var > 5'));

        $voter = new ExpressionLanguageVoter(
            $metadataCache,
            $this->createMock(UserResolverInterface::class)
        );

        self::assertFalse($voter->vote(new stdClass(), 'update', []));
    }
}
