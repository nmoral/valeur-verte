<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Port : signale un usage anormal à l'admin interne (D2). Le canal réel
 * (journal d'audit, socle EasyAdmin) est porté par #50.
 */
interface NotificationAdmin
{
    public function usageAnormal(string $sujet, string $contexte): void;
}
