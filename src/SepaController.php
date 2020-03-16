<?php

namespace Drupal\stripe_payment;

/**
 * Payment method controller for stripe credit card payments.
 */
class SepaController extends StripeController {

  /**
   * Create a new controller instance.
   */
  public function __construct() {
    $this->title = t('Stripe SEPA');
    $this->intentSettings = [
      'payment_method_types' => ['sepa_debit'],
    ];
    $this->controller_data_defaults += [
      'creditor_id' => '',
    ];
    parent::__construct();
  }

  /**
   * Get a payment form.
   *
   * @return SepaForm
   *   A new SEPA form.
   */
  public function paymentForm() {
    return new SepaForm();
  }

  /**
   * Get a customer data form.
   *
   * @return CustomerDataForm
   *   A new customer data form.
   */
  public function customerDataForm() {
    return new SepaCustomerDataForm();
  }

  /**
   * Get a form for configuring the payment method.
   *
   * @return SepaConfigurationForm
   *   A new SEPA configuration form.
   */
  public function configurationForm() {
    return new SepaConfigurationForm();
  }

}
