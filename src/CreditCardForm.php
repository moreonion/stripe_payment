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
    $method = &$payment->method;

    $intent = Api::init($method)->createIntent($payment);
    $settings['stripe_payment']['pmid_' . $method->pmid] = [
      'public_key' => $method->controller_data['public_key'],
      'client_secret' => $intent->client_secret,
      'intent_type' => $intent->object,
      'pmid' => $method->pmid,
      'font_src' => variable_get('stripe_payment_font_src', []),
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

    $form['extra_data'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stripe-extra-data']],
    ] + CustomerDataForm::form($method->controller_data['input_settings'], $payment->contextObj);
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
    // Stripe takes care of the real validation, client-side.
    $values = drupal_array_get_nested_value($form_state['values'], $element['#parents']);
    $payment->method_data['stripe_id'] = $values['stripe_id'];
    $payment->method_data['customer'] = CustomerDataForm::getData($element);
  }

}
