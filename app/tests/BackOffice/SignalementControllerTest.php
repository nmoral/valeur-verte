<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\AdminInterne\Signalement\Signalement;
use App\AdminInterne\Signalement\SignalementRepository;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\Restitution;
use App\Controller\BackOffice\AuditController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

final class SignalementControllerTest extends WebTestCase
{
    public function testSignalerEnregistreLeRetourAvecLeContexteDeLaSession(): void
    {
        $client = static::createClient();
        $this->poserResultatEnSession($client);

        $client->xmlHttpRequest('POST', 'http://valeurverte.localhost/audit/resultat/signalement', [
            'message' => 'Le gain me paraît trop élevé pour ce quartier.',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertTrue($charge['ok']);

        $signalements = $this->signalements()->recents();
        self::assertCount(1, $signalements);
        $signalement = $signalements[0];
        self::assertSame('12 rue des Lilas, 31000 Toulouse', $signalement->adresse());
        self::assertSame('Le gain me paraît trop élevé pour ce quartier.', $signalement->message());
        $contexte = $signalement->contexte();
        self::assertSame('E', $contexte['dpe_avant']);
        self::assertSame('C', $contexte['dpe_apres']);
        self::assertSame(134, $contexte['n']);
        self::assertSame(10, $contexte['rayon_km']);
        self::assertSame(15000, $contexte['montant_estime']);
    }

    public function testSansResultatEnSessionLeSignalementEstRefuse(): void
    {
        $client = static::createClient();

        $client->xmlHttpRequest('POST', 'http://valeurverte.localhost/audit/resultat/signalement', [
            'message' => 'test',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->signalements()->recents());
    }

    private function poserResultatEnSession(KernelBrowser $client): void
    {
        $factory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        $saisie = SaisieAudit::depuis('12 rue des Lilas, 31000 Toulouse', 90, 300, Dpe::E, Dpe::C);
        $restitution = Restitution::depuis(15000, 12000, 18000, 134, 10, 2024, 200000, false);
        $session->set(AuditController::SESSION_RESULTAT, [
            'saisie' => $saisie,
            'restitution' => $restitution,
            'reference' => '2026-0001',
        ]);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function signalements(): SignalementRepository
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(Signalement::class);
        self::assertInstanceOf(SignalementRepository::class, $repository);

        return $repository;
    }
}
