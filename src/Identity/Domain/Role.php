<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum Role: string
{
    case Student = 'ROLE_STUDENT';
    case Instructor = 'ROLE_INSTRUCTOR';
}
