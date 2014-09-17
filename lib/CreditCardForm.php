<?php

namespace Drupal\stripe_payment;

class CreditCardForm extends \Drupal\payment_forms\CreditCardForm {
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

  public function getForm(array &$form, array &$form_state) {
    parent::getForm($form, $form_state);
    $payment = &$form_state['payment'];

    drupal_add_js(
      array(
        'stripe_payment' => array(
          'public_key' => $payment->method->controller_data['public_key'],
        ),
      ),
      'setting'
    );

    $form['#attached']['js'] = array(
      array(
        'data' => 'https://js.stripe.com/v2/',
        'type' => 'external'
      ),
      array(
        'data' => drupal_get_path('module', 'stripe_payment') .
        '/stripe.js',
        'type' => 'file'
      )
    );

    $form['stripe_payment_token'] = array(
      '#type' => 'hidden',
      '#attributes' => array('class' => array('stripe-payment-token')),
    );

    return $form;
  }

  public function validateForm(array &$element, array &$form_state) {
    // Stripe takes care of the real validation, client-side.
    $values = drupal_array_get_nested_value(
      $form_state['values'], $element['#parents']);
    $form_state['payment']->method_data['stripe_payment_token'] =
      $values['stripe_payment_token'];
  }

}
