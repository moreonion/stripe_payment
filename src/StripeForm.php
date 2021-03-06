<?php

namespace Drupal\stripe_payment;

/**
 * Stripe form helper class.
 */
abstract class StripeForm {

  /**
   * Add form settings for Stripe payments.
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
  public static function form(array $form, array &$form_state, \Payment $payment) {
    $method = &$payment->method;
    $customer_data_form = $method->controller->customerDataForm();

    $settings['stripe_payment']['pmid_' . $method->pmid] = [
      'public_key' => $method->controller_data['public_key'],
      'pmid' => $method->pmid,
      'font_src' => variable_get('stripe_payment_font_src', []),
      'intent_callback_url' => $payment->contextObj->callbackUrl($method),
    ];
    $form['#attached']['js'][] = [
      'type' => 'setting',
      'data' => $settings,
    ];
    $form['#attached']['js']['https://js.stripe.com/v3/'] = [
      'type' => 'external',
      'group' => JS_LIBRARY,
    ];
    $form['#attached']['js'][drupal_get_path('module', 'stripe_payment') . '/stripe.min.js'] = [
      'type' => 'file',
    ];

    // Insert stripe id field.
    $form['stripe_id'] = [
      '#type' => 'hidden',
    ];

    $form['extra_data'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stripe-extra-data']],
    ] + $customer_data_form->form($method->controller_data['input_settings'], $payment->contextObj);
    return $form;
  }

  /**
   * Store relevant values in the payment’s method_data.
   *
   * Stripe takes care of the real validation, client-side.
   *
   * @param array $element
   *   The Drupal elements array.
   * @param array $form_state
   *   The Drupal form_state array.
   * @param \Payment $payment
   *   The payment object.
   */
  public static function validate(array $element, array &$form_state, \Payment $payment) {
    $values = drupal_array_get_nested_value($form_state['values'], $element['#parents']);
    $payment->method_data['stripe_id'] = $values['stripe_id'];
    $customer_data_form = $payment->method->controller->customerDataForm();
    $payment->method_data['customer'] = $customer_data_form->getData($element);
  }

}
