<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class CourseBrowsingTest extends WebTestCase
{
    private function publishCourse(CommandBusInterface $bus, string $title): Uuid
    {
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, $title, 'Course description'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Section'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lesson A', 'content'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        return $courseId;
    }

    public function testCatalogListsPublishedCoursesOnly(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $this->publishCourse($bus, 'Published Course');
        // draft course (not published)
        $bus->dispatch(new CreateCourseCommand(Uuid::v4(), Uuid::v4(), 'Draft Course', 'Description'));

        $client->request('GET', '/courses');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Published Course');
        self::assertStringNotContainsString('Draft Course', $client->getResponse()->getContent());
    }

    public function testCourseDetailShowsSectionsAndLessons(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus, 'Detailed Course');

        $client->request('GET', '/courses/'.$courseId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Detailed Course');
        self::assertSelectorTextContains('body', 'Section');
        self::assertSelectorTextContains('body', 'Lesson A');
    }

    public function testUnpublishedOrMissingCourseReturns404(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $draftId = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($draftId, Uuid::v4(), 'Draft', 'Description'));

        $client->request('GET', '/courses/'.$draftId);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/courses/'.Uuid::v4());
        self::assertResponseStatusCodeSame(404);
    }
}
