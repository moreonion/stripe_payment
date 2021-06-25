<?php

namespace Drupal\stripe_payment;

/**
 * Configuration form for the Stripe Payment Request payment method controller.
 */
class PaymentRequestConfigurationForm extends StripeConfigurationForm {

  /**
   * Add form elements to the configuration form.
   *
   * @param array $form
   *   The Drupal form array.
   * @param array $form_state
   *   The Drupal form_state array.
   * @param \PaymentMethod $method
   *   The Stripe payment method.
   *
   * @return array
   *   The updated form array.
   */
  public function form(array $form, array &$form_state, \PaymentMethod $method) {
    $form = parent::form($form, $form_state, $method);

    return $form;
  }

}
