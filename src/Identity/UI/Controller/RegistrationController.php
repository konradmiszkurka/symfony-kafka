<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Exception\EmailAlreadyInUseException;
use App\Identity\UI\Form\RegistrationForm;
use App\Identity\UI\Form\RegistrationFormData;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, CommandBusInterface $commandBus): Response
    {
        $data = new RegistrationFormData();
        $form = $this->createForm(RegistrationForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $commandBus->dispatch(new RegisterUserCommand($data->email, $data->plainPassword, $data->role));

                return $this->redirect('/login');
            } catch (EmailAlreadyInUseException $e) {
                $form->get('email')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }
}
