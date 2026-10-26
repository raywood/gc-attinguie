<?php
declare(strict_types=1);

namespace App\Enum;

enum BusinessStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
