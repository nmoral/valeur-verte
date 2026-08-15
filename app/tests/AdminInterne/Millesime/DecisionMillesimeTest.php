<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Millesime;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\AdminInterne\Millesime\DecisionMillesime;
use App\AdminInterne\Millesime\LectureMillesimes;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * REG-2 (#130) côté admin : lire l'état des millésimes et décider de leur sort.
 *
 * L'app est en **lecture seule** sur le schéma « data » (décision D6) : elle lit les
 * tables et n'invoque que les fonctions SQL exposées par la migration 012. Ce test
 * exécute cette migration **telle quelle** plutôt que de recréer les fonctions — si
 * elle dérive de ce que l'app attend, le test casse ici et non en production.
 */
final class DecisionMillesimeTest extends KernelTestCase
{
    use ProvisionneSchemaData;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->provisionner();
    }

    public function testUnMillesimeCalculeNaPasEncoreDEtat(): void
    {
        $millesimes = $this->lecture()->tous();

        self::assertCount(1, $millesimes);
        self::assertSame(2024, $millesimes[0]->millesime());
        self::assertNull($millesimes[0]->etat(), 'Calculé n\'est pas décidé.');
        self::assertTrue($millesimes[0]->estEnAttenteDeDecision());
    }

    public function testLesEffectifsDistinguentLesMaillesBloqueesEtEnAlerte(): void
    {
        $millesime = $this->lecture()->tous()[0];

        // Trois mailles semées : une normale, une en alerte, une bloquée.
        self::assertSame(3, $millesime->maillesCalculees());
        self::assertSame(1, $millesime->maillesBloquees());
        self::assertSame(1, $millesime->maillesEnAlerte());
    }

    public function testPublierProjetteLesMaillesSaufLesBloquees(): void
    {
        $this->decision()->publier(2024, 'admin@vv.test');

        $servies = $this->app()->fetchFirstColumn(
            'SELECT code_insee FROM data.coefficient_maille ORDER BY code_insee',
        );
        self::assertSame(['31001', '31002'], self::codes($servies));
    }

    public function testPublierEstTraceAuJournalAvecLeNombreDeMaillesServies(): void
    {
        $this->decision()->publier(2024, 'admin@vv.test');

        $evenement = $this->dernierEvenement('millesime.publie');
        self::assertNotNull($evenement);
        self::assertSame('admin@vv.test', $evenement->acteur());
        self::assertSame(2024, $evenement->contexte()['millesime'] ?? null);
        self::assertSame(2, $evenement->contexte()['mailles_publiees'] ?? null);
    }

    public function testAbandonnerNeTouchePasLaTableVive(): void
    {
        $this->decision()->abandonner(2024, 'admin@vv.test');

        self::assertSame(
            0,
            self::compte($this->app()->fetchOne('SELECT count(*) FROM data.coefficient_maille')),
            'Abandonner doit rester sans effet de bord : rien ne doit être servi.',
        );
        self::assertSame('abandonne', $this->lecture()->tous()[0]->etat());
    }

    public function testAbandonnerEstTraceAuJournal(): void
    {
        $this->decision()->abandonner(2024, 'admin@vv.test');

        self::assertNotNull($this->dernierEvenement('millesime.abandonne'));
    }

    public function testDebloquerUneMailleLaSertApresPublication(): void
    {
        $this->decision()->publier(2024, 'admin@vv.test');

        $this->decision()->debloquer('31003', 2024, 'admin@vv.test');

        $servies = $this->app()->fetchFirstColumn('SELECT code_insee FROM data.coefficient_maille');
        self::assertContains('31003', self::codes($servies));
        self::assertNotNull($this->dernierEvenement('millesime.maille_debloquee'));
    }

    public function testDebloquerAvantPublicationEstRefuse(): void
    {
        // Garde portée par la fonction SQL : une commune seule en N au milieu d'un
        // parc en N-1 serait l'état mixte que REG-2 cherche à éviter.
        $this->expectException(\RuntimeException::class);

        $this->decision()->debloquer('31003', 2024, 'admin@vv.test');
    }

    public function testLesMaillesBloqueesSontListeesAvecLeurEcart(): void
    {
        $bloquees = $this->lecture()->maillesBloquees(2024);

        self::assertCount(1, $bloquees);
        self::assertSame('31003', $bloquees[0]->codeInsee());
        self::assertSame(1.8, $bloquees[0]->ecart());
        self::assertSame('élasticité bâti', $bloquees[0]->coefficient());
    }

    /**
     * Codes INSEE nettoyés du remplissage CHAR(5).
     *
     * @param list<mixed> $lignes
     *
     * @return list<string>
     */
    private static function codes(array $lignes): array
    {
        return array_map(static fn (mixed $code): string => \is_string($code) ? trim($code) : '', $lignes);
    }

    private static function compte(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : -1;
    }

    private function lecture(): LectureMillesimes
    {
        $lecture = self::getContainer()->get(LectureMillesimes::class);
        self::assertInstanceOf(LectureMillesimes::class, $lecture);

        return $lecture;
    }

    private function decision(): DecisionMillesime
    {
        $decision = self::getContainer()->get(DecisionMillesime::class);
        self::assertInstanceOf(DecisionMillesime::class, $decision);

        return $decision;
    }

    private function dernierEvenement(string $action): ?\App\AdminInterne\JournalAudit\EvenementAudit
    {
        $journal = self::getContainer()->get(JournalAudit::class);
        self::assertInstanceOf(JournalAudit::class, $journal);

        foreach ($journal->evenementsRecents(20) as $evenement) {
            if ($action === $evenement->action()) {
                return $evenement;
            }
        }

        return null;
    }
}
