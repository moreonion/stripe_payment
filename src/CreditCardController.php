<?php

namespace Drupal\stripe_payment;

/**
 * Payment method controller for stripe credit card payments.
 */
class CreditCardController extends StripeController {

  /**
   * Create a new controller instance.
   */
  public function __construct() {
    $this->title = t('Stripe Credit Card');
    parent::__construct();
  }

  /**
   * Get a payment form.
   *
   * @return CreditCardForm
   *   A new credit card form.
   */
  public function paymentForm() {
    return new CreditCardForm();
  }

}
