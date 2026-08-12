<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Notification\NotificationAdmin;
use App\Security\LimiteurInscription;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class LimiteurInscriptionTest extends TestCase
{
    public function testBloqueEtNotifieLAdminAuDelaDeLaLimite(): void
    {
        $notification = new NotificationAdminEspion();
        $limiteur = new LimiteurInscription($this->fabrique(3), $notification);

        $limiteur->verifier('1.2.3.4');
        $limiteur->verifier('1.2.3.4');
        $limiteur->verifier('1.2.3.4');
        self::assertSame([], $notification->alertes, 'Sous la limite : aucune alerte.');

        $this->expectException(TooManyRequestsHttpException::class);
        try {
            $limiteur->verifier('1.2.3.4');
        } finally {
            self::assertCount(1, $notification->alertes, 'Un dépassement doit notifier l\'admin.');
        }
    }

    public function testLaLimiteEstIndependanteParIp(): void
    {
        $notification = new NotificationAdminEspion();
        $limiteur = new LimiteurInscription($this->fabrique(1), $notification);

        $limiteur->verifier('1.1.1.1');
        // Une autre IP dispose de sa propre fenêtre : pas de blocage.
        $limiteur->verifier('2.2.2.2');

        self::assertSame([], $notification->alertes);
    }

    private function fabrique(int $limite): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'inscription_ip', 'policy' => 'sliding_window', 'limit' => $limite, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }
}

/**
 * @internal
 */
final class NotificationAdminEspion implements NotificationAdmin
{
    /**
     * @var list<string>
     */
    public array $alertes = [];

    public function usageAnormal(string $sujet, string $contexte): void
    {
        $this->alertes[] = $sujet;
    }
}
