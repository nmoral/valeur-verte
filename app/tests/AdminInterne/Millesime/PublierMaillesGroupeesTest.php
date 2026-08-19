<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Millesime;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\AdminInterne\Millesime\DecisionMillesime;
use App\AdminInterne\Millesime\PublierMaillesGroupees;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Action de groupe (#239) : débloquer d'un coup plusieurs mailles écartées par
 * REG-1 (#130). L'identifiant porte le millésime (« millesime:codeInsee ») car
 * le socle d'action de groupe ne passe que la sélection ; la liste est bornée à
 * un millésime, l'encodage reste sans ambiguïté.
 *
 * Comme DecisionMillesimeTest, on rejoue la vraie migration 012 plutôt que de
 * recopier les fonctions SQL : une dérive casse ici, pas en production.
 */
final class PublierMaillesGroupeesTest extends KernelTestCase
{
    use ProvisionneSchemaData;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->provisionner();
        // provisionner() ne sème qu'une maille bloquée (31003) ; il en faut une
        // seconde pour prouver le traitement « de groupe ».
        $this->semerBloquee('31004');
    }

    public function testDebloqueToutesLesMaillesSelectionnees(): void
    {
        $this->publier(2024);

        $message = $this->action()->executer(['2024:31003', '2024:31004']);

        $servies = self::codes($this->app()->fetchFirstColumn('SELECT code_insee FROM data.coefficient_maille'));
        self::assertContains('31003', $servies);
        self::assertContains('31004', $servies);
        self::assertSame('2 maille(s) débloquée(s).', $message);
    }

    public function testChaqueDeblocageEstTraceAuJournal(): void
    {
        $this->publier(2024);

        $this->action()->executer(['2024:31003', '2024:31004']);

        $traces = $this->maillesTracees();
        self::assertContains('31003', $traces);
        self::assertContains('31004', $traces);
    }

    public function testUnMillesimeNonPublieNInterromptPasEtNeSertRien(): void
    {
        // Millésime non publié : la garde SQL refuse chaque déblocage. L'action ne
        // doit pas propager l'exception (sinon tout le LiveComponent casse) ni
        // rien mettre en service — elle rend compte des échecs.
        $message = $this->action()->executer(['2024:31003', '2024:31004']);

        self::assertSame(
            0,
            self::compte($this->app()->fetchOne('SELECT count(*) FROM data.coefficient_maille')),
        );
        self::assertSame('0 maille(s) débloquée(s), 2 en échec.', $message);
    }

    public function testUneSelectionVideNeFaitRien(): void
    {
        $this->publier(2024);

        self::assertSame('0 maille(s) débloquée(s).', $this->action()->executer([]));
    }

    /**
     * Sème une maille bloquée supplémentaire sous 2024 (mêmes colonnes que
     * provisionner(), avec classe_ecart = 'blocage').
     */
    private function semerBloquee(string $codeInsee): void
    {
        $this->prive()->executeStatement(
            "INSERT INTO data.coefficient_maille_historique
                (code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
                 coef_m2_bati, coef_m2_terrain, coef_dpe, effectifs_dpe,
                 mode, palier, classe_ecart, ecart, ecart_coefficient) VALUES
             (:code, 2024, 10, 95, 0.74, 19.50, 0.88, 0.28, '{\"D\":0.0,\"C\":0.11,\"E\":-0.19}', '{\"D\":30,\"C\":24,\"E\":22}', 'local', 'standard', 'blocage', 1.60, 'élasticité bâti')",
            ['code' => $codeInsee],
        );
    }

    private function publier(int $millesime): void
    {
        $this->decision()->publier($millesime, 'admin@vv.test');
    }

    /**
     * @return list<string>
     */
    private function maillesTracees(): array
    {
        $journal = self::getContainer()->get(JournalAudit::class);
        self::assertInstanceOf(JournalAudit::class, $journal);

        $codes = [];
        foreach ($journal->evenementsRecents(20) as $evenement) {
            if ('millesime.maille_debloquee' === $evenement->action()) {
                $code = $evenement->contexte()['code_insee'] ?? null;
                if (\is_string($code)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
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

    private function action(): PublierMaillesGroupees
    {
        $action = self::getContainer()->get(PublierMaillesGroupees::class);
        self::assertInstanceOf(PublierMaillesGroupees::class, $action);

        return $action;
    }

    private function decision(): DecisionMillesime
    {
        $decision = self::getContainer()->get(DecisionMillesime::class);
        self::assertInstanceOf(DecisionMillesime::class, $decision);

        return $decision;
    }
}
