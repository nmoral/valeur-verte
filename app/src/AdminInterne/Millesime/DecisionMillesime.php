<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Décisions de publication d'un millésime (REG-2, #130), depuis l'admin interne.
 *
 * L'app est en **lecture seule** sur le schéma « data » (décision D6) : elle
 * n'écrit rien elle-même, elle invoque les fonctions SQL `SECURITY DEFINER` que le
 * pipeline expose. La logique de projection reste côté `data` — le contrat A/B tient,
 * le pipeline reste réécrivable sans toucher à l'app.
 *
 * Chaque décision est tracée au journal d'audit (#50) : publier un millésime change
 * ce que voient tous les clients, ça ne doit jamais être anonyme.
 */
final readonly class DecisionMillesime
{
    public function __construct(
        private Connection $connexion,
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Projette le millésime vers la table vive, sauf les mailles écartées par REG-1.
     */
    public function publier(int $millesime, string $auteur): int
    {
        $publiees = $this->invoquer(
            'SELECT data.publier_millesime(:millesime::smallint, :auteur)',
            ['millesime' => $millesime, 'auteur' => $auteur],
        );

        $this->tracer($auteur, 'millesime.publie', [
            'millesime' => $millesime,
            'mailles_publiees' => $publiees,
        ]);

        return $publiees;
    }

    /**
     * Écarte le millésime **sans effet de bord** : l'archive reste, la table vive
     * n'est pas touchée. Seule la décision est enregistrée.
     */
    public function abandonner(int $millesime, string $auteur): void
    {
        $this->invoquer(
            'SELECT data.abandonner_millesime(:millesime::smallint, :auteur)',
            ['millesime' => $millesime, 'auteur' => $auteur],
        );

        $this->tracer($auteur, 'millesime.abandonne', ['millesime' => $millesime]);
    }

    /**
     * Débloque une maille écartée par REG-1, après examen. Refusé tant que le
     * millésime n'est pas publié — la garde est portée par la fonction SQL, pas ici :
     * l'app l'invoque directement, une garde côté client serait contournable.
     */
    public function debloquer(string $codeInsee, int $millesime, string $auteur): void
    {
        $this->invoquer(
            'SELECT data.publier_maille(:code::char(5), :millesime::smallint, :auteur)',
            ['code' => $codeInsee, 'millesime' => $millesime, 'auteur' => $auteur],
        );

        $this->tracer($auteur, 'millesime.maille_debloquee', [
            'millesime' => $millesime,
            'code_insee' => $codeInsee,
        ]);
    }

    /**
     * @param array<string, mixed> $parametres
     */
    private function invoquer(string $requete, array $parametres): int
    {
        // Une garde du pipeline lève un RAISE EXCEPTION, qui **avorte la
        // transaction** : sans isolation, la moindre requête suivante échouerait à
        // son tour (« current transaction is aborted ») et l'écran d'erreur ne
        // pourrait même pas se réafficher. On borne donc l'appel à sa propre
        // transaction imbriquée (savepoint), que l'échec seul annule.
        $this->connexion->setNestTransactionsWithSavepoints(true);
        $this->connexion->beginTransaction();

        try {
            $resultat = $this->connexion->fetchOne($requete, $parametres);
            $this->connexion->commit();
        } catch (\Doctrine\DBAL\Exception $erreur) {
            $this->connexion->rollBack();

            // Les gardes remontent en exception SQL : on les traduit en erreur
            // exploitable plutôt que de laisser fuiter une trace Doctrine.
            throw new \RuntimeException(self::message($erreur), previous: $erreur);
        }

        return is_numeric($resultat) ? (int) $resultat : 0;
    }

    private static function message(\Throwable $erreur): string
    {
        $message = $erreur->getMessage();
        // Le message métier est porté par le RAISE EXCEPTION du PL/pgSQL ; on en
        // extrait la phrase utile, le reste étant du bruit de pilote.
        if (1 === preg_match('/ERROR:\s+(.+?)(\n|$)/', $message, $capture)) {
            return trim($capture[1]);
        }

        return 'La décision n\'a pas pu être appliquée.';
    }

    /**
     * @param array<string, mixed> $contexte
     */
    private function tracer(string $auteur, string $action, array $contexte): void
    {
        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: $action,
            cible: 'millesime',
            contexte: $contexte,
            survenuLe: $this->clock->now(),
        ));
    }
}
