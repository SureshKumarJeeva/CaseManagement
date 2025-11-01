<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;

class FileConverter{
    public function convertToHtml($uri){
        $path = \Drupal::service('file_system')->realpath($uri);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        \Drupal::logger('template_upload')->info('convertToHtml path: ' . $path);
        \Drupal::logger('template_upload')->info('convertToHtml ext: ' . $extension);
        switch($extension){
            case 'pdf': 
                \Drupal::logger('template_upload')->info('convertToHtml pdf case: ' . $extension);
                $parser = new Parser();
                $pdf = $parser->parseFile($path);
                $text = nl2br($pdf->getText());
                \Drupal::logger('template_upload')->info('convertToHtml text: ' . $text);
                return "<div class='converted-html'>".$text."</div>";
            case 'docx':
                $phpword = IOFactory::load($path);
                $htmlwriter = IOFactory::createWriter($phpword, 'HTML');
                ob_start();
                $htmlwriter->save("php://output");
                return ob_get_clean();
            case 'eml':
                $content = file_get_contents($path);
                return "<pre>".htmlspecialchars($content)."</pre>";
            default:
                return "<p>Unsupported file type</p>";
        }
    }
}