<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\AuditConditionExpressionValidator;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\ParsedExpression;

final class AuditConditionExpressionValidatorTest extends TestCase
{
    private AuditConditionExpressionValidator $validator;

    private ExpressionLanguage $expressionLanguage;

    protected function setUp(): void
    {
        $this->validator = new AuditConditionExpressionValidator();
        $this->expressionLanguage = new ExpressionLanguage();
    }

    public function testAllowsSafeReadOnlyExpression(): void
    {
        $expression = $this->parse('object.getTitle() == "published" and action == "update"');

        self::assertTrue($this->validator->isSafe($expression));
    }

    public function testAllowsPropertyAndArrayAccess(): void
    {
        $expression = $this->parse('changeSet["status"][1] ?? object.status');

        self::assertTrue($this->validator->isSafe($expression));
    }

    public function testRejectsUnknownRootVariable(): void
    {
        $expression = $this->parse('subject == "bad"');

        self::assertFalse($this->validator->isSafe($expression));
    }

    public function testRejectsFunctionCalls(): void
    {
        $expression = $this->parse('constant("PHP_VERSION")');

        self::assertFalse($this->validator->isSafe($expression));
    }

    public function testRejectsNonAccessorMethodCalls(): void
    {
        $expression = $this->parse('object.setTitle("bad")');

        self::assertFalse($this->validator->isSafe($expression));
    }

    public function testRejectsAccessorMethodCallsWithArguments(): void
    {
        $expression = $this->parse('object.getTitle("bad")');

        self::assertFalse($this->validator->isSafe($expression));
    }

    public function testAllowsNullCoalescedRootNames(): void
    {
        $expression = $this->parse('user ?? action');

        self::assertTrue($this->validator->isSafe($expression));
    }

    public function testAllowsConditionalAndArrayLiteralExpressions(): void
    {
        $expression = $this->parse('action == "create" ? ["ok", object.status] : ["nope"]');

        self::assertTrue($this->validator->isSafe($expression));
    }

    private function parse(string $expression): ParsedExpression
    {
        return $this->expressionLanguage->parse($expression, ['object', 'action', 'changeSet', 'user', 'subject']);
    }
}
