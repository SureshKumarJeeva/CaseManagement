<?php

namespace Drupal\associatedps_affidavit_template\Service;

use Drupal\associatedps_forms\FormDisplayFields;

/**
 * Builds the "Available Tokens" list for Library email templates.
 *
 * A thin caller over the shared Drupal\associatedps_forms\FormDisplayFields:
 * that class supplies WHICH Job fields exist and WHAT they are called
 * (the same "<Tab>-<Section>-...-<Field>" labels the DocuSign token picker and
 * the /jobs/filter dropdown use, so the three lists can never drift apart).
 * This class only formats the VALUE, which for email templates is Drupal token
 * syntax the email resolver understands:
 *
 *   field_client_s_job_email      => [node:field_client_s_job_email]
 *   field_client__mail            => [node:field_client:mail]
 *
 * i.e. the path separator "__" becomes ":" (the resolver in
 * associatedps_pdf_templates treats a single ":" as an inner/sub-field) and the
 * whole thing is wrapped in [node:...]. FormDisplayFields only ever goes one
 * level deep, so there is at most one ":" — exactly what the resolver supports.
 *
 * The author picks a label from a Chosen select; the matching [node:...] value
 * is copied to the clipboard and pasted into the email body. Nothing is stored
 * on the node: the resolver reads the tokens straight out of the body at send
 * time (see _associatedps_pdf_templates_library_data).
 */
class EmailTemplateTokenMapper {

  /**
   * Available Tokens for the JOB email templates (case-status / cron sends).
   *
   * Job form uses tabs, so untabbed fields are omitted (the DocuSign-parity
   * default).
   *
   * @return array
   *   An associative array of [node:...] token => label.
   */
  public function getEmailTokenOptions(): array {
    return $this->buildOptions('job', FALSE);
  }

  /**
   * Available Tokens for the ONLINE INSTRUCTION admin email template.
   *
   * The online_instruction node form has NO tabs, only fieldsets (and some
   * fields in no group at all), so pass includeUntabbed = TRUE: labels fall
   * back to the fieldset chain, or the plain field label. Value format is the
   * same [node:...] grammar (e.g. [node:field_upload_documents]).
   *
   * @return array
   *   An associative array of [node:...] token => label.
   */
  public function getInstructionTokenOptions(): array {
    return $this->buildOptions('online_instruction', TRUE);
  }

  /**
   * Build token => label options for a node bundle.
   *
   * Value is the [node:...] token (what gets copied); label is the readable
   * form-display path from the shared FormDisplayFields. Fields buildLabel()
   * rejects (NULL) are skipped. Sorted by label so related fields group.
   *
   * @param string $bundle
   *   The node bundle ('job' or 'online_instruction').
   * @param bool $includeUntabbed
   *   TRUE for tab-less layouts (see getInstructionTokenOptions()).
   *
   * @return array
   *   An associative array of [node:...] token => label.
   */
  protected function buildOptions(string $bundle, bool $includeUntabbed): array {
    $fields = FormDisplayFields::create('node', $bundle, 'default');
    $options = [];

    foreach ($fields->getFieldPaths() as $path) {
      $label = $fields->buildLabel($path, $includeUntabbed);
      if ($label === NULL) {
        continue;
      }
      $token = '[node:' . str_replace(FormDisplayFields::PATH_SEPARATOR, ':', $path) . ']';
      $options[$token] = $label;
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

}
