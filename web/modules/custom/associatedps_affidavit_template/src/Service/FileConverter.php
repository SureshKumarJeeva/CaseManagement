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
                $headerXml = NULL;
                $footerXml = NULL;
                $zip = new \ZipArchive();
                if ($zip->open($path) === TRUE) {
                    $headerXml = $zip->getFromName('word/header1.xml');
                    $footerXml = $zip->getFromName('word/footer1.xml');
                    $zip->close();
                }
                if(!empty($headerXml))
                    $headerHtml = $this->convertDocxHeaderFootertoHTML($path);

                $command = 'pandoc -f docx -t html ' . escapeshellarg($path);
                $output = shell_exec($command);
                \Drupal::logger('template_upload')->info('convertToHtml docx: ' . $output);

                $bodyHTML = $this->embeddImagestoHTML($output, $path);

                return $headerHtml.$bodyHTML;
            case 'eml':
                $content = file_get_contents($path);
                return "<pre>".htmlspecialchars($content)."</pre>";
            default:
                return "<p>Unsupported file type</p>";
        }
    }

    public function convertDocxHeaderFootertoHTML($path){
        $zip = new \ZipArchive();
        $zip->open($path);

        $headerXml = $zip->getFromName('word/header1.xml');
        $relsXml = $zip->getFromName('word/_rels/header1.xml.rels');

        $imageMap = [];
        if ($relsXml) {
            $rels = simplexml_load_string($relsXml);
            foreach ($rels->Relationship as $rel) {
                $rId = (string) $rel['Id'];
                $target = (string) $rel['Target']; // e.g. "media/image1.png"
                $imageMap[$rId] = $target;
            }
        }

        $dom = new \DOMDocument();
        $dom->loadXML($headerXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $html = '';

        foreach ($xpath->query('//w:p') as $p) {
            $paragraphHtml = '';

            foreach ($xpath->query('.//w:r', $p) as $run) {
                $textNode = $xpath->query('.//w:t', $run)->item(0);
                $text = $textNode ? htmlspecialchars($textNode->nodeValue) : '';

                // handle formatting
                $rPr = $xpath->query('.//w:rPr', $run)->item(0);
                if ($rPr) {
                if ($rPr->getElementsByTagName('b')->length) $text = '<strong>' . $text . '</strong>';
                if ($rPr->getElementsByTagName('i')->length) $text = '<em>' . $text . '</em>';
                if ($rPr->getElementsByTagName('u')->length) $text = '<u>' . $text . '</u>';
                }

                // handle images
                $blip = $xpath->query('.//a:blip', $run)->item(0);
                if ($blip) {
                    $rId = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
                    if (isset($imageMap[$rId])) {
                        $imageData = $zip->getFromName('word/' . $imageMap[$rId]);
                        $base64 = base64_encode($imageData);
                        $text .= '<img src="data:image/png;base64,' . $base64 . '" />';
                    }
                }

                $paragraphHtml .= $text;
            }

            $html .= '<p>' . $paragraphHtml . '</p>';
        }
        return $html;
        // $dom = new \DOMDocument();
        // $dom->loadXML($headerFooterxml);
        // $texts = $dom->getElementsByTagName('t');

        // $convertedHtml = '';
        // foreach ($texts as $t) {
        //     $convertedHtml .= '<p>' . htmlspecialchars($t->nodeValue) . '</p>';
        // }
    }

    public function embeddImagestoHTML($html, $path){
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR.'docx_extract_' . uniqid();
        mkdir($tempDir);

        $zip = new \ZipArchive();
        if ($zip->open($path) === TRUE) {
            // Extract all contents into the temp directory
            $zip->extractTo($tempDir);
            $zip->close();
        } else {
            throw new \Exception("Unable to open DOCX file as ZIP: {$docxPath}");
        }

        // Parse and embed images as base64
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $images = $dom->getElementsByTagName('img');
        foreach ($images as $img) {
            \Drupal::logger('convert docx to html')->info('embedd image: ');
            $src = $img->getAttribute('src');
            if (str_starts_with($src, 'data:image')) {
                \Drupal::logger('convert docx to html')->info('embedd image: continue data:image already exists');
                continue; // already base64
            }
            // Find the extracted image path (Pandoc stores them under $tempDir/media)
            $imgPath = $tempDir . DIRECTORY_SEPARATOR . basename($src);
            if (!file_exists($imgPath)) {
                // Try media subfolder
                $imgPath = $tempDir . DIRECTORY_SEPARATOR.'word'. DIRECTORY_SEPARATOR. 'media' . DIRECTORY_SEPARATOR. basename($src);
            }
            \Drupal::logger('convert docx to html')->info('embedd image: image path'.$imgPath);
            \Drupal::logger('convert docx to html')->info('embedd image: file_exists($imgPath)');
            $mimeType = mime_content_type($imgPath);
            $data = base64_encode(file_get_contents($imgPath));
            $img->setAttribute('src', 'data:' . $mimeType . ';base64,' . $data);
        }

        $bodyHtml = $dom->saveHTML();
        return $bodyHtml;
    }
}