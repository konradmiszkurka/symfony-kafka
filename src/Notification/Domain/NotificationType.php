<?php

declare(strict_types=1);

namespace App\Notification\Domain;

enum NotificationType: string
{
    case Welcome = 'welcome';
    case CourseCompleted = 'course_completed';
}
