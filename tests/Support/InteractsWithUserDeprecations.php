<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Support;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

trait InteractsWithUserDeprecations
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function expectSingleUserDeprecation(string $expectedMessage, callable $callback): mixed
    {
        $messages = [];

        set_error_handler(static function (int $severity, string $message) use (&$messages): bool {
            if ($severity !== E_USER_DEPRECATED) {
                return false;
            }

            $messages[] = $message;

            return true;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        self::assertSame([$expectedMessage], $messages);

        return $result;
    }
}
