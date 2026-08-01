<?php

namespace Drupal\associatedps_forms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Form for Custom Add Client in create/edit job.
 */
class CustomAddClient extends FormBase {

  /**
   * Get form ID.
   */
  public function getFormId() {
    return 'custom_add_client';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $userCurrent = \Drupal::currentUser();
    $user = User::load($userCurrent->id());
    $roles = $user->getRoles();
    if ($userCurrent->id() > 0 && $roles[1] !== "agent") {

      $client_name = '';
      $client_id = '';
      $telephone = '';
      $generic_email = '';
      $discount = '0';
      $notes = '';
      $sa_address_line1 = '';
      $sa_address_line2 = '';
      $sa_locality = '';
      $sa_postal_code = '';
      $sa_administrative_area = '';

      $path = \Drupal::service('path.current')->getPath();
      $nid = (int) filter_var($path, FILTER_SANITIZE_NUMBER_INT);
      $nid = abs($nid);

      if ($client_id == '') {
        $connection = \Drupal::database();
        $query = $connection->query("SELECT field_client_id_value AS cid FROM {node__field_client_id} ORDER BY entity_id DESC");
        $result = $query->fetchField();
        $client_id = '54323' . (((int) (str_replace('54323', '', $result))) + 1);
      }

      $form = [];

      $form['client_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Client Name'),
        '#required' => TRUE,
        '#suffix' => '<p id="client-name-validate"></p>',
        '#default_value' => $client_name,
      ];

      $form['client_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Client ID'),
        '#required' => TRUE,
        '#suffix' => '<p id="client-id-validate"></p>',
        '#default_value' => $client_id,
      ];

      $form['address'] = [
        '#type' => 'details',
        '#title' => t('Address'),
        '#open' => TRUE,
      ];

      // Create the address field.
      $form['address']['site_address'] = [
        '#type' => 'address',
        '#field_overrides' => [
          'addressLine1' => 'optional',
          'addressLine2' => 'optional',
          'administrativeArea' => 'optional',
          'locality' => 'optional',
          'postalCode' => 'optional',
          'sortingCode' => 'hidden',
          'givenName' => 'hidden',
          'additionalName' => 'hidden',
          'familyName' => 'hidden',
          'organization' => 'hidden',
        ],
        '#available_countries' => ['AU'],
        '#default_value' => [
          'country_code' => 'AU',
          'administrative_area' => $sa_administrative_area,
          'locality' => $sa_locality,
          'postal_code' => $sa_postal_code,
          'address_line1' => $sa_address_line1,
          'address_line2' => $sa_address_line2,
        ],
      ];

      $form['telephone'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Telephone'),
        '#required' => FALSE,
        '#default_value' => $telephone,
      ];

      $form['generic_email'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Generic Email'),
        '#required' => FALSE,
        '#size' => 50,
        '#maxlength' => 50,
        '#suffix' => '<p id="email-validate"></p>',
        '#ajax' => [
          'callback' => '::validateEmailAjax',
          'effect' => 'fade',
          'event' => 'change',
          'progress' => [
            'type' => 'throbber',
            'message' => NULL,
          ],
        ],
        '#default_value' => $generic_email,
      ];

      $form['discount'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Agreed Discount'),
        '#attributes' => [
          'type' => 'number',
        ],
        '#required' => FALSE,
        '#default_value' => $discount,
      ];

      $form['notes'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Notes'),
        '#required' => FALSE,
        '#format' => 'full_html',
        '#default_value' => $notes,
      ];

      // Email update options — mirror the Client content type's email-sending
      // checkboxes so this modal matches the full "Add content -> Client" page.
      // The booleans mirror the node.client field defaults: all CHECKED except
      // Attendance Update - Frequency, which defaults UNCHECKED. The
      // "Frequency" days input pairs with the Attendance Update checkbox
      // (reuses field_email_interval). Order follows the client form display
      // weights (8-13). These defaults are hardcoded here (hand-built form, not
      // entity-form-driven), so changing a field's default value in config does
      // NOT propagate — update it here too.
      $form['email_options'] = [
        '#type' => 'details',
        '#title' => $this->t('Email Update Options'),
        '#open' => TRUE,
      ];

