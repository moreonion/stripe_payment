<?php

namespace Drupal\stripe_payment;

use Drupal\webform_paymethod_select\PaymentRecurrentController;
use Stripe\Exception\ApiErrorException;

/**
 * Payment method controller for stripe credit card payments.
 */
class StripeController extends \PaymentMethodController implements PaymentRecurrentController {

  public $controller_data_defaults = [
    'private_key' => '',
    'public_key'  => '',
    'webhook_key' => '',
    'input_settings' => [],
    'enable_recurrent_payments' => 1,
  ];

  public $intentSettings = [];

  /**
   * Create a new controller instance.
   */
  public function __construct() {
    $this->payment_configuration_form_elements_callback = 'payment_forms_payment_form';
    $this->payment_method_configuration_form_elements_callback = 'payment_forms_method_configuration_form';
  }

  /**
   * Get a form for configuring the payment method.
   *
   * @return StripeConfigurationForm
   *   A new Stripe configuration form.
   */
  public function configurationForm() {
    return new StripeConfigurationForm();
  }

  /**
   * Get a customer data form.
   *
   * @return CustomerDataForm
   *   A new customer data form.
   */
  public function customerDataForm() {
    return new CustomerDataForm();
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
    $intent = $this->fetchIntent($payment, $api);
    entity_save('payment', $payment);
    $this->createSubscriptions($payment, $api, $intent);
  }

  /**
   * Load the intent object and populate the $payment object accordingly.
   */
  protected function fetchIntent(\Payment $payment, Api $api) {
    $intent = $api->retrieveIntent($payment->method_data['stripe_id']);
    $payment->stripe = [
      'stripe_id' => $intent->id,
      'type'      => $intent->object,
    ];
    return $intent;
  }

  /**
   * Create subscriptions for recurrent payments.
   */
  protected function createSubscriptions(\Payment $payment, Api $api, $intent) {
    list($one_off, $recurring) = Utils::splitRecurring($payment);
    if ($recurring->line_items) {
      try {
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
      catch (\UnexpectedValueException $e) {
        watchdog_exception('stripe_payment', $e, 'Impossible recurrence constraints.', [], WATCHDOG_ERROR);
        $payment->setStatus(new \PaymentStatusItem(PAYMENT_STATUS_FAILED));
        entity_save('payment', $payment);
        return FALSE;
      }
      catch (ApiErrorException $e) {
        $message = 'Stripe API error for recurrent payment (pmid: @pmid). @description.';
        $variables = ['@description' => $e->getMessage(), '@pmid' => $payment->method->pmid];
        watchdog('stripe_payment', $message, $variables, WATCHDOG_WARNING);
        $payment->setStatus(new \PaymentStatusItem(PAYMENT_STATUS_FAILED));
        entity_save('payment', $payment);
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Create a payment intent if this wasn’t done already.
   */
  public function ajaxCallback(\Payment $payment) {
    $api = Api::init($payment->method);
    if (empty($payment->stripe['stripe_id'])) {
      $intent = $api->createIntent($payment);
      $payment->stripe = [
        'stripe_id' => $intent->id,
        'type' => $intent->object,
      ];
      entity_save('payment', $payment);
    }
    else {
      $intent = $api->retrieveIntent($payment->stripe['stripe_id']);
    }
    return [
      'client_secret' => $intent->client_secret,
      'type' => $intent->object,
      'methods' => $intent->payment_method_types,
    ];
  }

}
