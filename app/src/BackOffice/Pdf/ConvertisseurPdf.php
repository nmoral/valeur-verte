<?php

declare(strict_types=1);

namespace App\BackOffice\Pdf;

/**
 * Port de conversion d'un document HTML en PDF. Isole le domaine du moteur de
 * rendu concret (mpdf) : le générateur de rapport ne connaît que cette frontière.
 */
interface ConvertisseurPdf
{
    /**
     * @return string le contenu binaire du PDF produit
     */
    public function depuisHtml(string $html): string;
}
