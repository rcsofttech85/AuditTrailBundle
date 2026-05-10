<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function in_array;

final class AuditAccessRequestEvaluator
{
    private ?int $readIntentRequestId = null;

    private ?bool $readIntentRequestAllowed = null;

    /**
     * @param array<string> $auditedMethods
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AuditAccessIntentResolver $intentResolver,
        private readonly array $auditedMethods = ['GET'],
    ) {
    }

    public function allowsAccessAudit(): bool
    {
        $request = $this->getCurrentAuditedRequest();
        if ($request === null) {
            return false;
        }

        $requestId = spl_object_id($request);
        if ($this->readIntentRequestId === $requestId && $this->readIntentRequestAllowed !== null) {
            return $this->readIntentRequestAllowed;
        }

        if (!$this->isAuditedRequest($request)) {
            return $this->rememberReadIntentDecision($requestId, false);
        }

        return $this->rememberReadIntentDecision(
            $requestId,
            $this->intentResolver->isExplicitReadIntentRequest($request, $this->auditedMethods),
        );
    }

    public function reset(): void
    {
        $this->readIntentRequestId = null;
        $this->readIntentRequestAllowed = null;
    }

    private function isAuditedRequest(Request $request): bool
    {
        $method = $request->getMethod();

        return in_array($method, ['GET', 'HEAD'], true)
            && in_array($method, $this->auditedMethods, true);
    }

    private function rememberReadIntentDecision(int $requestId, bool $allowed): bool
    {
        $this->readIntentRequestId = $requestId;
        $this->readIntentRequestAllowed = $allowed;

        return $allowed;
    }

    private function getCurrentAuditedRequest(): ?Request
    {
        $request = $this->requestStack->getCurrentRequest();
        $mainRequest = $this->requestStack->getMainRequest();

        if ($request === null || $mainRequest === null || $request !== $mainRequest) {
            return null;
        }

        return $request;
    }
}