      $form['email_options']['instructions_received'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Instructions Received'),
        '#default_value' => 1,
      ];

      // Checkbox + "Frequency (days)" input on a single row (inline styles so
      // the layout holds inside the AJAX dialog without depending on an
      // external stylesheet loading).
      $form['email_options']['frequency_row'] = [
        '#type' => 'container',
        // margin-left:1.5rem aligns the row's checkbox with the other Claro
        // checkboxes (Instructions Received, Served, ...), whose form-items
        // carry that default left indent.
        '#attributes' => [
          'style' => 'display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin-left:1.5rem;',
        ],
      ];

      $form['email_options']['frequency_row']['attendance_update_frequenc'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Attendance Update - Frequency'),
        // The only one that defaults UNCHECKED — matches the node.client field
        // default for field_attendance_update_frequenc.
        '#default_value' => 0,
        '#wrapper_attributes' => ['style' => 'margin:0;'],
      ];

      $form['email_options']['frequency_row']['email_interval'] = [
        '#type' => 'number',
        '#title' => $this->t('Frequency'),
        '#title_display' => 'inline',
        '#field_suffix' => $this->t('day(s)'),
        '#min' => 0,
        '#default_value' => '',
        '#attributes' => ['style' => 'width:6rem;'],
        '#wrapper_attributes' => ['style' => 'margin:0; display:flex; align-items:center; gap:0.4rem;'],
      ];

      // Titles mirror the Client page labels exactly (en-dash U+2013). The
      // array keys stay as-is — they are local form keys, mapped to the real
      // fields in the submit handler.
      $form['email_options']['served'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Completed – Served'),
        '#default_value' => 1,
      ];

      $form['email_options']['attempted'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Completed – Attempted'),
        '#default_value' => 1,
      ];

