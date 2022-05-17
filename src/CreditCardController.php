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
    $this->intentSettings = [
      'payment_method_types' => ['card'],
    ];
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

  /**
   * Check whether this payment method is available for a payment.
   *
   * @param \Payment $payment
   *   The payment to validate.
   * @param \PaymentMethod $method
   *   The payment method to check against.
   * @param bool $strict
   *   Whether to validate everything a payment for this method needs.
   *
   * @throws PaymentValidationException
   */
  public function validate(\Payment $payment, \PaymentMethod $method, $strict) {
    parent::validate($payment, $method, $strict);

    list($one_off, $recurring) = Utils::splitRecurring($payment);
    if (!$one_off->line_items && count($recurring->line_items) > 1) {
      throw new \PaymentValidationException(t('This payment method can only handle a single recurrent line item.'));
    }
  }

}
