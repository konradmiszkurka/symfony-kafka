<?php

declare(strict_types=1);

namespace App\Catalog\UI\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class CourseFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    #[Assert\NotBlank]
    public ?string $description = null;
}
