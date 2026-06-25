<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Domain\Course;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class InstructorCourseTest extends WebTestCase
{
    private function loginAs(object $client, string $email, Role $role): void
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $bus->dispatch(new RegisterUserCommand($email, 'secret123', $role));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail($email);
        $client->loginUser($user);
    }

    public function testStudentCannotAccessInstructorArea(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'student@example.com', Role::Student);

        $client->request('GET', '/instructor/courses');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonOwnerGets404OnInstructorManagedCourse(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $userRepo = self::getContainer()->get(UserRepository::class);

        // Register instructor A and create a course
        $bus->dispatch(new RegisterUserCommand('a@example.com', 'secret123', Role::Instructor));
        $instructorA = $userRepo->ofEmail('a@example.com');
        $courseId = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructorA->getId(), 'Course A', 'Description'));

        // Log in as instructor B
        $bus->dispatch(new RegisterUserCommand('b@example.com', 'secret123', Role::Instructor));
        $instructorB = $userRepo->ofEmail('b@example.com');
        $client->loginUser($instructorB);

        // B tries to access A's manage page — ownership check must return 404
        $client->request('GET', '/instructor/courses/' . $courseId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInstructorCanCreateAddAndPublishCourse(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'instruktor@example.com', Role::Instructor);

        // create course
        $client->request('GET', '/instructor/courses/new');
        self::assertResponseIsSuccessful();
        $client->submitForm('Create', [
            'course_form[title]' => 'My Course',
            'course_form[description]' => 'Course description',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        // manage page shows the course
        self::assertSelectorTextContains('body', 'My Course');

        // add section
        $client->submitForm('Add section', ['section_form[title]' => 'Section 1']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Section 1');

        // lesson form is present
        self::assertSelectorExists('form[name="lesson_form"]');

        // publish button exists
        self::assertSelectorExists('button#publish, form[action*="/publish"] button');
    }

    public function testInstructorPublishesCourseViaCsrfProtectedForm(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $bus->dispatch(new RegisterUserCommand('owner@example.com', 'secret123', Role::Instructor));
        $instructor = self::getContainer()->get(UserRepository::class)->ofEmail('owner@example.com');
        $client->loginUser($instructor);

        // Seed a publishable course (with a lesson) owned by the logged-in instructor
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor->getId(), 'Course to publish', 'Description'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor->getId(), 'Section'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor->getId(), 'Lesson', 'content'));

        // Submit the real (CSRF-protected) publish form from the manage page
        $client->request('GET', '/instructor/courses/' . $courseId);
        $client->submitForm('Publish course');
        self::assertResponseRedirects('/instructor/courses/' . $courseId);

        // The course is now published (visible via the published-course query)
        $course = self::getContainer()->get(QueryBusInterface::class)
            ->ask(new FindPublishedCourseQuery($courseId));
        self::assertInstanceOf(Course::class, $course);
    }
}
