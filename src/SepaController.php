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
