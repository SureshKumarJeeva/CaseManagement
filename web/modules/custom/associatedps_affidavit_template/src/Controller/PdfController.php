<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Symfony\Component\HttpFoundation\Response;

class PdfController{
    public function download(){
        $html = file_get_contents("public://template_uploads/latest.html");
        $pdfService = \Drupal::service('associatedps_affidavit_template.pdf');
        $pdfpath = 'public://template_uploads/generated.pdf';
        $pdfService->generateFromHtml($html, $pdfpath);

        return new Response(file_get_contents($pdfpath), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="template.pdf"'
        ]);
    }
}