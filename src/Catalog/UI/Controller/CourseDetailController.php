<?php

declare(strict_types=1);

namespace App\Catalog\UI\Controller;

use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Domain\Course;
use App\Identity\Domain\User;
use App\Progress\Application\FindCourseProgress\FindCourseProgressQuery;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class CourseDetailController extends AbstractController
{
    #[Route('/courses/{id}', name: 'catalog_detail', methods: ['GET'])]
    public function __invoke(string $id, QueryBusInterface $queryBus): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $course = $queryBus->ask(new FindPublishedCourseQuery(Uuid::fromString($id)));
        if (!$course instanceof Course) {
            throw $this->createNotFoundException();
        }

        $progress = null;
        $user = $this->getUser();
        if ($user instanceof User) {
            $progress = $queryBus->ask(new FindCourseProgressQuery(
                $user->getId(),
                Uuid::fromString($id),
            ));
        }

        return $this->render('catalog/detail.html.twig', ['course' => $course, 'progress' => $progress]);
    }
}
