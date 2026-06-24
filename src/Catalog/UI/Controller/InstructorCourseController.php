<?php

declare(strict_types=1);

namespace App\Catalog\UI\Controller;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\FindInstructorCourses\FindInstructorCoursesQuery;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\Exception\CannotPublishCourseWithoutLessonsException;
use App\Catalog\UI\Form\CourseForm;
use App\Catalog\UI\Form\CourseFormData;
use App\Catalog\UI\Form\LessonForm;
use App\Catalog\UI\Form\LessonFormData;
use App\Catalog\UI\Form\SectionForm;
use App\Catalog\UI\Form\SectionFormData;
use App\Identity\Domain\User;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/instructor/courses')]
final class InstructorCourseController extends AbstractController
{
    #[Route('', name: 'instructor_courses', methods: ['GET'])]
    public function list(QueryBusInterface $queryBus): Response
    {
        return $this->render('instructor/courses.html.twig', [
            'courses' => $queryBus->ask(new FindInstructorCoursesQuery($this->instructorId())),
        ]);
    }

    #[Route('/new', name: 'instructor_course_new', methods: ['GET', 'POST'])]
    public function new(Request $request, CommandBusInterface $commandBus): Response
    {
        $data = new CourseFormData();
        $form = $this->createForm(CourseForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $courseId = Uuid::v4();
            $commandBus->dispatch(new CreateCourseCommand($courseId, $this->instructorId(), $data->title, $data->description));

            return $this->redirectToRoute('instructor_course_manage', ['id' => (string) $courseId]);
        }

        return $this->render('instructor/new_course.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'instructor_course_manage', methods: ['GET'])]
    public function manage(string $id, CourseRepository $courses): Response
    {
        $course = $this->ownedCourse($id, $courses);

        return $this->render('instructor/manage_course.html.twig', [
            'course' => $course,
            'sectionForm' => $this->createForm(SectionForm::class, new SectionFormData())->createView(),
            'lessonForm' => $this->createForm(LessonForm::class, new LessonFormData())->createView(),
        ]);
    }

    #[Route('/{id}/sections', name: 'instructor_course_add_section', methods: ['POST'])]
    public function addSection(string $id, Request $request, CommandBusInterface $commandBus, CourseRepository $courses): Response
    {
        $this->ownedCourse($id, $courses);

        $data = new SectionFormData();
        $form = $this->createForm(SectionForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $commandBus->dispatch(new AddSectionCommand(
                $this->uuid($id), Uuid::v4(), $this->instructorId(), $data->title
            ));
        }

        return $this->redirectToRoute('instructor_course_manage', ['id' => $id]);
    }

    #[Route('/{id}/lessons', name: 'instructor_course_add_lesson', methods: ['POST'])]
    public function addLesson(string $id, Request $request, CommandBusInterface $commandBus, CourseRepository $courses): Response
    {
        $this->ownedCourse($id, $courses);

        $data = new LessonFormData();
        $form = $this->createForm(LessonForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $commandBus->dispatch(new AddLessonCommand(
                $this->uuid($id), $this->uuid($data->sectionId), Uuid::v4(), $this->instructorId(), $data->title, $data->content
            ));
        }

        return $this->redirectToRoute('instructor_course_manage', ['id' => $id]);
    }

    #[Route('/{id}/publish', name: 'instructor_course_publish', methods: ['POST'])]
    public function publish(string $id, CommandBusInterface $commandBus, CourseRepository $courses): Response
    {
        $this->ownedCourse($id, $courses);

        try {
            $commandBus->dispatch(new PublishCourseCommand($this->uuid($id), $this->instructorId()));
            $this->addFlash('success', 'Kurs opublikowany.');
        } catch (CannotPublishCourseWithoutLessonsException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('instructor_course_manage', ['id' => $id]);
    }

    private function instructorId(): Uuid
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Oczekiwano zalogowanego użytkownika typu User.');
        }

        return $user->getId();
    }

    private function uuid(string $id): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        return Uuid::fromString($id);
    }

    private function ownedCourse(string $id, CourseRepository $courses): Course
    {
        $course = $courses->ofId($this->uuid($id));
        if (null === $course || !$course->belongsTo($this->instructorId())) {
            throw $this->createNotFoundException();
        }

        return $course;
    }
}
