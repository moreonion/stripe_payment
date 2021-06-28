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
    $cd = $method->controller_data;
    $form['button_type'] = [
      '#type' => 'select',
      '#title' => t('Pay button type'),
      '#description' => t('Select the type of the button.'),
      '#default_value' => $cd['button_type'],
      '#options' => array(
        'default' => t('Default'),
        'book' => t('Book'),
        'buy' => t('Buy'),
        'donate' => t('Donate'),
      ),
    ];
    $form['button_style'] = [
      '#type' => 'select',
      '#title' => t('Pay button color'),
      '#description' => t('Select the color scheme of the button.'),
      '#default_value' => $cd['button_style'],
      '#options' => array(
        'dark' => t('Dark'),
        'light' => t('Light'),
        'light-outline' => t('Light with outline'),
      ),
    ];
    return $form;
  }

}
