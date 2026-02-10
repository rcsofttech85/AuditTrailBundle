<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\UserResolver;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubUserWithId;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubUserWithoutId;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

use function strlen;

#[AllowMockObjectsWithoutExpectations]
class UserResolverTest extends TestCase
{
    private Security&MockObject $security;

    private RequestStack&MockObject $requestStack;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->requestStack = $this->createMock(RequestStack::class);
    }

    public function testGetUserId(): void
    {
        $resolver = new UserResolver($this->security, $this->requestStack);

        // No User
        $this->security->method('getUser')->willReturn(null);
        self::assertNull($resolver->getUserId());

        // User with ID
        $this->security = $this->createMock(Security::class);
        $this->security->method('getUser')->willReturn(new StubUserWithId());
        $resolver = new UserResolver($this->security, $this->requestStack);
        self::assertEquals(123, $resolver->getUserId());

        // User without ID
        $this->security = $this->createMock(Security::class);
        $this->security->method('getUser')->willReturn(new StubUserWithoutId());
        $resolver = new UserResolver($this->security, $this->requestStack);
        self::assertNull($resolver->getUserId());
    }

    public function testGetUsername(): void
    {
        $resolver = new UserResolver($this->security, $this->requestStack);

        $this->security->method('getUser')->willReturn(null);
        self::assertNull($resolver->getUsername());

        $this->security = $this->createMock(Security::class);
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('test_user');
        $this->security->method('getUser')->willReturn($user);

        $resolver = new UserResolver($this->security, $this->requestStack);
        self::assertEquals('test_user', $resolver->getUsername());
    }

    public function testGetIpAddress(): void
    {
        // Tracking enabled
        $resolver = new UserResolver($this->security, $this->requestStack, true, true);

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        self::assertEquals('127.0.0.1', $resolver->getIpAddress());

        // Tracking disabled
        $resolver = new UserResolver($this->security, $this->requestStack, false, true);
        self::assertNull($resolver->getIpAddress());

        // No request
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->requestStack->method('getCurrentRequest')->willReturn(null);
        $resolver = new UserResolver($this->security, $this->requestStack, true, true);
        self::assertNull($resolver->getIpAddress());
    }

    public function testGetUserAgent(): void
    {
        // Tracking enabled
        $resolver = new UserResolver($this->security, $this->requestStack, true, true);

        $request = new Request([], [], [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0']);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        self::assertEquals('Mozilla/5.0', $resolver->getUserAgent());

        // Tracking disabled
        $resolver = new UserResolver($this->security, $this->requestStack, true, false);
        self::assertNull($resolver->getUserAgent());

        // Truncation
        $longUa = str_repeat('a', 600);
        $request = new Request([], [], [], [], [], ['HTTP_USER_AGENT' => $longUa]);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $resolver = new UserResolver($this->security, $this->requestStack, true, true);

        $ua = $resolver->getUserAgent();
        self::assertNotNull($ua);
        self::assertEquals(500, strlen($ua));

        // Empty UA
        $request = new Request([], [], [], [], [], []);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $resolver = new UserResolver($this->security, $this->requestStack, true, true);
        self::assertNull($resolver->getUserAgent());
    }
}
