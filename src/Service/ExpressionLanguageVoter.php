<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ParsedExpression;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Throwable;

/**
 * Evaluates `#[AuditCondition]` expressions to determine audit eligibility.
 *
 * @security Expressions are pre-parsed at first use and restricted to the variables
 *           `object`, `action`, `changeSet`, and `user`. NEVER source expression strings
 *           from user input, database records, or any untrusted origin — doing so would
 *           allow arbitrary code execution.
 */
final class ExpressionLanguageVoter implements AuditVoterInterface
{
    /** @var array<string, ParsedExpression> */
    private array $cache = [];

    private const array ALLOWED_VARIABLES = ['object', 'action', 'changeSet', 'user'];

    public function __construct(
        private readonly MetadataCacheInterface $metadataCache,
        private readonly UserResolverInterface $userResolver,
        private readonly ExpressionLanguage $expressionLanguage,
        private readonly AuditConditionExpressionValidator $validator,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Override]
    public function vote(object $entity, AuditAction $action, array $changeSet): bool
    {
        $condition = $this->metadataCache->getAuditCondition($entity::class);
        if ($condition === null) {
            return true;
        }

        $expression = $condition->expression;

        try {
            if (!isset($this->cache[$expression])) {
                $parsedExpression = $this->expressionLanguage->parse(
                    $expression,
                    self::ALLOWED_VARIABLES,
                );

                if (!$this->validator->isSafe($parsedExpression)) {
                    $this->logger?->critical('Blocked unsafe AuditCondition expression.', [
                        'entity' => $entity::class,
                        'expression' => $expression,
                    ]);

                    return false;
                }

                $this->cache[$expression] = $parsedExpression;
            }

            return (bool) $this->expressionLanguage->evaluate($this->cache[$expression], [
                'object' => $entity,
                'action' => $action->value,
                'changeSet' => $changeSet,
                'user' => new readonly class($this->userResolver->getUserId(), $this->userResolver->getUsername(), $this->userResolver->getIpAddress()) {
                    public function __construct(
                        public ?string $id,
                        public ?string $username,
                        public ?string $ip,
                    ) {
                    }
                },
            ]);
        } catch (SyntaxError $e) {
            $this->logger?->error('AuditCondition expression syntax error.', [
                'entity' => $entity::class,
                'expression' => $expression,
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (Throwable $e) {
            $this->logger?->error('AuditCondition expression evaluation failed.', [
                'entity' => $entity::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
