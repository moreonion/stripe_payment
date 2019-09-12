<?php

namespace Drupal\stripe_payment;

use Drupal\little_helpers\ElementTree;
use Drupal\payment_forms\CreditCardForm as _CreditCardForm;

class CreditCardForm extends _CreditCardForm {
  static protected $issuers = array(
    'visa'           => 'Visa',
    'mastercard'     => 'MasterCard',
    'amex'           => 'American Express',
    'jcb'            => 'JCB',
    'discover'       => 'Discover',
    'diners_club'    => 'Diners Club',
  );
  static protected $cvc_label = array(
    'visa'           => 'CVV2 (Card Verification Value 2)',
    'amex'           => 'CID (Card Identification Number)',
    'mastercard'     => 'CVC2 (Card Validation Code 2)',
    'jcb'            => 'CSC (Card Security Code)',
    'discover'       => 'CID (Card Identification Number)',
    'diners_club'    => 'CSC (Card Security Code)',
  );

  public function form(array $form, array &$form_state, \Payment $payment) {
    $form = parent::form($form, $form_state, $payment);
    $method = &$payment->method;

    $intent = Api::init($method)->createIntent($payment);
    $settings['stripe_payment']['pmid_' . $method->pmid] = array(
      'public_key' => $method->controller_data['public_key'],
      'client_secret' => $intent->client_secret,
      'intent_type' => $intent->object,
      'pmid' => $method->pmid,
    );
    $form['#attached']['js'][] = [
      'type' => 'setting',
      'data' => $settings,
    ];
    $form['#attached']['js']['https://js.stripe.com/v3/'] = [
      'type' => 'external',
      'group' => JS_LIBRARY,
    ];
    $form['#attached']['js'][drupal_get_path('module', 'stripe_payment') . '/js/stripe.min.js'] = [
      'type' => 'file',
    ];

    // insert stripe id field
    $form['stripe_id'] = array(
      '#type' => 'hidden',
    );

    // override payment fields
    $form['credit_card_number'] = [
      '#type' => 'stripe_payment_field',
      '#field_name' => 'cardNumber',
      '#attributes' => [
        'class' => ['cc-number']
      ],
    ] + $form['credit_card_number'];
    $form['secure_code'] = [
      '#type' => 'stripe_payment_field',
      '#field_name' => 'cardCvc',
      '#attributes' => [
        'class' => ['cc-cvv']
      ],
    ] + $form['secure_code'];
    $form['expiry_date'] = [
      '#type' => 'stripe_payment_field',
      '#field_name' => 'cardExpiry',
      '#attributes' => [
        'class' => ['cc-expiry']
      ],
    ] + $form['expiry_date'];

    // remove unused default fields
    unset($form['expiry_date']['month']);
    unset($form['expiry_date']['year']);
    unset($form['issuer']);

    $form['extra_data'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('stripe-extra-data')),
    ) + CustomerDataForm::form($method->controller_data['input_settings'], $payment->contextObj);
    return $form;
  }

  public function validate(array $element, array &$form_state, \Payment $payment) {
    // Stripe takes care of the real validation, client-side.
    $values = drupal_array_get_nested_value($form_state['values'], $element['#parents']);
    $payment->method_data['stripe_id'] = $values['stripe_id'];
    $payment->method_data['customer'] = CustomerDataForm::getData($element);
  }

}
