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
    list($one_off, $recurring) = $this->splitRecurring($payment);
    if ($recurring->line_items) {
      $customer = $api->createCustomer($intent, [
        // TODO: Use CreditCardForm::mappedFields()
        'name' => $this->getName($payment->contextObj),
        'email' => $payment->contextObj->value('email'),
      ]);
      $currency = $payment->currency_code;
      foreach ($recurring->line_items as $name => $line_item) {
        // Since we have a date per line item and Stripe per subscription
        // lets create a new subscription (with only 1 plan) for each line item.
        $plan = $this->getPlan($line_item, $currency);
        $options = $this->subscriptionOptions($customer, $plan, $line_item);
        $subscription = $api->createSubscription($options, $plan, $line_item);
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

  /**
   * Generate subscription options.
   */
  protected function subscriptionOptions($customer, $plan, \PaymentLineItem $line_item) {
    $options['customer'] = $customer->id;
    // Start with the next full billing cycle.
    $options['prorate'] = FALSE;
    $options['items'][] = [
      'plan' => $plan['id'],
      'quantity' => $line_item->totalAmount(TRUE),
    ];
    if ($start_date = $this->getStartDate($line_item->recurrence)) {
      $options['billing_cycle_anchor'] = $start_date->getTimestamp();
    }
    return $options;
  }

  /**
   * Generate data for a payment plan.
   */
  public function getPlan($line_item, $currency) {
    $interval = $line_item->recurrence->interval_unit;
    $interval_count = $line_item->recurrence->interval_value;
    $product_id = $line_item->name;
    // IDs look like "1-monthly-donation-EUR".
    $id = "$interval_count-$interval-{$line_item->name}-$currency";
    // Descriptions look like "1 monthly Donation in EUR".
    $description = "$interval_count $interval {$line_item->description} in $currency";

    return [
      'id' => $id,
      'amount' => 100,
      'currency' => $currency,
      'interval' => rtrim($interval, 'ly'),
      'interval_count' => $interval_count,
      'nickname' => $description,
      'product' => $product_id,
    ];
  }

  /**
   * Generat the customer name.
   */
  public function getName($context) {
    return trim(
      $context->value('title') . ' ' .
      $context->value('first_name') . ' ' .
      $context->value('last_name')
    );
  }

  /**
   * Calculate the start date for a recurring payment.
   */
  public function getStartDate($recurrence) {
    if (empty($recurrence->start_date) && empty($recurrence->month) && empty($recurrence->day_of_month)) {
      return NULL;
    }
    // Earliest possible start date.
    $earliest = $recurrence->start_date ?? new \DateTime('tomorrow', new \DateTimeZone('UTC'));
    // Date meeting day of month and month requirements.
    $y = $earliest->format('Y');
    $m = $recurrence->month ?? $earliest->format('m');
    $d = $recurrence->day_of_month ?? $earliest->format('d');
    $date = new \DateTime($y . $m . $d, new \DateTimeZone('UTC'));
    // Find the first matching date after the earliest.
    $unit = rtrim($recurrence->interval_unit, 'ly');
    $count = $recurrence->interval_value ?? 1;
    while ($date < $earliest) {
      $date->modify("$count $unit");
    }
    return $date;
  }

}
