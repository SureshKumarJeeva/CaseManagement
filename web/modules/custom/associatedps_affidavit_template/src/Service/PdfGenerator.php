<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Dompdf\Dompdf;

class PdfGenerator{
    public function generateFromHtml($html, $outputpath){
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper("A4", 'portrait');
        $dompdf->render();
        file_put_contents($outputpath, $dompdf->output());    
    }
}