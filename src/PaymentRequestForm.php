<?php

namespace Drupal\stripe_payment;

use Drupal\payment_forms\PaymentFormInterface;

/**
 * Stripe Payment Request form.
 */
class PaymentRequestForm implements PaymentFormInterface {

  /**
   * Add form elements for Stripe Payment Request payments.
   *
   * @param array $form
   *   The Drupal form array.
   * @param array $form_state
   *   The Drupal form_state array.
   * @param \Payment $payment
   *   The payment object.
   *
   * @return array
   *   The updated form array.
   */
  public function form(array $form, array &$form_state, \Payment $payment) {
    $form = StripeForm::form($form, $form_state, $payment);

    return $form;
  }

  /**
   * Store relevant values in the payment’s method_data.
   *
   * @param array $element
   *   The Drupal elements array.
   * @param array $form_state
   *   The Drupal form_state array.
   * @param \Payment $payment
   *   The payment object.
   */
  public function validate(array $element, array &$form_state, \Payment $payment) {
    StripeForm::validate($element, $form_state, $payment);
  }

}
