<?php

declare(strict_types=1);

namespace App\Tests\Controller\Compte;

use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\JetonDesabonnement;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Route publique de désabonnement (#108/#109) : le lien signé de l'email désabonne le
 * compte sans session. Le jeton HMAC fait toute la sécurité — un lien falsifié
 * ou un tenant inconnu est refusé (404), sans révéler l'existence du compte.
 */
final class DesabonnementControllerTest extends WebTestCase
{
    public function testUnLienSigneDesabonneLeCompteEtLeConfirme(): void
    {
        $client = static::createClient();
        $this->seedCompte(7701);

        $jeton = $this->jetons()->pour(7701);
        $client->request('GET', 'http://app.valeurverte.localhost/desabonnement?tenant=7701&jeton='.$jeton);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Désabonnement');
        self::assertTrue($this->comptes()->pourTenant(7701)?->estDesabonneDesEmails());
    }

    public function testUnJetonInvalideEstRejeteEtNeDesabonnePas(): void
    {
        $client = static::createClient();
        $this->seedCompte(7702);

        $client->request('GET', 'http://app.valeurverte.localhost/desabonnement?tenant=7702&jeton=faux');

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->comptes()->pourTenant(7702)?->estDesabonneDesEmails());
    }

    public function testUnTenantInconnuEstRejete(): void
    {
        $client = static::createClient();

        $jeton = $this->jetons()->pour(7703);
        $client->request('GET', 'http://app.valeurverte.localhost/desabonnement?tenant=7703&jeton='.$jeton);

        self::assertResponseStatusCodeSame(404);
    }

    private function seedCompte(int $tenantId): void
    {
        $this->comptes()->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable()));
    }

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }

    private function jetons(): JetonDesabonnement
    {
        $jetons = self::getContainer()->get(JetonDesabonnement::class);
        self::assertInstanceOf(JetonDesabonnement::class, $jetons);

        return $jetons;
    }
}
