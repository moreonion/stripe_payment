<?php

namespace Drupal\stripe_payment;

use Drupal\payment_forms\CreditCardForm as _CreditCardForm;

/**
 * Stripe credit card form.
 */
class CreditCardForm extends _CreditCardForm {

  /**
   * Add form elements for Stripe credit card payments.
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
    $form = parent::form($form, $form_state, $payment);
    $form = StripeForm::form($form, $form_state, $payment);

    // Override payment fields.
    $form['credit_card_number'] = [
      '#type' => 'stripe_payment_field',
      '#field_name' => 'cardNumber',
      '#attributes' => [
        'class' => ['cc-number'],
        'name' => 'cc-number',
      ],
    ] + $form['credit_card_number'];
    $form['secure_code'] = [
      '#type' => 'stripe_payment_field',
      '#field_name' => 'cardCvc',
      '#attributes' => [
        'class' => ['cc-cvv'],
        'name' => 'cc-cvv',
      ],
    ] + $form['secure_code'];
    $form['expiry_date'] = [
      '#type' => 'stripe_payment_field',
      '#field_name' => 'cardExpiry',
      '#attributes' => [
        'class' => ['cc-expiry'],
        'name' => 'cc-expiry',
      ],
    ] + $form['expiry_date'];

    // Remove unused default fields.
    unset($form['expiry_date']['month']);
    unset($form['expiry_date']['year']);
    unset($form['issuer']);

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
