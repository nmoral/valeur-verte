<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf;

use App\BackOffice\Pdf\MpdfConvertisseur;
use PHPUnit\Framework\TestCase;

/**
 * Adaptateur mpdf du port de conversion HTML → PDF. On ne vérifie pas le rendu
 * pixel — hors de portée d'un test — mais que la sortie est bien un document PDF
 * (signature « %PDF- »), preuve que le HTML a été accepté et converti.
 */
final class MpdfConvertisseurTest extends TestCase
{
    public function testProduitUnDocumentPdf(): void
    {
        $pdf = (new MpdfConvertisseur())->depuisHtml('<h1>Rapport de valeur verte</h1>');

        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
