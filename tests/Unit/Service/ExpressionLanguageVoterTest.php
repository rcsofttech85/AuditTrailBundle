<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Attribute\AuditCondition;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AuditConditionExpressionValidator;
use Rcsofttech\AuditTrailBundle\Service\ExpressionLanguageVoter;
use stdClass;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

final class ExpressionLanguageVoterTest extends TestCase
{
    private function createVoter(
        MetadataCacheInterface $metadataCache,
        UserResolverInterface $userResolver,
        ?\Psr\Log\LoggerInterface $logger = null,
    ): ExpressionLanguageVoter {
        return new ExpressionLanguageVoter(
            $metadataCache,
            $userResolver,
            new ExpressionLanguage(),
            new AuditConditionExpressionValidator(),
            $logger,
        );
    }

    public function testVoteWithNoCondition(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(null);
        $userResolver = self::createStub(UserResolverInterface::class);

        $voter = $this->createVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, AuditAction::Update, []));
    }

    public function testVoteWithConditionTrue(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('action == "update"'));
        $userResolver = self::createStub(UserResolverInterface::class);

        $voter = $this->createVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, AuditAction::Update, []));
    }

    public function testVoteWithConditionFalse(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('action == "create"'));
        $userResolver = self::createStub(UserResolverInterface::class);

        $voter = $this->createVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertFalse($voter->vote($entity, AuditAction::Update, []));
    }

    public function testVoteWithObjectAccess(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('object.price > 100'));
        $userResolver = self::createStub(UserResolverInterface::class);

        $voter = $this->createVoter($metadataCache, $userResolver);
        $entity = new class {
            public int $price = 150;
        };

        self::assertTrue($voter->vote($entity, AuditAction::Update, []));

        $entity->price = 50;
        self::assertFalse($voter->vote($entity, AuditAction::Update, []));
    }

    public function testVoteAllowsAccessorMethodCallsWithoutArguments(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('object.getPrice() > 100'));
        $userResolver = self::createStub(UserResolverInterface::class);

        $voter = $this->createVoter($metadataCache, $userResolver);
        $entity = new class {
            public function getPrice(): int
            {
                return 150;
            }
        };

        self::assertTrue($voter->vote($entity, AuditAction::Update, []));
    }

    public function testVoteWithUserContext(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('user.username == "admin"'));

        $userResolver = self::createStub(UserResolverInterface::class);
        $userResolver->method('getUsername')->willReturn('admin');

        $voter = $this->createVoter($metadataCache, $userResolver);
        $entity = new stdClass();

        self::assertTrue($voter->vote($entity, AuditAction::Update, []));

        $userResolver = self::createStub(UserResolverInterface::class);
        $userResolver->method('getUsername')->willReturn('guest');
        $voter = $this->createVoter($metadataCache, $userResolver);

        self::assertFalse($voter->vote($entity, AuditAction::Update, []));
    }

    public function testVoteBlocksFunctionCalls(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('system("rm -rf /")'));

        $voter = $this->createVoter($metadataCache, self::createStub(UserResolverInterface::class));

        self::assertFalse($voter->vote(new stdClass(), AuditAction::Update, []));
    }

    public function testVoteBlocksImperativeMethodCalls(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('object.deleteAll()'));

        $voter = $this->createVoter($metadataCache, self::createStub(UserResolverInterface::class));

        self::assertFalse($voter->vote(new class {
            public function deleteAll(): bool
            {
                return true;
            }
        }, AuditAction::Update, []));
    }

    public function testVoteHandlesSyntaxError(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('invalid expression ++'));

        $voter = $this->createVoter($metadataCache, self::createStub(UserResolverInterface::class));

        self::assertFalse($voter->vote(new stdClass(), AuditAction::Update, []));
    }

    public function testVoteHandlesEvaluationError(): void
    {
        $metadataCache = self::createStub(MetadataCacheInterface::class);
        // This will cause an evaluation error because 'undefined_var' is not in ALLOWED_VARIABLES
        $metadataCache->method('getAuditCondition')->willReturn(new AuditCondition('undefined_var > 5'));

        $voter = $this->createVoter($metadataCache, self::createStub(UserResolverInterface::class));

        self::assertFalse($voter->vote(new stdClass(), AuditAction::Update, []));
    }
}
