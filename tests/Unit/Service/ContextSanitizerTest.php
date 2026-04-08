<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\ContextSanitizer;
use Stringable;

use function fclose;
use function tmpfile;

final class ContextSanitizerTest extends TestCase
{
    public function testSanitizeArrayNormalizesNestedValues(): void
    {
        $sanitizer = new ContextSanitizer();
        $resource = tmpfile();
        self::assertIsResource($resource);

        try {
            $sanitized = $sanitizer->sanitizeArray([
                'enum_like' => new class implements Stringable {
                    public function __toString(): string
                    {
                        return 'stringified';
                    }
                },
                'resource' => $resource,
                'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'value']]]]]],
            ]);

            self::assertSame('stringified', $sanitized['enum_like']);
            self::assertSame('[resource:stream]', $sanitized['resource']);
            self::assertSame(['_max_depth_reached' => true], $sanitized['deep']['a']['b']['c']['d']);
        } finally {
            fclose($resource);
        }
    }

    public function testSanitizeStringReplacesInvalidUtf8(): void
    {
        $sanitizer = new ContextSanitizer();

        self::assertSame('[invalid utf-8]', $sanitizer->sanitizeString("\xB1\x31"));
    }
}
