<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Outbox;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class OutboxPublishingTest extends KernelTestCase
{
    private function publishCourse(CommandBusInterface $bus): Uuid
    {
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lekcja', 'treść'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        return $courseId;
    }

    public function testEventGoesToOutboxNotDirectlyToKafka(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus);
        $userId = Uuid::v4();

        $bus->dispatch(new EnrollStudentCommand($userId, $courseId));

        // Event jest w outboxie...
        $unsent = self::getContainer()->get(OutboxRepository::class)->unsent(100);
        $types = array_map(static fn ($m) => $m->getMessageType(), $unsent);
        self::assertContains(UserEnrolled::class, $types);

        // ...ale NIE poszedł jeszcze wprost na Kafkę (transport in-memory pusty dla eventów).
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.kafka_events');
        self::assertCount(0, $transport->getSent());
    }

    public function testFailedCommandLeavesNoOutboxRow(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $outbox = self::getContainer()->get(OutboxRepository::class);
        $before = \count($outbox->unsent(1000));

        try {
            // kurs nieopublikowany -> handler rzuca przed zapisem
            $draft = Uuid::v4();
            $bus->dispatch(new CreateCourseCommand($draft, Uuid::v4(), 'Roboczy', 'Opis'));
            $bus->dispatch(new EnrollStudentCommand(Uuid::v4(), $draft));
            self::fail('Spodziewano się wyjątku.');
        } catch (CourseNotEnrollableException) {
            // oczekiwane
        }

        self::assertCount($before, $outbox->unsent(1000));
    }
}
