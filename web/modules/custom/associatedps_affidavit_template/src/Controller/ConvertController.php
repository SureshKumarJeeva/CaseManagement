<?php

namespace Drupal\associatedps_affidavit_template\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class ConvertController extends ControllerBase {
    /*
    *function to save file contents (html) to remote location at the server 
    */
    public function save(Request $request){
        $html = $request->request->get('html');
        file_put_contents("public://template_uploads/latest.html", $html);
        return new JsonResponse(["status"=>"ok"]);
    }

    /*
    * Controller function to accepts any file format and convert its content to HTML
    */
    public function convert(Request $request){
        $fid = $request->get('fid');
        $response = ['success'=>FALSE, 'html'=>'', 'message'=>''];

        if($fid){
            //load file entity
            $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
            if($file){
                //call to converter service and get the html content
                $converter = \Drupal::service('associatedps_affidavit_template.converter');
                $html = $converter->convertToHtml($file->getFileUri());
                \Drupal::logger('template_upload')->info('Conversion done for fid: ' . $fid);
                \Drupal::logger('template_upload')->info('Converted HTML: ' . $html);

                $response['success'] = TRUE;
                $response['html'] = $html;
            }else{
                $response['message'] = "File not found";
            }
        }else{
            $response['message'] = "Missing file id";
        }
        return new JsonResponse($response);
    }
}