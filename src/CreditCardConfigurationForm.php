<?php

namespace Drupal\stripe_payment;

use Drupal\payment_forms\MethodFormInterface;
use Stripe\Account;
use Stripe\Stripe;
use Stripe\Exception\ApiErrorException;

/**
 * Configuration form for the Stripe payment method controller.
 */
class CreditCardConfigurationForm implements MethodFormInterface {

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
    $cd = $method->controller_data;

    $library = libraries_detect('stripe-php');
    if (empty($library['installed'])) {
      drupal_set_message($library['error message'], 'error', FALSE);
    }

    $form['private_key'] = [
      '#type' => 'textfield',
      '#title' => t('Private key'),
      '#description' => t('Available from Your Account / Developers / API keys'),
      '#required' => TRUE,
      '#default_value' => $cd['private_key'],
    ];

    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => t('Public key'),
      '#description' => t('Available from Your Account / Developers / API keys'),
      '#required' => TRUE,
      '#default_value' => $cd['public_key'],
    ];

    $form['enable_recurrent_payments'] = [
      '#type' => 'checkbox',
      '#title' => t('Enable recurrent payments'),
      '#description' => t('Check this if you want to enable stripe payment plans. In addition to enabling this, your payment context needs to support recurrent payments'),
      '#default_value' => $cd['enable_recurrent_payments'],
    ];
    $form['input_settings'] = [
      '#type' => 'fieldset',
      '#title' => t('Personal data mapping'),
      '#description' => t('This setting allows you to map data from the payment context to stripe fields. If data is found for one of the mapped fields it will be transferred to stripe. Use a comma to separate multiple field keys.'),
    ] + CustomerDataForm::configurationForm($method->controller_data['input_settings']);
    return $form;
  }

  /**
   * Validate the submitted values and put them in the method’s controller data.
   *
   * @param array $element
   *   The Drupal elements array.
   * @param array $form_state
   *   The Drupal form_state array.
   * @param \PaymentMethod $method
   *   The payment method.
   */
  public function validate(array $element, array &$form_state, \PaymentMethod $method) {
    $cd = drupal_array_get_nested_value($form_state['values'], $element['#parents']);
    foreach ($cd['field_map'] as $k => &$v) {
      $v = array_filter(array_map('trim', explode(',', $v)));
    }

    $library = libraries_detect('stripe-php');
    if (empty($library['installed'])) {
      drupal_set_message($library['error message'], 'error', FALSE);
    }

    if (substr($cd['private_key'], 0, 3) != 'sk_') {
      form_error($element['private_key'], t('Please enter a valid private key (starting with sk_).'));
    }
    else {
      libraries_load('stripe-php');
      try {
        Stripe::setApiKey($cd['private_key']);
        Account::retrieve();
      }
      catch (ApiErrorException $e) {
        $values = [
          '@status'   => $e->getHttpStatus(),
          '@message'  => $e->getMessage(),
        ];
        $msg = t('Unable to contact stripe using this set of keys: HTTP @status: @message.', $values);
        form_error($element['private_key'], $msg);
      }
    }
    if (substr($cd['public_key'], 0, 3) != 'pk_') {
      form_error($element['public_key'], t('Please enter a valid public key (starting with pk_).'));
    }

    $method->controller_data = $cd + $method->controller_data;
  }

}
