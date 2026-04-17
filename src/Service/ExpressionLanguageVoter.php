<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ParsedExpression;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Throwable;

use function array_any;
use function str_contains;
use function strtolower;

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
    private ?ExpressionLanguage $expressionLanguage = null;

    /** @var array<string, ParsedExpression> */
    private array $cache = [];

    private const array ALLOWED_VARIABLES = ['object', 'action', 'changeSet', 'user'];

    /** Characters that strongly indicate external/untrusted expression injection. */
    private const array FORBIDDEN_PATTERNS = [
        'system(',
        'exec(',
        'passthru(',
        'shell_exec(',
        'popen(',
        'proc_open(',
        'file_get_contents(',
        'file_put_contents(',
        'unlink(',
        'rmdir(',
        'constant(',
    ];

    public function __construct(
        private readonly MetadataCacheInterface $metadataCache,
        private readonly UserResolverInterface $userResolver,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Override]
    public function vote(object $entity, string $action, array $changeSet): bool
    {
        $condition = $this->metadataCache->getAuditCondition($entity::class);
        if ($condition === null) {
            return true;
        }

        $expression = $condition->expression;

        if (!$this->isExpressionSafe($expression)) {
            $this->logger?->critical('Blocked potentially dangerous AuditCondition expression.', [
                'entity' => $entity::class,
                'expression' => $expression,
            ]);

            return false;
        }

        try {
            $this->expressionLanguage ??= new ExpressionLanguage();

            if (!isset($this->cache[$expression])) {
                $this->cache[$expression] = $this->expressionLanguage->parse(
                    $expression,
                    self::ALLOWED_VARIABLES,
                );
            }

            return (bool) $this->expressionLanguage->evaluate($this->cache[$expression], [
                'object' => $entity,
                'action' => $action,
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

    private function isExpressionSafe(string $expression): bool
    {
        $normalized = strtolower($expression);

        return !array_any(
            self::FORBIDDEN_PATTERNS,
            static fn (string $pattern): bool => str_contains($normalized, $pattern),
        );
    }
}
