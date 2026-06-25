<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Outbox;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class OutboxRelayIntegrationTest extends KernelTestCase
{
    public function testRelayMovesOutboxEventToKafkaTransportAndMarksSent(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);

        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lekcja', 'treść'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));
        $bus->dispatch(new EnrollStudentCommand(Uuid::v4(), $courseId));

        // przed relay: w outboxie, brak na transporcie
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.kafka_events');
        self::assertCount(0, $transport->getSent());

        $outbox = self::getContainer()->get(OutboxRepository::class);
        $pending = \count($outbox->unsent(1000));
        self::assertGreaterThanOrEqual(1, $pending, 'Outbox powinien mieć niewysłane wiersze przed relay.');

        $relayed = self::getContainer()->get(OutboxRelay::class)->relayBatch(1000);

        // wszystkie niewysłane wiersze zostały zrelayowane
        self::assertSame($pending, $relayed);
        // po relay: event na transporcie Kafka (in-memory)
        $messages = array_map(static fn ($e) => $e->getMessage()::class, $transport->getSent());
        self::assertContains(UserEnrolled::class, $messages);
        // outbox w pełni opróżniony z niewysłanych
        self::assertCount(0, $outbox->unsent(1000));
    }
}
