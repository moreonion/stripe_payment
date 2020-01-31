<?php

namespace Drupal\stripe_payment;

/**
 * Payment method controller for stripe credit card payments.
 */
class SepaController extends StripeController {

  /**
   * Create a new controller instance.
   */
  public function __construct() {
    $this->title = t('Stripe SEPA');
    $this->intentSettings = [
      'payment_method_types' => ['sepa_debit'],
    ];
    // Stripe requires a name and an email address for SEPA payments,
    // set default to display those fields if not already on the form.
    $this->controller_data_defaults['input_settings'] = [
      'billing_details' => [
        'name' => [
          'enabled' => 1,
          'display' => 'ifnotset',
          'required' => 1,
        ],
        'email' => [
          'enabled' => 1,
          'display' => 'ifnotset',
          'required' => 1,
        ],
      ]
    ];
    parent::__construct();
  }

  /**
   * Get a payment form.
   *
   * @return SepaForm
   *   A new credit card form.
   */
  public function paymentForm() {
    return new SepaForm();
  }

}
