<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\ExpressionLanguageVoter;
use stdClass;

final class ExpressionLanguageVoterTest extends TestCase
{
    public function testVoteWithNoCondition(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(null);
        $userResolver = self::createStub(UserResolverInterface::class);

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, 'update', []));
    }

    public function testVoteWithConditionTrue(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('action == "update"'));
        $userResolver = self::createStub(UserResolverInterface::class);

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, 'update', []));
    }

    public function testVoteWithConditionFalse(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('action == "create"'));
        $userResolver = self::createStub(UserResolverInterface::class);

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertFalse($voter->vote($entity, 'update', []));
    }

    public function testVoteWithObjectAccess(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('object.price > 100'));
        $userResolver = self::createStub(UserResolverInterface::class);

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
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('user.username == "admin"'));

        $userResolver = self::createStub(UserResolverInterface::class);
        $userResolver->method('getUsername')->willReturn('admin');

        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, 'update', []));

        $userResolver = self::createStub(UserResolverInterface::class);
        $userResolver->method('getUsername')->willReturn('guest');
        $voter = new ExpressionLanguageVoter($metadataCache, $userResolver);

        self::assertFalse($voter->vote($entity, 'update', []));
    }

    public function testIsExpressionSafeBlocksDangerousPatterns(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('system("rm -rf /")'));

        $voter = new ExpressionLanguageVoter(
            $metadataCache,
            self::createStub(UserResolverInterface::class)
        );

        self::assertFalse($voter->vote(new stdClass(), 'update', []));
    }

    public function testVoteHandlesSyntaxError(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('invalid expression ++'));

        $voter = new ExpressionLanguageVoter(
            $metadataCache,
            self::createStub(UserResolverInterface::class)
        );

        self::assertFalse($voter->vote(new stdClass(), 'update', []));
    }

    public function testVoteHandlesEvaluationError(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        // This will cause an evaluation error because 'undefined_var' is not in ALLOWED_VARIABLES
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('undefined_var > 5'));

        $voter = new ExpressionLanguageVoter(
            $metadataCache,
            self::createStub(UserResolverInterface::class)
        );

        self::assertFalse($voter->vote(new stdClass(), 'update', []));
    }
}
