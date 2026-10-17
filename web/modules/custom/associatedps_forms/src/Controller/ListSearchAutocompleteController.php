<?php

namespace Drupal\associatedps_forms\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Type-ahead suggestions for the search box on the app's list pages.
 *
 * Route: /list-search/autocomplete/{view_id}/{display_id}?q=...
 *
 * Suggestions are produced by RUNNING THE PAGE'S OWN VIEW with the typed text
 * as its search value, then collecting the values of the search's fields that
 * contain the text. So they are exactly what the search box would match, and
 * they obey everything the page itself applies — its fixed filters, access,
 * and per-role scoping such as the client "Jobs Page Per Client" email filter
 * in associatedps_forms_views_query_alter(). Nothing is suggested that the
 * user could not see on that page.
 *
 * Only the views in associatedps_forms_list_search_views() are served.
 */
class ListSearchAutocompleteController extends ControllerBase {

  /**
   * How many rows to scan, and how many suggestions to return.
   */
  const SCAN_ROWS = 100;
  const MAX_SUGGESTIONS = 15;

  /**
   * Minimum typed length (matches the JS minLength).
   */
  const MIN_LENGTH = 2;

  public function suggest(string $view_id, string $display_id, Request $request): JsonResponse {
    if (!in_array($view_id, associatedps_forms_list_search_views(), TRUE)) {
      throw new NotFoundHttpException();
    }

    $typed = trim((string) $request->query->get('q', ''));
    if (mb_strlen($typed) < self::MIN_LENGTH) {
      return new JsonResponse([]);
    }

    $view = Views::getView($view_id);
    if (!$view || !$view->setDisplay($display_id)) {
      throw new NotFoundHttpException();
    }
    if (!$view->access($display_id)) {
      throw new AccessDeniedHttpException();
    }

    $filter = associatedps_forms_list_search_filter($view);
    if (!$filter) {
      return new JsonResponse([]);
    }

    // Run the page's view with the typed text in its search box.
    $view->setExposedInput([$filter['identifier'] => $typed]);
    $view->setItemsPerPage(self::SCAN_ROWS);
    $view->setCurrentPage(0);
    $view->execute();

    $suggestions = [];
    $needle = mb_strtolower($typed);
    foreach (array_keys($view->result) as $index) {
      foreach ($filter['fields'] as $field_id) {
        if (!isset($view->field[$field_id])) {
          continue;
        }
        $text = $this->plainText($view->style_plugin->getField($index, $field_id));
        if ($text === '' || mb_strpos(mb_strtolower($text), $needle) === FALSE) {
          continue;
        }
        // Distinct, case-insensitively.
        $suggestions[mb_strtolower($text)] = $text;
        if (count($suggestions) >= self::MAX_SUGGESTIONS) {
          break 2;
        }
      }
    }

    $matches = [];
    foreach ($suggestions as $text) {
      $matches[] = ['value' => $text, 'label' => $text];
    }
    return new JsonResponse($matches);
  }

  /**
   * A rendered field value as one line of plain text.
   */
  protected function plainText($rendered): string {
    $text = Html::decodeEntities(strip_tags((string) $rendered));
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

}
