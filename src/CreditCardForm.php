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

    $ed = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('stripe-extra-data')),
    ) + $this->mappedFields($payment);

    // Stripe does only use the name attribute instead of first_name / last_name.
    if (!isset($ed['name']) && isset($ed['first_name']) && isset($ed['last_name'])) {
      $ed['name'] = $ed['first_name'];
      $ed['name']['#value'] .= ' ' . $ed['last_name']['#value'];
      $ed['name']['#attributes']['data-stripe'] = 'name';
    }
    unset($ed['first_name']);
    unset($ed['last_name']);

    $form['extra_data'] = $ed;
    return $form;
  }

  public function validate(array $element, array &$form_state, \Payment $payment) {
    // Stripe takes care of the real validation, client-side.
    $values = drupal_array_get_nested_value($form_state['values'], $element['#parents']);
    $payment->method_data['stripe_id'] = $values['stripe_id'];
  }

  protected function mappedFields(\Payment $payment) {
    $fields = array();
    $field_map = $payment->method->controller_data['field_map'];
    foreach (static::extraDataFields() as $name => $field) {
      $map = isset($field_map[$name]) ? $field_map[$name] : array();
      foreach ($map as $key) {
        if ($value = $payment->contextObj->value($key)) {
          $field['#value'] = $value;
          $fields[$name] = $field;
        }
      }
    }
    return $fields;
  }

  public static function extraDataFields() {
    $fields = array();
    $f = array(
      'name' => t('Name'),
      'first_name' => t('First name'),
      'last_name' => t('Last name'),
      'email' => t('Email address'),
      'phone' => t('Phone number'),
      'address.line1' => t('Address line 1'),
      'address.line2' => t('Address line 2'),
      'address.city' => t('City'),
      'address.state' => t('State'),
      'address.postal_code' => t('Postal code'),
      'address.country' => t('Country'),
    );
    foreach ($f as $name => $title) {
      $fields[$name] = array(
        '#type' => 'hidden',
        '#title' => $title,
        '#attributes' => array('data-stripe' => $name),
      );
    }
    return $fields;
  }
}
