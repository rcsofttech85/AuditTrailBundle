<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Event;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Event\AuditMessageStampEvent;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

class AuditMessageStampEventTest extends TestCase
{
    public function testEventExposesMessage(): void
    {
        $message = $this->createMessage();
        $event = new AuditMessageStampEvent($message);

        self::assertSame($message, $event->message);
    }

    public function testAddStamp(): void
    {
        $event = new AuditMessageStampEvent($this->createMessage());

        $delayStamp = new DelayStamp(5000);
        $event->addStamp($delayStamp);

        $stamps = $event->getStamps();
        self::assertCount(1, $stamps);
        self::assertSame($delayStamp, $stamps[0]);
    }

    public function testAddMultipleStamps(): void
    {
        $event = new AuditMessageStampEvent($this->createMessage());

        $delayStamp = new DelayStamp(5000);
        $transportStamp = new TransportNamesStamp(['async']);

        $event->addStamp($delayStamp);
        $event->addStamp($transportStamp);

        $stamps = $event->getStamps();
        self::assertCount(2, $stamps);
        self::assertSame($delayStamp, $stamps[0]);
        self::assertSame($transportStamp, $stamps[1]);
    }

    public function testInitialStampsViaConstructor(): void
    {
        $delayStamp = new DelayStamp(1000);
        $event = new AuditMessageStampEvent($this->createMessage(), [$delayStamp]);

        $stamps = $event->getStamps();
        self::assertCount(1, $stamps);
        self::assertSame($delayStamp, $stamps[0]);
    }

    private function createMessage(): AuditLogMessage
    {
        return new AuditLogMessage(
            'App\\Entity\\TestEntity',
            '123',
            'create',
            [],
            ['name' => 'Test'],
            ['name'],
            '1',
            'admin',
            '127.0.0.1',
            'PHPUnit',
            null,
            new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            []
        );
    }
}
