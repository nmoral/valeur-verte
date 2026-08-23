<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\BackOffice\Rapport\Rapport;
use App\Security\CreateurDeCompte;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Ouvre une session professionnelle pour les tests de l'espace pro.
 *
 * Depuis #306, les routes du back-office sont épinglées sur `app.` : elles ne
 * répondent plus sur l'apex, et la zone exige `ROLE_PRO`. Un test de l'espace pro
 * doit donc viser le bon hôte **et** être connecté — ce qui décrit d'ailleurs mieux
 * la réalité qu'un audit produit par un visiteur anonyme.
 *
 * L'adresse par défaut dérive de la classe de test : chaque classe a la sienne, et
 * DAMA remet la base à zéro entre les tests, donc deux tests d'une même classe
 * peuvent la réutiliser sans se marcher dessus.
 */
trait ConnecteUnPro
{
    protected const HOTE_PRO = 'app.valeurverte.localhost';

    /**
     * @return int l'identifiant de tenant du compte connecté
     */
    protected function connecterPro(KernelBrowser $client, ?string $email = null): int
    {
        $adresse = $email ?? self::emailParDefaut();
        $utilisateurs = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);

        // Idempotent : un test qui ouvre deux navigateurs (un pour préparer, un pour
        // vérifier) rejoue la même session plutôt que de buter sur l'unicité de
        // l'adresse — et surtout, les deux voient bien le **même** tenant.
        $user = $utilisateurs->findOneBy(['email' => $adresse]);
        if (!$user instanceof User) {
            $createur = self::getContainer()->get(CreateurDeCompte::class);
            self::assertInstanceOf(CreateurDeCompte::class, $createur);
            $user = $createur->creer($adresse, 'motdepasse-solide', '2026-08-16');
        }
        $client->loginUser($user);

        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        return $tenantId;
    }

    /**
     * La session **du navigateur déjà connecté**, prête à recevoir des clés.
     *
     * Un test qui fabriquait sa propre session écrasait le cookie posé par
     * `loginUser` et repartait donc anonyme — invisible tant que l'espace pro
     * répondait sans authentification (#306). On reprend ici l'identifiant du cookie
     * en place : même session, la connexion survit.
     */
    protected function sessionDuClient(KernelBrowser $client): SessionInterface
    {
        $fabrique = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $fabrique);
        $session = $fabrique->createSession();

        $cookie = $client->getCookieJar()->get($session->getName());
        if (null !== $cookie) {
            $session->setId($cookie->getValue());
        }
        $session->start();

        return $session;
    }

    /**
     * Rattache un rapport semé au tenant connecté. Sans ça, le cloisonnement par
     * tenant le rend invisible à l'écran qui vient le chercher — un test qui semait
     * « pour personne » ne passait que parce que l'espace pro répondait sans compte.
     */
    protected static function pourLeTenant(int $tenantId, Rapport $rapport): Rapport
    {
        $rapport->setTenantId($tenantId);

        return $rapport;
    }

    protected static function urlPro(string $chemin): string
    {
        return 'http://'.self::HOTE_PRO.$chemin;
    }

    private static function emailParDefaut(): string
    {
        $classe = strtolower(str_replace('\\', '-', static::class));

        return substr(strrchr($classe, '-') ?: $classe, 1).'@example.com';
    }
}
