<?php

namespace Drupal\stripe_payment;

use Drupal\webform_paymethod_select\PaymentRecurrentController;

/**
 * Payment method controller for stripe credit card payments.
 */
class CreditCardController extends \PaymentMethodController implements PaymentRecurrentController {

  public $controller_data_defaults = [
    'private_key' => '',
    'public_key'  => '',
    'webhook_key' => '',
    'input_settings' => [],
    'enable_recurrent_payments' => 1,
  ];

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
   *
   * @return CreditCardForm
   *   A new credit card form.
   */
  public function paymentForm() {
    return new CreditCardForm();
  }

  /**
   * Get a form for configuring the payment method.
   *
   * @return CreditCardConfigurationForm
   *   A new credit card configuration form.
   */
  public function configurationForm() {
    return new CreditCardConfigurationForm();
  }

  /**
   * Check whether this payment method is available for a payment.
   *
   * @param \Payment $payment
   *   The payment to validate.
   * @param \PaymentMethod $method
   *   The payment method to check against.
   * @param bool $strict
   *   Whether to validate everything a payment for this method needs.
   *
   * @throws PaymentValidationException
   */
  public function validate(\Payment $payment, \PaymentMethod $method, $strict) {
    parent::validate($payment, $method, $strict);

    if (!($library = libraries_detect('stripe-php')) || empty($library['installed'])) {
      throw new \PaymentValidationException(t('The stripe-php library could not be found.'));
    }
    if (version_compare($library['version'], '3', '<')) {
      throw new \PaymentValidationException(t('stripe_payment needs at least version 3 of the stripe-php library (installed: @version).', ['@version' => $library['version']]));
    }

    if (!$strict) {
      return;
    }

    list($one_off, $recurring) = Utils::splitRecurring($payment);
    if ($recurring->line_items && empty($method->controller_data['enable_recurrent_payments'])) {
      throw new \PaymentValidationException(t('Recurrent payments are disabled for this payment method.'));
    }
    if (!$recurring->line_items && !((int) $one_off->totalAmount(TRUE) > 0)) {
      throw new \PaymentValidationException(t('Stripe requires a positive integer as total amount.'));
    }
    foreach (array_values($recurring->line_items) as $line_item) {
      if (!((int) $line_item->totalAmount(TRUE) > 0)) {
        throw new \PaymentValidationException(t('Recurrent line-items require a positive integer as total amount.'));
      }
    }
  }

  /**
   * Execute the payment transaction.
   *
   * @param \Payment $payment
   *   The payment to execute.
   *
   * @return bool
   *   Whether the payment was successfully executed or not.
   */
  public function execute(\Payment $payment) {
    $payment->setStatus(new \PaymentStatusItem(STRIPE_PAYMENT_STATUS_ACCEPTED));

    $api = Api::init($payment->method);
    $intent = $api->retrieveIntent($payment->method_data['stripe_id']);
    $payment->stripe = [
      'stripe_id' => $intent->id,
      'type'      => $intent->object,
    ];
    entity_save('payment', $payment);

    // Make subscriptions for recurrent payments.
    list($one_off, $recurring) = Utils::splitRecurring($payment);
    if ($recurring->line_items) {
      $customer = $api->createCustomer($intent, $payment->method_data['customer']);
      foreach (Utils::generateSubscriptions($recurring) as $subscription_options) {
        $subscription = $api->createSubscription(['customer' => $customer->id] + $subscription_options);
        db_insert('stripe_payment_subscriptions')
          ->fields([
            'pid' => $payment->pid,
            'stripe_id' => $subscription->id,
            'plan' => $subscription->plan->id,
            'amount' => $subscription->plan->amount * $subscription->quantity / 100,
            'billing_cycle_anchor' => $subscription->billing_cycle_anchor,
          ])->execute();
      }
    }
    return TRUE;
  }

}
