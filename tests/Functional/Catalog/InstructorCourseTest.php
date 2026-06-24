<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandBusInterface;
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
        $bus->dispatch(new CreateCourseCommand($courseId, $instructorA->getId(), 'Kurs A', 'Opis'));

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
        $client->submitForm('Utwórz', [
            'course_form[title]' => 'Mój Kurs',
            'course_form[description]' => 'Opis kursu',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        // manage page shows the course
        self::assertSelectorTextContains('body', 'Mój Kurs');

        // add section
        $client->submitForm('Dodaj sekcję', ['section_form[title]' => 'Sekcja 1']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Sekcja 1');

        // lesson form is present
        self::assertSelectorExists('form[name="lesson_form"]');

        // publish button exists
        self::assertSelectorExists('button#publish, form[action*="/publish"] button');
    }
}
