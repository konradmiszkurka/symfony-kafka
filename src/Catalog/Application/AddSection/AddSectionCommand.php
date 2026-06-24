<?php

declare(strict_types=1);

namespace App\Catalog\Application\AddSection;

use Symfony\Component\Uid\Uuid;

final readonly class AddSectionCommand
{
    public function __construct(
        public Uuid $courseId,
        public Uuid $sectionId,
        public Uuid $instructorId,
        public string $title,
    ) {
    }
}
