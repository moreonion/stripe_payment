<?php

namespace Drupal\stripe_payment;

/**
 * Configuration form for the Stripe SEPA payment method controller.
 */
class SepaConfigurationForm extends StripeConfigurationForm {

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
    $cd = $method->controller_data;
    $form['creditor_id'] = [
      '#type' => 'textfield',
      '#title' => t('Creditor ID'),
      '#description' => t('The creditor is used for displaying the SEPA mandate information.'),
      '#default_value' => $cd['creditor_id'],
      '#weight' => 5,
    ];
    return $form;
  }

}
