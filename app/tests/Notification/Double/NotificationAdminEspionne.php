<?php

declare(strict_types=1);

namespace App\Tests\Notification\Double;

use App\Notification\NotificationAdmin;

/**
 * Double de la notification d'usage anormal (#44) : retient les alertes en mémoire
 * au lieu de les inscrire au journal d'audit, pour que les tests constatent qui a
 * été alerté et combien de fois.
 */
final class NotificationAdminEspionne implements NotificationAdmin
{
    /**
     * @var list<array{sujet: string, contexte: string}>
     */
    public array $alertes = [];

    public function usageAnormal(string $sujet, string $contexte): void
    {
        $this->alertes[] = ['sujet' => $sujet, 'contexte' => $contexte];
    }
}
