<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
