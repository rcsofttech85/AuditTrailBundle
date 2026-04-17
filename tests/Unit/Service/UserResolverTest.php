<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
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

final class UserResolverTest extends TestCase
{
    public function testGetUserIdFallsBackToCliWhenNoUser(): void
    {
        $resolver = $this->createResolver();
        self::assertStringStartsWith('cli:', (string) $resolver->getUserId());
    }

    public function testGetUserIdReturnsIdFromUserWithIdMethod(): void
    {
        $resolver = $this->createResolver(user: new StubUserWithId());
        self::assertSame('123', $resolver->getUserId());
    }

    public function testGetUserIdReturnsIdentifierWhenNoIdMethod(): void
    {
        $resolver = $this->createResolver(user: new StubUserWithoutId());
        self::assertSame('user', $resolver->getUserId());
    }

    #[DataProvider('publicIdUserProvider')]
    public function testGetUserIdReturnsPublicIdPropertyWhenNoIdMethod(UserInterface $user, string $expectedId): void
    {
        $resolver = $this->createResolver(user: $user);
        self::assertSame($expectedId, $resolver->getUserId());
    }

    public function testGetUserIdPrefersGetIdOverPublicIdProperty(): void
    {
        $user = new class implements UserInterface {
            public string $id = 'public-id';

            public function getId(): string
            {
                return 'method-id';
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

        $resolver = $this->createResolver(user: $user);
        self::assertSame('method-id', $resolver->getUserId());
    }

    #[DataProvider('invalidPublicIdUserProvider')]
    public function testGetUserIdFallsBackToIdentifierWhenPublicIdIsNotUsable(UserInterface $user): void
    {
        $resolver = $this->createResolver(user: $user);
        self::assertSame('user_identifier', $resolver->getUserId());
    }

    public function testGetUserIdFallsBackToIdentifierWhenIdPropertyIsNotPublic(): void
    {
        $user = new class implements UserInterface {
            private string $id = 'private-id';

            public function hasHiddenId(): bool
            {
                return $this->id !== '';
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
        self::assertSame('user_identifier', $resolver->getUserId());
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
        self::assertSame('test_user', $resolver->getUsername());
    }

    public function testGetIpAddressReturnsClientIpWhenTrackingEnabled(): void
    {
        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $resolver = $this->createResolver(
            request: $request,
            trackIp: true,
            trackUserAgent: true
        );

        self::assertSame('127.0.0.1', $resolver->getIpAddress());
    }

    public function testGetIpAddressReturnsNullWhenTrackingDisabled(): void
    {
        $resolver = $this->createResolver(trackIp: false, trackUserAgent: true);
        self::assertNull($resolver->getIpAddress());
    }

    public function testGetIpAddressReturnsNullInCliWithoutExplicitIpSource(): void
    {
        $resolver = $this->createResolver(trackIp: true, trackUserAgent: true);

        self::assertNull($resolver->getIpAddress());
    }

    public function testGetUserAgentReturnsHeaderWhenTrackingEnabled(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0']);
        $resolver = $this->createResolver(
            request: $request,
            trackIp: true,
            trackUserAgent: true
        );

        self::assertSame('Mozilla/5.0', $resolver->getUserAgent());
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
        self::assertSame(500, strlen($ua));
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
        self::assertSame('123', $resolver->getImpersonatorId());
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
        self::assertSame('superadmin', $resolver->getImpersonatorUsername());
    }

    public function testGetImpersonatorIdReturnsNullWithoutIdMethod(): void
    {
        $resolver = $this->createResolverWithImpersonation(new StubUserWithoutId());
        self::assertNull($resolver->getImpersonatorId());
    }

    #[DataProvider('publicIdUserProvider')]
    public function testGetImpersonatorIdReturnsPublicIdPropertyWhenNoIdMethod(UserInterface $user, string $expectedId): void
    {
        $resolver = $this->createResolverWithImpersonation($user);
        self::assertSame($expectedId, $resolver->getImpersonatorId());
    }

    public function testGetImpersonatorIdPrefersGetIdOverPublicIdProperty(): void
    {
        $user = new class implements UserInterface {
            public string $id = 'public-id';

            public function getId(): string
            {
                return 'method-id';
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
        self::assertSame('method-id', $resolver->getImpersonatorId());
    }

    #[DataProvider('invalidPublicIdUserProvider')]
    public function testGetImpersonatorIdFallsBackToNullWhenPublicIdIsNotUsable(UserInterface $user): void
    {
        $resolver = $this->createResolverWithImpersonation($user);
        self::assertNull($resolver->getImpersonatorId());
    }

    public function testGetImpersonatorIdFallsBackToNullWhenIdPropertyIsNotPublic(): void
    {
        $user = new class implements UserInterface {
            private string $id = 'private-id';

            public function hasHiddenId(): bool
            {
                return $this->id !== '';
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

        $resolver = $this->createResolverWithImpersonation($user);
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
        self::assertSame('user_identifier', $resolver->getUserId());
    }

    public function testGetIpAddressInCliWithoutRequestReturnsNullWithoutExplicitIpSource(): void
    {
        $resolver = $this->createResolver(trackIp: true, trackUserAgent: true);

        self::assertNull($resolver->getIpAddress());
    }

    public function testGetUserAgentInCliWithoutRequestUsesHostname(): void
    {
        $resolver = $this->createResolver(trackIp: true, trackUserAgent: true);

        $ua = $resolver->getUserAgent();
        self::assertIsString($ua);
        self::assertStringContainsString('cli-console', $ua);
    }

    /**
     * @return array<string, array{0: UserInterface, 1: string}>
     */
    public static function publicIdUserProvider(): array
    {
        return [
            'plain public property' => [
                new class implements UserInterface {
                    public string $id = 'public-id';

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
                },
                'public-id',
            ],
            'property hook' => [
                new class implements UserInterface {
                    public string $id {
                        get => 'hooked-id';
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
                },
                'hooked-id',
            ],
        ];
    }

    /**
     * @return array<string, array{0: UserInterface}>
     */
    public static function invalidPublicIdUserProvider(): array
    {
        return [
            'array public property' => [
                new class implements UserInterface {
                    /** @var array<string> */
                    public array $id = [];

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
                },
            ],
            'non-stringable hooked property' => [
                new class implements UserInterface {
                    public object $id {
                        get => new class {};
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
                },
            ],
        ];
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
