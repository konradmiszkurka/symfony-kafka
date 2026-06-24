<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use App\Identity\Domain\Role;
use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationFormData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, minMessage: 'Hasło musi mieć min. {{ limit }} znaków.')]
    public ?string $plainPassword = null;

    #[Assert\NotNull]
    public ?Role $role = null;
}
