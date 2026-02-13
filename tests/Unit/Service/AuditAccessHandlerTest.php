<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessHandler;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
class AuditAccessHandlerTest extends TestCase
{
    public function testHandleAccessSkipsWhenVoterVetoes(): void
    {
        $entity = new stdClass();

        $auditService = $this->createMock(AuditService::class);
        $auditService->method('getAccessAttribute')
            ->with(stdClass::class)
            ->willReturn(new AuditAccess());

        // Voter vetoes the access audit
        $auditService->expects($this->once())
            ->method('passesVoters')
            ->with($entity, AuditLogInterface::ACTION_ACCESS)
            ->willReturn(false);

        // createAuditLog should NEVER be called
        $auditService->expects($this->never())->method('createAuditLog');

        $dispatcher = $this->createMock(AuditDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));

        $handler = new AuditAccessHandler(
            $auditService,
            $dispatcher,
            $this->createMock(UserResolverInterface::class),
            $requestStack,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $handler->handleAccess($entity, $em);
    }

    public function testHandleAccessProceedsWhenVoterApproves(): void
    {
        $entity = new stdClass();

        $auditService = $this->createMock(AuditService::class);
        $auditService->method('getAccessAttribute')
            ->with(stdClass::class)
            ->willReturn(new AuditAccess());

        $auditService->method('passesVoters')->willReturn(true);

        $audit = new AuditLog();
        $auditService->expects($this->once())
            ->method('createAuditLog')
            ->willReturn($audit);

        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $em->method('getClassMetadata')->willReturn($metadata);

        $dispatcher = $this->createMock(AuditDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch');

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));

        $handler = new AuditAccessHandler(
            $auditService,
            $dispatcher,
            $this->createMock(UserResolverInterface::class),
            $requestStack,
        );

        $handler->handleAccess($entity, $em);
    }

    public function testHandleAccessSkipsOnNonGetRequest(): void
    {
        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->never())->method('getAccessAttribute');
        $auditService->expects($this->never())->method('passesVoters');

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'POST'));

        $handler = new AuditAccessHandler(
            $auditService,
            $this->createMock(AuditDispatcher::class),
            $this->createMock(UserResolverInterface::class),
            $requestStack,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $handler->handleAccess(new stdClass(), $em);
    }
}
