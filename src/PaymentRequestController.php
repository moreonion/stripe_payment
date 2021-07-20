<?php

namespace Drupal\stripe_payment;

/**
 * Payment method controller for stripe payment request payments.
 */
class PaymentRequestController extends StripeController {

  /**
   * Create a new controller instance.
   */
  public function __construct() {
    $this->title = t('Stripe Payment Request');
    $this->intentSettings = [
      'payment_method_types' => ['card'],
    ];
    $this->controller_data_defaults += [
      'account_country' => variable_get('site_default_country', ''),
      'button_type' => 'default',
      'button_style'  => 'dark',
    ];
    parent::__construct();
  }

  /**
   * Get a payment form.
   *
   * @return PaymentRequestForm
   *   A new payment request form.
   */
  public function paymentForm() {
    return new PaymentRequestForm();
  }

  /**
   * Get a form for configuring the payment method.
   *
   * @return PaymentRequestConfigurationForm
   *   A new Payment Request configuration form.
   */
  public function configurationForm() {
    return new PaymentRequestConfigurationForm();
  }

}
