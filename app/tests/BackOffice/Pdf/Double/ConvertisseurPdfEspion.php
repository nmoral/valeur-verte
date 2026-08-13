<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf\Double;

use App\BackOffice\Pdf\ConvertisseurPdf;

/**
 * Double du port de conversion : capture le HTML reçu et renvoie un contenu
 * préfixé, sans dépendre du moteur PDF. Permet d'exercer le rendu Twig du
 * rapport (#32) et la délégation du générateur au convertisseur.
 */
final class ConvertisseurPdfEspion implements ConvertisseurPdf
{
    public string $dernierHtml = '';

    public function depuisHtml(string $html): string
    {
        $this->dernierHtml = $html;

        return 'PDF::'.$html;
    }
}
