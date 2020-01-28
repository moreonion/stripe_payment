<?php

namespace Drupal\stripe_payment;

use Drupal\payment_forms\AccountForm;

/**
 * Stripe SEPA form.
 */
class SepaForm extends AccountForm {

  /**
   * Add form elements for Stripe SEPA payments.
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
    $stripe_form = new StripeForm();
    $form = parent::form($form, $form_state, $payment);
    $form = $stripe_form->form($form, $form_state, $payment);

    // Override payment fields.
    $form['iban'] = [
      '#type' => 'stripe_payment_field',
      '#field_name' => 'iban',
      '#attributes' => [
        'class' => ['iban'],
      ],
    ] + $form['ibanbic']['iban'];

    // Remove unused default fields.
    unset($form['holder']);
    unset($form['ibanbic']);

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
