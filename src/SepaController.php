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

  /**
   * Load the intent object and populate the $payment object accordingly.
   */
  protected function fetchIntent(\Payment $payment, Api $api, array $expand = []) {
    $recurring = substr($payment->method_data['stripe_id'], 0, 5) == 'seti_';
    if ($recurring) {
      $expand[] = 'mandate';
      $expand[] = 'payment_method';
      $intent = parent::fetchIntent($payment, $api, $expand);
      $mandate = $intent['mandate'];
      $details = $intent['payment_method']['sepa_debit'];
    }
    else {
      // At the time of writing this neither PaymentIntents nor PaymentMethods
      // include the mandate data. So we need this detour via charges.
      $intent = parent::fetchIntent($payment, $api, $expand);
      $details = $intent['charges']['data'][0]['payment_method_details']['sepa_debit'];
      $mandate = $api->retrieveMandate($details['mandate']);
    }
    $payment->stripe_sepa = [
      'mandate_reference' => $mandate['payment_method_details']['sepa_debit']['reference'],
      'last4' => $details['last4'],
    ];
    return $intent;
  }

}
