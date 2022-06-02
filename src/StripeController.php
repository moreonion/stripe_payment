<?php

namespace Drupal\stripe_payment;

use Stripe\Exception\ApiErrorException;

/**
 * Payment method controller for stripe credit card payments.
 */
class StripeController extends \PaymentMethodController {

  /**
   * Default values for the controller configuration.
   *
   * @var array
   */
  public $controller_data_defaults = [
    'private_key' => '',
    'public_key'  => '',
    'webhook_key' => '',
    'input_settings' => [],
    'enable_recurrent_payments' => 1,
  ];

  /**
   * Settings for the Stripe intent.
   *
   * @var array
   */
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
    if (empty($payment->method_data['stripe_id'])) {
      watchdog('stripe_payment', 'A payment was submitted without an intent.', [], WATCHDOG_WARNING);
      $payment->setStatus(new \PaymentStatusItem(STRIPE_PAYMENT_STATUS_NO_INTENT));
      return FALSE;
    }
    $payment->setStatus(new \PaymentStatusItem(STRIPE_PAYMENT_STATUS_ACCEPTED));

    $subscriptions = db_query(
      'SELECT stripe_id from stripe_payment_subscriptions WHERE pid=:pid',
      [':pid' => $payment->pid]
    )->fetchCol();
    if ($subscriptions) {
      entity_save('payment', $payment);
      return TRUE;
    }

    $api = Api::init($payment->method);
    $intent = $this->fetchIntent($payment, $api);
    entity_save('payment', $payment);

    // The `payment_method` is expanded for sepa but not credit card payments.
    $stripe_pm = $intent->payment_method->id ?? $intent->payment_method;
    $subscriptions = $this->createSubscriptions($payment, $api, $stripe_pm);
    return $subscriptions['success'];
  }

  /**
   * Load the intent object and populate the $payment object accordingly.
   */
  protected function fetchIntent(\Payment $payment, Api $api, array $expand = []) {
    $intent = $api->retrieveIntent($payment->method_data['stripe_id'], $expand);
    $payment->stripe = [
      'stripe_id' => $intent->id,
      'type'      => $intent->object,
    ];
    return $intent;
  }

  /**
   * Create subscriptions for recurrent payments.
   */
  protected function createSubscriptions(\Payment $payment, Api $api, string $stripe_pm) {
    $intent = NULL;
    list($one_off, $recurring) = Utils::splitRecurring($payment);
    if ($recurring->line_items) {
      try {
        $customer = $api->createCustomer($stripe_pm, $payment);
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
          // If there’s no intent yet, try to get one from the subscriptions.
          if (empty($payment->stripe['stripe_id'])) {
            $intent = $subscription->latest_invoice->payment_intent ?? $subscription->pending_setup_intent;
            if ($intent) {
              $payment->stripe = [
                'stripe_id' => $intent->id,
                'type' => $intent->object,
              ];
              entity_save('payment', $payment);
            }
          }
        }
      }
      catch (\UnexpectedValueException $e) {
        watchdog_exception('stripe_payment', $e, 'Impossible recurrence constraints.', [], WATCHDOG_ERROR);
        $payment->setStatus(new \PaymentStatusItem(PAYMENT_STATUS_FAILED));
        entity_save('payment', $payment);
        return ['success' => FALSE];
      }
      catch (ApiErrorException $e) {
        $message = 'Stripe API error for recurrent payment (pmid: @pmid). @description.';
        $variables = [
          '@description' => $e->getMessage(),
          '@pmid' => $payment->method->pmid,
        ];
        watchdog('stripe_payment', $message, $variables, WATCHDOG_WARNING);
        $payment->setStatus(new \PaymentStatusItem(PAYMENT_STATUS_FAILED));
        entity_save('payment', $payment);
        return ['success' => FALSE];
      }
    }
    return ['success' => TRUE, 'intent' => $intent];
  }

  /**
   * Create a payment intent if needed.
   */
  public function ajaxCallback(\Payment $payment, array $form, array $form_state) {
    $api = Api::init($payment->method);
    // Save payment to make sure pid is set.
    entity_save('payment', $payment);
    // A payment intent has already been created, we can fetch it again.
    if (!empty($payment->stripe['stripe_id'])) {
      $intent = $api->retrieveIntent($payment->stripe['stripe_id']);
    }
    // A payment method was created client side, this means we have to create a
    // subscription first and then use the intent that comes with it.
    elseif ($stripe_pm = $form_state['input']['stripe_pm'] ?? NULL) {
      // Add customer data from form.
      if (!isset($payment->method_data['customer'])) {
        $customer_data_form = $payment->method->controller->customerDataForm();
        $payment->method_data['customer'] = $customer_data_form->getData($form);
      }
      // Create subscription – returns an intent if confirmation is needed.
      $subscriptions = $this->createSubscriptions($payment, $api, $stripe_pm);
      if ($subscriptions['success']) {
        $intent = $subscriptions['intent'];
      }
      else {
        return ["error" => ["message" => t("Payment failed.")]];
      }
    }
    // Create a new intent for the payment.
    else {
      $intent = $api->createIntent($payment);
      $payment->stripe = [
        'stripe_id' => $intent->id,
        'type' => $intent->object,
      ];
      entity_save('payment', $payment);
    }
    return [
      'client_secret' => $intent->client_secret ?? NULL,
      'type' => $intent->object ?? 'no_intent',
      'methods' => $intent->payment_method_types ?? [],
      'needs_confirmation' => isset($intent),
    ];
  }

  /**
   * Column headers for webform data.
   */
  public function webformDataInfo() {
    $info['transaction_id'] = t('Transaction ID');
    return $info;
  }

  /**
   * Data for webform results.
   */
  public function webformData(\Payment $payment) {
    $data['transaction_id'] = $payment->stripe['stripe_id'];
    return $data;
  }

}
