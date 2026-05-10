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

    /** @var list<class-string<Node>> */
    private const array SUPPORTED_NODE_CLASSES = [
        ArrayNode::class,
        BinaryNode::class,
        ConditionalNode::class,
        ConstantNode::class,
        GetAttrNode::class,
        NameNode::class,
        NullCoalesceNode::class,
        NullCoalescedNameNode::class,
        UnaryNode::class,
    ];

    /**
     * Accessor-style methods keep expressions read-oriented in a major release
     * without pretending to sandbox arbitrary code execution.
     */
    private const string ACCESSOR_METHOD_PATTERN = '/^(get|is|has)[A-Z_]/';

    public function isSafe(ParsedExpression $expression): bool
    {
        return $this->isSafeNode($expression->getNodes());
    }

    private function isSafeNode(Node $node): bool
    {
        return !$node instanceof FunctionNode
            && $this->hasAllowedRootName($node)
            && (!$node instanceof GetAttrNode || $this->isSafeGetAttrNode($node))
            && $this->isSupportedNode($node)
            && $this->areSafeChildren($node);
    }

    /**
     * @param array<mixed> $children
     */
    private function areSafeNodeArrayChildren(array $children): bool
    {
        return array_is_list($children) && array_all(
            $children,
            fn (mixed $child): bool => $child instanceof Node && $this->isSafeNode($child),
        );
    }

    private function isSupportedNode(Node $node): bool
    {
        foreach (self::SUPPORTED_NODE_CLASSES as $supportedClass) {
            if (is_a($node, $supportedClass)) {
                return true;
            }
        }

        return false;
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

        return $this->hasSafeAccessorSignature($node);
    }

    private function hasAllowedRootName(Node $node): bool
    {
        if (!$node instanceof NameNode && !$node instanceof NullCoalescedNameNode) {
            return true;
        }

        $name = $node->attributes['name'] ?? null;

        return is_string($name) && in_array($name, self::ALLOWED_ROOT_NAMES, true);
    }

    private function areSafeChildren(Node $node): bool
    {
        foreach ($node->nodes as $child) {
            if ($child instanceof Node && !$this->isSafeNode($child)) {
                return false;
            }

            if (is_array($child) && !$this->areSafeNodeArrayChildren($child)) {
                return false;
            }
        }

        return true;
    }

    private function hasSafeAccessorSignature(GetAttrNode $node): bool
    {
        $attributeNode = $this->resolveAttributeNode($node);
        if ($attributeNode === null) {
            return false;
        }

        $argumentsNode = $this->resolveArgumentsNode($node);
        if ($argumentsNode === null) {
            return false;
        }

        if (!$this->isAccessorMethodName($attributeNode->attributes['value'] ?? null)) {
            return false;
        }

        return $this->hasNoArguments($argumentsNode);
    }

    private function resolveAttributeNode(GetAttrNode $node): ?ConstantNode
    {
        $attributeNode = $node->nodes['attribute'] ?? null;

        return $attributeNode instanceof ConstantNode ? $attributeNode : null;
    }

    private function resolveArgumentsNode(GetAttrNode $node): ?ArgumentsNode
    {
        $argumentsNode = $node->nodes['arguments'] ?? null;

        return $argumentsNode instanceof ArgumentsNode ? $argumentsNode : null;
    }

    private function hasNoArguments(ArgumentsNode $argumentsNode): bool
    {
        return count($argumentsNode->nodes) === 0;
    }

    private function isAccessorMethodName(mixed $method): bool
    {
        return is_string($method) && preg_match(self::ACCESSOR_METHOD_PATTERN, $method) === 1;
    }
}
