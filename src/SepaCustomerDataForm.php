<?php

namespace Drupal\stripe_payment;

/**
 * Form for customer data that’s used for SEPA payments.
 */
class SepaCustomerDataForm extends CustomerDataForm {

  /**
   * Extend default field input settings.
   */
  public function defaultSettings() {
    $defaults = parent::defaultSettings();
    // Stripe requires a name and an email address for SEPA payments,
    // set default to display those fields if not already on the form.
    $defaults['billing_details']['name'] = [
      'enabled' => TRUE,
      'display' => 'ifnotset',
      'keys' => ['name'],
      'required' => TRUE,
      'display_other' => 'ifnotset',
    ];
    $defaults['billing_details']['email'] = [
      'enabled' => TRUE,
      'display' => 'ifnotset',
      'keys' => ['email'],
      'required' => TRUE,
      'display_other' => 'ifnotset',
    ];
    return $defaults;
  }

  /**
   * Extends Stripe fields.
   */
  public function fields() {
    $fields = parent::fields();
    // Ensure Stripe required fields are required.
    $fields['billing_details']['name']['#required'] = TRUE;
    $fields['billing_details']['email']['#required'] = TRUE;
    return $fields;
  }

}
