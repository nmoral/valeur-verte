<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

enum Source: string
{
    case Audit = 'audit';
    case Widget = 'widget';
}
