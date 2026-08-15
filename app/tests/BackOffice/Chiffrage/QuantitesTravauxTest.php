<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Chiffrage;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Chiffrage\QuantitesTravaux;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Rapport\Dpe;
use PHPUnit\Framework\TestCase;

/**
 * Quantités déduites de la saisie d'audit (#26). La grille de coûts (#34) exprime
 * ses montants en €/m², €/unité ou en forfait (cf. Poste::unite()) : il faut donc
 * une quantité par poste pour la convertir en euros.
 *
 * Le formulaire d'audit reste court (ONB-3) : rien n'est demandé au pro au-delà de
 * ce qu'il saisit déjà, les quantités sont dérivées.
 */
final class QuantitesTravauxTest extends TestCase
{
    public function testUnPosteAuMetreCarreEstQuantifieParLaSurfaceBatie(): void
    {
        $quantites = QuantitesTravaux::depuis($this->saisie(surfaceBatie: 100, nombrePieces: 5));

        self::assertSame(100, $quantites->pour(Poste::Murs));
        self::assertSame(100, $quantites->pour(Poste::Combles));
        self::assertSame(100, $quantites->pour(Poste::PlanchersBas));
    }

    public function testUnPosteForfaitaireVautUneUnite(): void
    {
        $quantites = QuantitesTravaux::depuis($this->saisie(surfaceBatie: 100, nombrePieces: 5));

        self::assertSame(1, $quantites->pour(Poste::Chauffage));
        self::assertSame(1, $quantites->pour(Poste::Ecs));
        self::assertSame(1, $quantites->pour(Poste::Vmc));
    }

    public function testLesOuvrantsSontDeduitsDuNombreDePieces(): void
    {
        // Ratio retenu : 1,5 ouvrant par pièce, arrondi au supérieur.
        $quantites = QuantitesTravaux::depuis($this->saisie(surfaceBatie: 100, nombrePieces: 5));

        self::assertSame(8, $quantites->pour(Poste::Menuiseries));
    }

    public function testSansNombreDePiecesLesPiecesSontEllesMemesDeduitesDeLaSurface(): void
    {
        // Le nombre de pièces est facultatif à l'audit : on retombe sur une pièce
        // pour 20 m², puis on applique le même ratio d'ouvrants.
        $quantites = QuantitesTravaux::depuis($this->saisie(surfaceBatie: 100, nombrePieces: null));

        self::assertSame(8, $quantites->pour(Poste::Menuiseries));
    }

    public function testUnTresPetitLogementGardeAuMoinsUnOuvrant(): void
    {
        // Borne basse : un logement sans ouvrant n'existe pas, et une quantité nulle
        // ferait disparaître le poste du chiffrage au lieu de le sous-estimer.
        $quantites = QuantitesTravaux::depuis($this->saisie(surfaceBatie: 20, nombrePieces: null));

        self::assertGreaterThanOrEqual(1, $quantites->pour(Poste::Menuiseries));
    }

    private function saisie(int $surfaceBatie, ?int $nombrePieces): SaisieAudit
    {
        return SaisieAudit::depuis(
            '12 rue des Lilas, Toulouse',
            $surfaceBatie,
            0,
            Dpe::E,
            Dpe::C,
            $nombrePieces,
        );
    }
}
