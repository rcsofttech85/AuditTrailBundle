<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function fclose;
use function fopen;
use function sprintf;

final readonly class AuditLogExportResponseFactory
{
    private const array CONTENT_TYPES = [
        'json' => 'application/json',
        'csv' => 'text/csv',
    ];

    public function __construct(
        private AuditLogAdminOperations $operations,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function createResponse(array $filters, string $format): StreamedResponse
    {
        $fileName = sprintf('audit_logs_%s.%s', new DateTimeImmutable()->format('Y-m-d_His'), $format);

        $response = new StreamedResponse(function () use ($filters, $format): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new RuntimeException('Failed to open output stream for export');
            }

            try {
                $this->operations->exportToStream($filters, $format, $output);
            } finally {
                fclose($output);
            }
        });

        $response->headers->set('Content-Type', self::CONTENT_TYPES[$format] ?? 'application/octet-stream');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $fileName));

        return $response;
    }
}
