<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\Compte\CompteRepository;
use App\Security\CreateurDeCompte;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suspension (#322) : un compte suspendu ne peut plus modifier sa configuration —
 * marque (#34), bouquets (#35), page publique (#38). Chaque écriture (POST) est
 * refusée et renvoyée vers l'espace pro ; la lecture (GET) reste ouverte.
 */
final class EcrituresConfigBloqueesSiSuspenduTest extends WebTestCase
{
    private const HOTE_PRO = 'app.valeurverte.localhost';

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function ecritures(): iterable
    {
        yield 'marque' => ['/marque', ['couleur' => '#123456', 'nom' => 'X']];
        yield 'bouquet enregistrer' => ['/bouquets', ['nom' => 'B', 'postes' => []]];
        yield 'bouquet supprimer' => ['/bouquets/supprimer', ['nom' => 'B']];
        yield 'page slug' => ['/page-publique/slug', ['slug' => 'nouveau-slug']];
        yield 'page retirer' => ['/page-publique/retirer', []];
        yield 'page remettre' => ['/page-publique/remettre-en-ligne', []];
        yield 'page regenerer' => ['/page-publique/regenerer', []];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ecritures')]
    public function testUneEcritureDeConfigEstRefusee(string $chemin, array $params): void
    {
        $client = $this->proSuspendu('config-suspendu@example.com');

        $client->request('POST', 'http://'.self::HOTE_PRO.$chemin, $params);

        self::assertResponseStatusCodeSame(302);
        self::assertTrue(
            str_ends_with((string) $client->getResponse()->headers->get('Location'), '/'),
            'Une écriture refusée doit renvoyer vers l\'espace pro.',
        );
    }

    private function proSuspendu(string $email): KernelBrowser
    {
        $client = static::createClient();
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);
        $user = $createur->creer($email, 'motdepasse-solide', '2026-08-16');
        $client->loginUser($user);
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);
        $compte = $comptes->pourTenant($tenantId);
        self::assertNotNull($compte);
        $compte->suspendre(new \DateTimeImmutable('2026-08-20 10:00:00'));
        $comptes->enregistrer($compte);

        return $client;
    }
}
