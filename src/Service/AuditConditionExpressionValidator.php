<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Symfony\Component\ExpressionLanguage\Node\ArgumentsNode;
use Symfony\Component\ExpressionLanguage\Node\ArrayNode;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConditionalNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\NullCoalescedNameNode;
use Symfony\Component\ExpressionLanguage\Node\NullCoalesceNode;
use Symfony\Component\ExpressionLanguage\Node\UnaryNode;
use Symfony\Component\ExpressionLanguage\ParsedExpression;

use function array_all;
use function array_is_list;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;

final class AuditConditionExpressionValidator
{
    private const array ALLOWED_ROOT_NAMES = ['object', 'action', 'changeSet', 'user'];

    /**
     * Accessor-style methods keep expressions read-oriented in a major release
     * without pretending to sandbox arbitrary code execution.
     */
    private const string ACCESSOR_METHOD_PATTERN = '/^(get|is|has)[A-Z_]/';

    public function isSafe(ParsedExpression $expression): bool
    {
        return $this->isSafeNode($expression->getNodes(), null);
    }

    private function isSafeNode(Node $node, ?Node $parent): bool
    {
        if ($node instanceof FunctionNode) {
            return false;
        }

        if ($node instanceof NameNode) {
            $name = $node->attributes['name'] ?? null;

            return is_string($name) && in_array($name, self::ALLOWED_ROOT_NAMES, true);
        }

        if ($node instanceof NullCoalescedNameNode) {
            $name = $node->attributes['name'] ?? null;

            return is_string($name) && in_array($name, self::ALLOWED_ROOT_NAMES, true);
        }

        if ($node instanceof GetAttrNode && !$this->isSafeGetAttrNode($node)) {
            return false;
        }

        if (!$this->isSupportedNode($node)) {
            return false;
        }

        foreach ($node->nodes as $child) {
            if ($child instanceof Node && !$this->isSafeNode($child, $node)) {
                return false;
            }

            if (is_array($child) && !$this->areSafeNodeArrayChildren($child, $node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $children
     */
    private function areSafeNodeArrayChildren(array $children, Node $parent): bool
    {
        return array_is_list($children) && array_all(
            $children,
            fn (mixed $child): bool => $child instanceof Node && $this->isSafeNode($child, $parent),
        );
    }

    private function isSupportedNode(Node $node): bool
    {
        return $node instanceof ArrayNode
            || $node instanceof BinaryNode
            || $node instanceof ConditionalNode
            || $node instanceof ConstantNode
            || $node instanceof GetAttrNode
            || $node instanceof NameNode
            || $node instanceof NullCoalesceNode
            || $node instanceof NullCoalescedNameNode
            || $node instanceof UnaryNode;
    }

    private function isSafeGetAttrNode(GetAttrNode $node): bool
    {
        $type = $node->attributes['type'] ?? null;

        if ($type === GetAttrNode::PROPERTY_CALL || $type === GetAttrNode::ARRAY_CALL) {
            return true;
        }

        if ($type !== GetAttrNode::METHOD_CALL) {
            return false;
        }

        $attributeNode = $node->nodes['attribute'] ?? null;
        $argumentsNode = $node->nodes['arguments'] ?? null;
        if (!$attributeNode instanceof ConstantNode || !$argumentsNode instanceof ArgumentsNode) {
            return false;
        }

        $method = $attributeNode->attributes['value'] ?? null;
        if (!is_string($method) || preg_match(self::ACCESSOR_METHOD_PATTERN, $method) !== 1) {
            return false;
        }

        return count($argumentsNode->nodes) === 0;
    }
}
