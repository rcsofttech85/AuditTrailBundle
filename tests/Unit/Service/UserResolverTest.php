<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\UserResolver;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubUserWithId;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubUserWithoutId;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function strlen;

class UserResolverTest extends TestCase
{
    public function testGetUserIdFallsBackToCliWhenNoUser(): void
    {
        $resolver = $this->createResolver();
        self::assertStringStartsWith('cli:', (string) $resolver->getUserId());
    }

    public function testGetUserIdReturnsIdFromUserWithIdMethod(): void
    {
        $resolver = $this->createResolver(user: new StubUserWithId());
        self::assertEquals(123, $resolver->getUserId());
    }

    public function testGetUserIdReturnsIdentifierWhenNoIdMethod(): void
    {
        $resolver = $this->createResolver(user: new StubUserWithoutId());
        self::assertEquals('user', $resolver->getUserId());
    }

    public function testGetUsernameFallsBackToCliWhenNoUser(): void
    {
        $resolver = $this->createResolver();
        self::assertStringStartsWith('cli:', (string) $resolver->getUsername());
    }

    public function testGetUsernameReturnsUserIdentifier(): void
    {
        $user = self::createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('test_user');

        $resolver = $this->createResolver(user: $user);
        self::assertEquals('test_user', $resolver->getUsername());
    }

    public function testGetIpAddressReturnsClientIpWhenTrackingEnabled(): void
    {
        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $resolver = $this->createResolver(
            request: $request,
            trackIp: true,
            trackUserAgent: true
        );

        self::assertEquals('127.0.0.1', $resolver->getIpAddress());
    }

    public function testGetIpAddressReturnsNullWhenTrackingDisabled(): void
    {
        $resolver = $this->createResolver(trackIp: false, trackUserAgent: true);
        self::assertNull($resolver->getIpAddress());
    }

    public function testGetIpAddressFallsBackToHostnameInCli(): void
    {
        $resolver = $this->createResolver(trackIp: true, trackUserAgent: true);

        self::assertEquals(gethostbyname((string) gethostname()), $resolver->getIpAddress());
    }

    public function testGetUserAgentReturnsHeaderWhenTrackingEnabled(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0']);
        $resolver = $this->createResolver(
            request: $request,
            trackIp: true,
            trackUserAgent: true
        );

        self::assertEquals('Mozilla/5.0', $resolver->getUserAgent());
    }

    public function testGetUserAgentReturnsNullWhenTrackingDisabled(): void
    {
        $resolver = $this->createResolver(trackIp: true, trackUserAgent: false);
        self::assertNull($resolver->getUserAgent());
    }

    public function testGetUserAgentTruncatesLongStrings(): void
    {
        $longUa = str_repeat('a', 600);
        $request = new Request([], [], [], [], [], ['HTTP_USER_AGENT' => $longUa]);
        $resolver = $this->createResolver(
            request: $request,
            trackIp: true,
            trackUserAgent: true
        );

        $ua = $resolver->getUserAgent();
        self::assertNotNull($ua);
        self::assertEquals(500, strlen($ua));
    }

    public function testGetUserAgentFallsBackToCliInCliEnvironment(): void
    {
        $request = new Request([], [], [], [], [], []);
        $resolver = $this->createResolver(
            request: $request,
            trackIp: true,
            trackUserAgent: true
        );

        self::assertStringContainsString('cli-console', (string) $resolver->getUserAgent());
    }

    public function testGetImpersonatorIdReturnsNullWhenNoToken(): void
    {
        $resolver = $this->createResolver();
        self::assertNull($resolver->getImpersonatorId());
    }

    public function testGetImpersonatorIdReturnsSwitchUserOriginalId(): void
    {
        $resolver = $this->createResolverWithImpersonation(new StubUserWithId());
        self::assertEquals('123', $resolver->getImpersonatorId());
    }

    public function testGetImpersonatorUsernameReturnsNullWhenNoToken(): void
    {
        $resolver = $this->createResolver();
        self::assertNull($resolver->getImpersonatorUsername());
    }

    public function testGetImpersonatorUsernameReturnsSwitchUserOriginalIdentifier(): void
    {
        $user = self::createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('superadmin');

        $resolver = $this->createResolverWithImpersonation($user);
        self::assertEquals('superadmin', $resolver->getImpersonatorUsername());
    }

    public function testGetImpersonatorIdReturnsNullWithoutIdMethod(): void
    {
        $resolver = $this->createResolverWithImpersonation(new StubUserWithoutId());
        self::assertNull($resolver->getImpersonatorId());
    }

    public function testGetImpersonatorIdReturnsNullWhenIdNotScalarOrStringable(): void
    {
        $user = new class implements UserInterface {
            /** @return array<mixed> */
            public function getId(): array
            {
                return [];
            }

            public function getUserIdentifier(): string
            {
                return 'user';
            }

            /** @return array<string> */
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }
        };

        $resolver = $this->createResolverWithImpersonation($user);
        self::assertNull($resolver->getImpersonatorId());
    }

    public function testGetUserIdReturnsIdentifierWhenIdNotScalarOrStringable(): void
    {
        $user = new class implements UserInterface {
            /** @return array<mixed> */
            public function getId(): array
            {
                return [];
            }

            public function getUserIdentifier(): string
            {
                return 'user_identifier';
            }

            /** @return array<string> */
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }
        };

        $resolver = $this->createResolver(user: $user);
        self::assertEquals('user_identifier', $resolver->getUserId());
    }

    public function testGetIpAddressInCliWithoutRequestUsesHostname(): void
    {
        $resolver = $this->createResolver(trackIp: true, trackUserAgent: true);

        $ip = $resolver->getIpAddress();
        self::assertIsString($ip);
    }

    public function testGetUserAgentInCliWithoutRequestUsesHostname(): void
    {
        $resolver = $this->createResolver(trackIp: true, trackUserAgent: true);

        $ua = $resolver->getUserAgent();
        self::assertIsString($ua);
        self::assertStringContainsString('cli-console', $ua);
    }

    private function createResolver(
        ?UserInterface $user = null,
        ?Request $request = null,
        bool $trackIp = false,
        bool $trackUserAgent = false,
    ): UserResolver {
        $security = self::createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        if ($request !== null) {
            $requestStack->push($request);
        }

        return new UserResolver($security, $requestStack, $trackIp, $trackUserAgent);
    }

    private function createResolverWithImpersonation(UserInterface $originalUser): UserResolver
    {
        $originalToken = self::createStub(TokenInterface::class);
        $originalToken->method('getUser')->willReturn($originalUser);

        /** @var SwitchUserToken&Stub $token */
        $token = self::createStub(SwitchUserToken::class);
        $token->method('getOriginalToken')->willReturn($originalToken);

        $security = self::createStub(Security::class);
        $security->method('getToken')->willReturn($token);

        return new UserResolver($security, new RequestStack());
    }
}
