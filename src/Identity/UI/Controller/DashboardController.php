<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }
}
