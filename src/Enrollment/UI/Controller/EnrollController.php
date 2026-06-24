<?php

declare(strict_types=1);

namespace App\Enrollment\UI\Controller;

use App\Enrollment\Application\EnrollStudent\EnrollStudentCommand;
use App\Enrollment\Domain\Exception\AlreadyEnrolledException;
use App\Enrollment\Domain\Exception\CourseNotEnrollableException;
use App\Identity\Domain\User;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class EnrollController extends AbstractController
{
    #[Route('/courses/{id}/enroll', name: 'enroll', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id, Request $request, CommandBusInterface $commandBus): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('enroll-'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Nieprawidłowy token CSRF.');

            return $this->redirectToRoute('catalog_detail', ['id' => $id]);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Oczekiwano zalogowanego użytkownika.');
        }

        try {
            $commandBus->dispatch(new EnrollStudentCommand($user->getId(), Uuid::fromString($id)));
            $this->addFlash('success', 'Zapisano na kurs.');
        } catch (AlreadyEnrolledException|CourseNotEnrollableException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('catalog_detail', ['id' => $id]);
    }
}
