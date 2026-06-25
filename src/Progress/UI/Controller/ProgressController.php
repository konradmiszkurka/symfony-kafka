<?php

declare(strict_types=1);

namespace App\Progress\UI\Controller;

use App\Identity\Domain\User;
use App\Progress\Application\MarkLessonCompleted\MarkLessonCompletedCommand;
use App\Progress\Domain\Exception\LessonNotInCourseException;
use App\Progress\Domain\Exception\ProgressNotFoundException;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class ProgressController extends AbstractController
{
    #[Route('/courses/{courseId}/lessons/{lessonId}/complete', name: 'progress_mark_lesson', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function markLesson(string $courseId, string $lessonId, Request $request, CommandBusInterface $commandBus): Response
    {
        if (!Uuid::isValid($courseId) || !Uuid::isValid($lessonId)) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('complete-'.$lessonId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('catalog_detail', ['id' => $courseId]);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Expected a logged-in user.');
        }

        try {
            $commandBus->dispatch(new MarkLessonCompletedCommand($user->getId(), Uuid::fromString($courseId), Uuid::fromString($lessonId)));
            $this->addFlash('success', 'Lesson marked as completed.');
        } catch (ProgressNotFoundException|LessonNotInCourseException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('catalog_detail', ['id' => $courseId]);
    }
}
