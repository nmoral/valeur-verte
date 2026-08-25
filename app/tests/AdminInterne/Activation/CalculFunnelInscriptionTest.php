<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Activation;

use App\AdminInterne\Activation\CalculFunnelInscription;
use App\Onboarding\Parcours\EvenementInscription;
use App\Onboarding\Parcours\EvenementInscriptionRepository;
use App\Onboarding\Parcours\TypeEvenementInscription;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le funnel d'inscription (#111) lit les événements anonymes du parcours et en
 * dérive le taux d'abandon sur l'écran SIRET : une visite qui a vu l'écran mais
 * n'a pas créé de compte est un abandon. On compte des sessions distinctes, pas
 * des événements — un rechargement de l'écran ne doit pas gonfler le taux.
 */
final class CalculFunnelInscriptionTest extends KernelTestCase
{
    public function testTauxDAbandonSurLEcranSiret(): void
    {
        self::bootKernel();
        // 4 visites affichent l'écran, 3 renseignent le SIRET, 3 créent un compte.
        $this->seed(1, TypeEvenementInscription::InscriptionAffichee);
        $this->seed(2, TypeEvenementInscription::InscriptionAffichee);
        $this->seed(3, TypeEvenementInscription::InscriptionAffichee);
        $this->seed(4, TypeEvenementInscription::InscriptionAffichee);
        $this->seed(1, TypeEvenementInscription::SiretRenseigne);
        $this->seed(2, TypeEvenementInscription::SiretRenseigne);
        $this->seed(3, TypeEvenementInscription::SiretRenseigne);
        $this->seed(1, TypeEvenementInscription::CompteCree);
        $this->seed(2, TypeEvenementInscription::CompteCree);
        $this->seed(3, TypeEvenementInscription::CompteCree);

        $funnel = $this->calcul()->calculer();

        self::assertSame(4, $funnel->affichees());
        self::assertSame(3, $funnel->siretRenseigne());
        self::assertSame(3, $funnel->compteCree());
        // Abandon = 1 visite sur 4 → 25 %.
        self::assertSame(1, $funnel->abandonSiret()->numerateur());
        self::assertSame(4, $funnel->abandonSiret()->denominateur());
        self::assertSame(25.0, $funnel->abandonSiret()->pourcentage());
    }

    public function testUnRechargementDeLEcranNeGonflePasLeDenominateur(): void
    {
        self::bootKernel();
        // Une seule visite, deux affichages (rechargement), un compte créé.
        $this->seed(1, TypeEvenementInscription::InscriptionAffichee);
        $this->seed(1, TypeEvenementInscription::InscriptionAffichee);
        $this->seed(1, TypeEvenementInscription::CompteCree);

        $funnel = $this->calcul()->calculer();

        self::assertSame(1, $funnel->affichees());
        self::assertSame(0.0, $funnel->abandonSiret()->pourcentage());
    }

    public function testSansEvenementLeTauxEstIndefini(): void
    {
        self::bootKernel();

        $funnel = $this->calcul()->calculer();

        self::assertSame(0, $funnel->affichees());
        self::assertNull($funnel->abandonSiret()->pourcentage());
    }

    private function seed(int $visite, TypeEvenementInscription $type): void
    {
        $session = str_pad((string) $visite, 32, '0', \STR_PAD_LEFT);
        $this->evenements()->enregistrer(
            EvenementInscription::survenu($session, $type, new \DateTimeImmutable('2026-08-25 09:00:00')),
        );
    }

    private function calcul(): CalculFunnelInscription
    {
        return new CalculFunnelInscription($this->evenements());
    }

    private function evenements(): EvenementInscriptionRepository
    {
        $repository = self::getContainer()->get(EvenementInscriptionRepository::class);
        self::assertInstanceOf(EvenementInscriptionRepository::class, $repository);

        return $repository;
    }
}
