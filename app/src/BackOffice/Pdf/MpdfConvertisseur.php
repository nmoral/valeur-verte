<?php

declare(strict_types=1);

namespace App\BackOffice\Pdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Adaptateur mpdf du port de conversion HTML → PDF (#32). Rendu A4 portrait ;
 * le répertoire temporaire est isolé sous le tmp système pour rester inscriptible
 * dans le conteneur applicatif comme en CI.
 */
final class MpdfConvertisseur implements ConvertisseurPdf
{
    public function depuisHtml(string $html): string
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => sys_get_temp_dir().'/mpdf',
        ]);
        $mpdf->WriteHTML($html);

        // Output() est typé « mixed » par mpdf ; en mode STRING_RETURN il rend
        // bien le contenu binaire du document.
        $pdf = $mpdf->Output('', Destination::STRING_RETURN);
        \assert(\is_string($pdf));

        return $pdf;
    }
}
