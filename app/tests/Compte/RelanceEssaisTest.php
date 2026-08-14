<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\RelanceEssais;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Relance de fin d'essai (#108) : à J-X (X éditable en base, défaut 7), un email
 * est envoyé une seule fois au compte dont l'essai approche — pour que l'artisan
 * ne soit jamais surpris. Aucun envoi réel sous test (double en mémoire).
 */
final class RelanceEssaisTest extends KernelTestCase
{
    public function testIlRelanceUnCompteDontLEssaiApprocheEtUneSeuleFois(): void
    {
        self::bootKernel();
        // Essai Classique (60 j) créé il y a 57 j → fin dans ~3 j (≤ 7).
        $this->seedCompte(9601, 'artisan-proche@example.com', creeIlYaJours: 57);

        self::assertSame(1, $this->relance()->executer());

        $emails = $this->mailer()->envoyes();
        self::assertCount(1, $emails);
        self::assertSame('artisan-proche@example.com', $emails[0]->destinataire());
        self::assertTrue($this->comptes()->pourTenant(9601)?->relanceEnvoyee());

        // Idempotence : un second passage ne renvoie rien.
        self::assertSame(0, $this->relance()->executer());
        self::assertCount(1, $this->mailer()->envoyes());
    }

    public function testLEmailDeRelanceEstUnDocumentHtmlHabilleAuxCouleursDeLaMarque(): void
    {
        self::bootKernel();
        // Essai Classique (60 j) créé il y a 57 j → ~3 j restants, quota 10 intact.
        $this->seedCompte(9604, 'artisan-design@example.com', creeIlYaJours: 57);

        self::assertSame(1, $this->relance()->executer());

        $corps = $this->mailer()->envoyes()[0]->corpsHtml();

        // Document HTML complet (et non un fragment) : robuste aux clients mail.
        self::assertStringContainsStringIgnoringCase('<!doctype html', $corps);
        // Texte de pré-en-tête (aperçu du client mail) et identité de marque.
        self::assertStringContainsString('Valeur Verte', $corps);
        self::assertStringContainsStringIgnoringCase('se termine', $corps);
        // Données personnalisées : jours et rapports restants (Classique : ~3 j, 10 rapports).
        self::assertMatchesRegularExpression('/3\s*jours/u', $corps);
        self::assertMatchesRegularExpression('/10\s*rapports/u', $corps);
        // Appel à l'action bouton vers l'espace pro.
        self::assertStringContainsString('https://app.', $corps);
        self::assertStringContainsString('Continuer avec Valeur Verte', $corps);
        // Réassurance : les rapports déjà produits restent accessibles.
        self::assertStringContainsString('restent accessibles', $corps);
    }

    public function testIlNeRelancePasUnEssaiEncoreLoinDeLaFin(): void
    {
        self::bootKernel();
        $this->seedCompte(9602, 'artisan-loin@example.com', creeIlYaJours: 0);

        self::assertSame(0, $this->relance()->executer());
        self::assertCount(0, $this->mailer()->envoyes());
    }

    public function testIlNeRelancePasUnEssaiDejaTermine(): void
    {
        self::bootKernel();
        // Créé il y a 65 j → essai (60 j) déjà terminé : pas de relance a posteriori.
        $this->seedCompte(9603, 'artisan-fini@example.com', creeIlYaJours: 65);

        self::assertSame(0, $this->relance()->executer());
        self::assertCount(0, $this->mailer()->envoyes());
    }

    private function seedCompte(int $tenantId, string $email, int $creeIlYaJours): void
    {
        $artisan = new User($email, ['ROLE_PRO']);
        $artisan->rattacherAuTenant($tenantId);
        $this->users()->enregistrer($artisan);
        $creeLe = (new \DateTimeImmutable())->modify(\sprintf('-%d days', $creeIlYaJours));
        $this->comptes()->enregistrer(Compte::creer($tenantId, $creeLe));
    }

    private function relance(): RelanceEssais
    {
        $relance = self::getContainer()->get(RelanceEssais::class);
        self::assertInstanceOf(RelanceEssais::class, $relance);

        return $relance;
    }

    private function mailer(): MailerEnMemoire
    {
        $mailer = self::getContainer()->get(MailerEnMemoire::class);
        self::assertInstanceOf(MailerEnMemoire::class, $mailer);

        return $mailer;
    }

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }

    private function users(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
