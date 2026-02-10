<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ParsedExpression;

class ExpressionLanguageVoter implements AuditVoterInterface
{
    private ?ExpressionLanguage $expressionLanguage = null;

    /** @var array<string, ParsedExpression> */
    private array $cache = [];

    public function __construct(
        private readonly MetadataCache $metadataCache,
        private readonly UserResolverInterface $userResolver,
    ) {
    }

    public function vote(object $entity, string $action, array $changeSet): bool
    {
        $condition = $this->metadataCache->getAuditCondition($entity::class);
        if ($condition === null) {
            return true;
        }

        if ($this->expressionLanguage === null) {
            $this->expressionLanguage = new ExpressionLanguage();
        }

        $expression = $condition->expression;
        if (!isset($this->cache[$expression])) {
            $this->cache[$expression] = $this->expressionLanguage->parse($expression, [
                'object',
                'action',
                'changeSet',
                'user',
            ]);
        }

        return (bool) $this->expressionLanguage->evaluate($this->cache[$expression], [
            'object' => $entity,
            'action' => $action,
            'changeSet' => $changeSet,
            'user' => (object) [
                'id' => $this->userResolver->getUserId(),
                'username' => $this->userResolver->getUsername(),
                'ip' => $this->userResolver->getIpAddress(),
            ],
        ]);
    }
}
