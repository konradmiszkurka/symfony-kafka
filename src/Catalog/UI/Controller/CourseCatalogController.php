<?php

declare(strict_types=1);

namespace App\Catalog\UI\Controller;

use App\Catalog\Application\FindPublishedCourses\FindPublishedCoursesQuery;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CourseCatalogController extends AbstractController
{
    #[Route('/courses', name: 'catalog_list', methods: ['GET'])]
    public function __invoke(QueryBusInterface $queryBus): Response
    {
        return $this->render('catalog/list.html.twig', [
            'courses' => $queryBus->ask(new FindPublishedCoursesQuery()),
        ]);
    }
}
