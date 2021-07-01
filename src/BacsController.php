<?php

namespace Drupal\stripe_payment;

/**
 * Payment method controller for stripe Bacs payments.
 */
class BacsController extends StripeController {

  /**
   * Create a new controller instance.
   */
  public function __construct() {
    $this->title = t('Stripe Bacs');
    $this->intentSettings = [
      'payment_method_types' => ['bacs_debit'],
    ];
    parent::__construct();
  }

  /**
   * Get a payment form.
   *
   * @return BacsForm
   *   A new Bacs form.
   */
  public function paymentForm() {
    return new BacsForm();
  }

  /**
   * Get a customer data form.
   *
   * @return CustomerDataForm
   *   A new customer data form.
   */
  public function customerDataForm() {
    return new BacsCustomerDataForm();
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
      $details = $intent['payment_method']['bacs_debit'];
    }
    else {
      // At the time of writing this neither PaymentIntents nor PaymentMethods
      // include the mandate data. So we need this detour via charges.
      $intent = parent::fetchIntent($payment, $api, $expand);
      $details = $intent['charges']['data'][0]['payment_method_details']['bacs_debit'];
      $mandate = $api->retrieveMandate($details['mandate']);
    }
    $payment->stripe_bacs = [
      'mandate_reference' => $mandate['payment_method_details']['bacs_debit']['reference'],
      'last4' => $details['last4'],
    ];
    return $intent;
  }

}
