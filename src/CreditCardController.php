<?php

namespace Drupal\stripe_payment;

use Drupal\webform_paymethod_select\PaymentRecurrentController;

/**
 * Payment method controller for stripe credit card payments.
 */
class CreditCardController extends \PaymentMethodController implements PaymentRecurrentController {

  public $controller_data_defaults = array(
    'private_key' => '',
    'public_key'  => '',
    'webhook_key' => '',
    'field_map' => [],
    'enable_recurrent_payments' => 1,
  );

  /**
   * Create a new controller instance.
   */
  public function __construct() {
    $this->title = t('Stripe Credit Card');

    $this->payment_configuration_form_elements_callback = 'payment_forms_payment_form';
    $this->payment_method_configuration_form_elements_callback = 'payment_forms_method_configuration_form';
  }

  /**
   * Get a payment form.
   */
  public function paymentForm() {
    return new CreditCardForm();
  }

  /**
   * Get a form for configuring the payment method.
   */
  public function configurationForm() {
    return new CreditCardConfigurationForm();
  }

  /**
   * Check whether this payment method is available for a payment.
   */
  public function validate(\Payment $payment, \PaymentMethod $method, $strict) {
    parent::validate($payment, $method, $strict);

    if (!($library = libraries_detect('stripe-php')) || empty($library['installed'])) {
      throw new \PaymentValidationException(t('The stripe-php library could not be found.'));
    }
    if (version_compare($library['version'], '3', '<')) {
      throw new \PaymentValidationException(t('stripe_payment needs at least version 3 of the stripe-php library (installed: @version).', array('@version' => $library['version'])));
    }

    if ($payment->contextObj && ($interval = $payment->contextObj->value('donation_interval'))) {
      if (empty($method->controller_data['enable_recurrent_payments']) && in_array($interval, ['m', 'y'])) {
        throw new \PaymentValidationException(t('Recurrent payments are disabled for this payment method.'));
      }
    }
  }

  /**
   * Execute the payment transaction.
   */
  public function execute(\Payment $payment) {
    $payment->setStatus(new \PaymentStatusItem(STRIPE_PAYMENT_STATUS_ACCEPTED));
    entity_save('payment', $payment);

    $api = Api::init($payment->method);
    $intent = $api->retrieveIntent($payment->method_data['stripe_id']);

    // Save one off payment record.
    if ($intent->object == 'payment_intent') {
      $payment->stripe = $this->createRecord($intent);
    }

    // Save recurrent payment record.
    list($one_off, $recurring) = Utils::splitRecurring($payment);
    if ($recurring->line_items) {
      $customer = $api->createCustomer($intent, [
        // TODO: Use CreditCardForm::mappedFields()
        'name' => Utils::getName($payment),
        'email' => $payment->contextObj->value('email'),
      ]);
      foreach (Utils::generateSubscriptions($recurring) as $subscription_options) {
        $subscription = $api->createSubscription(['customer' => $customer->id] + $subscription_options);
        $subscription_item = reset($subscription->items->data);
        $payment->stripe = $this->createRecord($subscription, $subscription_item->plan->id);
      }
    }
    entity_save('payment', $payment);
  }

  /**
   * Create a record for the {stripe_payment} table.
   */
  protected function createRecord($stripe, $plan_id = NULL) {
    return [
      // Subscription id (sub_) or payment intent id (pi_).
      'stripe_id' => $stripe->id,
      // "subscription" or "payment_intent".
      'type'      => $stripe->object,
      'plan_id'   => $plan_id,
    ];
  }

}