      $form['email_options']['cancelled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Completed – Cancelled'),
        '#default_value' => 1,
      ];

      $form['email_options']['completed'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Completed – Enquiries'),
        '#default_value' => 1,
      ];

      $form['job_id'] = [
        '#type' => 'hidden',
        '#value' => $nid,
      ];

      $form['submit'] = [
        '#value' => $this->t("Save"),
        '#type' => 'submit',
        '#ajax' => [
          'callback' => '::submitFormAddClient',
        ],
      ];

      $form['#attached']['library'] = [
        'core/drupal.dialog.ajax',
      ];

      $form['#cache']['max-age'] = 0;
      $form['#attributes']['class'] = ['wrapper-form-add-client'];

      return $form;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateEmailAjax(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $email_value = $form_state->getValue('generic_email');

    if (!empty($email_value) && !\Drupal::service('email.validator')->isValid($email_value)) {
      $response->addCommand(new CssCommand('.form-item-generic-email input', ['border' => '1px solid red']));
      $response->addCommand(new HtmlCommand('#email-validate', 'Please enter a valid email address.'));
    }
    elseif (empty($email_value)) {
      $response->addCommand(new CssCommand('.form-item-generic-email input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
      $response->addCommand(new HtmlCommand('#email-validate', ''));
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

  /**
   * AJAX callback for the client form save button.
   */
  public function submitFormAddClient(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $values = $form_state->getValues();
    $client_name = $values['client_name'];
    $client_id_value = $values['client_id'];
    $job_id = (int) $values['job_id'];

    if (empty($client_id_value) || empty($client_name)) {
      if (empty($client_id_value)) {
        $response->addCommand(new CssCommand('.form-item-client-id input', ['border' => '1px solid red']));
        $response->addCommand(new HtmlCommand('#client-id-validate', 'Please enter a Client ID.'));
      }
      if (empty($client_name)) {
        $response->addCommand(new CssCommand('.form-item-client-name input', ['border' => '1px solid red']));
        $response->addCommand(new HtmlCommand('#client-name-validate', 'Please enter a Client Name.'));
      }
      return $response;
    }

    $response->addCommand(new CssCommand('.form-item-client-id input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
    $response->addCommand(new HtmlCommand('#client-name-validate', ''));
    $response->addCommand(new CssCommand('.form-item-client-name input', ['border' => '1px solid #b8b8b8', 'border-top-color' => '#999']));
    $response->addCommand(new HtmlCommand('#client-id-validate', ''));

    // Build the address array with country_code.
    $address_data = [
      'country_code' => $values['site_address']['country_code'] ?? 'AU',
      'address_line1' => $values['site_address']['address_line1'] ?? '',
      'address_line2' => $values['site_address']['address_line2'] ?? '',
      'locality' => $values['site_address']['locality'] ?? '',
      'administrative_area' => $values['site_address']['administrative_area'] ?? '',
      'postal_code' => $values['site_address']['postal_code'] ?? '',
    ];

    // Determine if we should update an existing client or create a new one.
    $client_node = NULL;
    $job_node = NULL;
    if ($job_id > 0) {
      $job_node = Node::load($job_id);
      if ($job_node) {
        $client_ref = $job_node->get('field_client')->getValue();
        if (!empty($client_ref)) {
          $client_node = Node::load($client_ref[0]['target_id']);
        }
      }
    }

    if (!$client_node) {
      $client_node = Node::create(['type' => 'client']);
      $client_node->status = 1;
    }

    // Save client fields.
    $client_node->set('title', $values['client_name']);
    $client_node->set('field_client_id', $values['client_id']);
    $client_node->set('field_telephone', $values['telephone']);
    $client_node->set('field_email', $values['generic_email']);
    $client_node->set('field_agreed_discount', $values['discount']);
    $client_node->set('field_notes', $values['notes']);
    $client_node->set('field_client_address', $address_data);

    // Email update options (5 opt-in checkboxes + frequency days).
    $client_node->set('field_instructions_received', !empty($values['instructions_received']) ? 1 : 0);
    $client_node->set('field_attendance_update_frequenc', !empty($values['attendance_update_frequenc']) ? 1 : 0);
    $client_node->set('field_served', !empty($values['served']) ? 1 : 0);
    $client_node->set('field_attempted', !empty($values['attempted']) ? 1 : 0);
    $client_node->set('field_cancelled', !empty($values['cancelled']) ? 1 : 0);
    $client_node->set('field_completed', !empty($values['completed']) ? 1 : 0);
    if (isset($values['email_interval']) && $values['email_interval'] !== '') {
      $client_node->set('field_email_interval', (int) $values['email_interval']);
    }

    $client_node->save();

    $node_id = $client_node->id();

    // If editing an existing job, set the client reference and save the job.
    // The presave hook will sync the denormalized fields from the client node.
    if ($job_node) {
      $job_node->set('field_client', ['target_id' => $node_id]);
      $job_node->save();
    }

    // Only set cookies for new job creation (job_id = 0). For existing jobs
    // the client and job are saved directly above, so cookies are not needed.
    if ($job_id === 0) {
      $notes_value = is_array($values['notes']) ? ($values['notes']['value'] ?? '') : $values['notes'];
      $value_submit = [
        'node_id' => $node_id,
        'client_submit' => 1,
        'client_name' => $values['client_name'],
        'client_id' => $values['client_id'],
        'telephone' => $values['telephone'],
        'generic_email' => $values['generic_email'],
        'discount' => $values['discount'],
        'notes' => $notes_value,
        'sa_address_line1' => $address_data['address_line1'],
        'sa_address_line2' => $address_data['address_line2'],
        'sa_locality' => $address_data['locality'],
        'sa_postal_code' => $address_data['postal_code'],
        'sa_administrative_area' => $address_data['administrative_area'],
      ];
      user_cookie_save($value_submit);
    }

    // Close dialog and redirect back to the job form.
    $response->addCommand(new CloseDialogCommand());

    if ($job_id === 0) {
      $redirect_url = '/node/add/job';
    }
    else {
      $redirect_url = '/node/' . $job_id . '/edit';
    }
    $response->addCommand(new RedirectCommand($redirect_url));

    return $response;
  }

}
