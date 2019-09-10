<?php

namespace Drupal\stripe_payment;

use Drupal\webform_paymethod_select\PaymentRecurrentController;
use Stripe\Customer;
use Stripe\Error\InvalidRequest;
use Stripe\PaymentIntent;
use Stripe\Plan;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\Subscription;

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

    $api_key = $payment->method->controller_data['private_key'];
    $intent_id = $payment->method_data['stripe_id'];

    libraries_load('stripe-php');
    Stripe::setApiKey($api_key);
    $intent = $this->retrieveIntent($intent_id);

    // Save one off payment record.
    if ($intent->object == 'payment_intent') {
      $payment->stripe = $this->createRecord($intent);
    }

    // Save recurrent payment record.
    if ($recurring_items = $this->filterRecurringLineItems($payment)) {
      $customer = $this->createCustomer($intent, $payment->contextObj);
      $currency = $payment->currency_code;
      foreach ($recurring_items as $name => $line_item) {
        // Since we have a date per line item and Stripe per subscription
        // lets create a new subscription (with only 1 plan) for each line item.
        $subscription = $this->createSubscription($line_item, $customer, $currency);
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
   * Create a new customer using the API.
   */
  public function createCustomer($intent, $context) {
    return Customer::create([
      'payment_method' => $intent->payment_method,
      'invoice_settings' => [
        'default_payment_method' => $intent->payment_method,
      ],
      // TODO: Use CreditCardForm::mappedFields()
      'name' => $this->getName($context),
      'email' => $context->value('email'),
    ]);
  }

  /**
   * Calculate the total amount.
   *
   * @return int
   *   Total amount converted to cents.
   */
  public function getTotalAmount(\Payment $payment) {
    return (int) ($payment->totalAmount(0) * 100);
  }

  /**
   * Create a new payment intent using the API.
   */
  public function createIntent($payment) {
    // PaymentIntent: Make a payment immediately.
    if ($this->filterRecurringLineItems($payment, FALSE)) {
      return PaymentIntent::create([
        'amount'   => $this->getTotalAmount($payment),
        'currency' => $payment->currency_code,
      ]);
    }
    // SetupIntent: Save card details for later use without initial payment.
    if ($this->filterRecurringLineItems($payment, TRUE)) {
      return SetupIntent::create();
    }
    // TODO: What now? Exception for "nothing to pay for"?
  }

  /**
   * Get a payment intent by its ID from the API.
   */
  public function retrieveIntent($id) {
    // Get a matching item via the API:
    // SetupIntent ids start with `seti_`, PaymentIntent ids with `pi_`.
    if (strpos($id, 'seti') === 0) {
      return SetupIntent::retrieve($id);
    }
    return PaymentIntent::retrieve($id);
  }

  /**
   * Create a new subscription via the API.
   */
  public function createSubscription($line_item, $customer, $currency) {
    $plan = $this->getPlan($line_item, $currency);
    $options = [
      'customer' => $customer->id,
      // Start with the next full billing cycle.
      'prorate' => FALSE,
      'items' => [
        [
          'plan' => $plan['id'],
          'quantity' => $line_item->amount * $line_item->quantity,
        ],
      ],
    ];
    if ($start_date = $this->getStartDate($line_item->recurrence)) {
      $options['billing_cycle_anchor'] = $start_date->getTimestamp();
    }

    try {
      // Assuming the plan already exists.
      $subscription = Subscription::create($options);
    }
    catch (InvalidRequest $e) {
      if ($e->getStripeCode() !== 'resource_already_exists') {
        throw $e;
      }
      try {
        // Create a new plan assuming the product already exists.
        Plan::create($plan);
      }
      catch (InvalidRequest $e) {
        if ($e->getStripeCode() !== 'resource_already_exists') {
          throw $e;
        }
        // Create a new plan together with a new product.
        $plan['product'] = [
          'id' => $plan['product'],
          'name' => $line_item->description,
        ];
        Plan::create($plan);
      }
      $subscription = Subscription::create($options);
    }
    return $subscription;
  }

  /**
   * Generate data for a payment plan.
   */
  public function getPlan($line_item, $currency) {
    $interval = $line_item->recurrence->interval_unit;
    $interval_count = $line_item->recurrence->interval_value;
    $product_id = $line_item->name;
    // IDs look like "1-monthly-donation-EUR".
    $id = "$interval_count-$interval-$line_item->name-$currency";
    // Descriptions look like "1 monthly Donation in EUR".
    $description = "$interval_count $interval $line_item->description in $currency";

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

  /**
   * Get only recurring or non-recurring line items.
   */
  private function filterRecurringLineItems($payment, $recurrence = TRUE) {
    $filtered = [];
    foreach ($payment->line_items as $name => $line_item) {
      if ($line_item->quantity == 0) {
        continue;
      }
      if (!empty($line_item->recurrence->interval_unit) == $recurrence) {
        $filtered[$name] = $line_item;
      }
    }
    return $filtered;
  }

}
