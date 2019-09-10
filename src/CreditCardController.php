<?php

namespace Drupal\stripe_payment;

class CreditCardController extends \PaymentMethodController implements \Drupal\webform_paymethod_select\PaymentRecurrentController {
  public $controller_data_defaults = array(
    'private_key' => '',
    'public_key'  => '',
    'webhook_key' => '',
    'field_map' => [],
    'enable_recurrent_payments' => 1,
  );

  public function __construct() {
    $this->title = t('Stripe Credit Card');

    $this->payment_configuration_form_elements_callback = 'payment_forms_payment_form';
    $this->payment_method_configuration_form_elements_callback = 'payment_forms_method_configuration_form';
  }

  public function paymentForm() {
    return new CreditCardForm();
  }

  public function configurationForm() {
    return new CreditCardConfigurationForm();
  }

  /**
   * {@inheritdoc}
   */
  function validate(\Payment $payment, \PaymentMethod $method, $strict) {
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

  public function execute(\Payment $payment) {
    $payment->setStatus(new \PaymentStatusItem(STRIPE_PAYMENT_STATUS_ACCEPTED));
    entity_save('payment', $payment);

    $api_key = $payment->method->controller_data['private_key'];
    $intent_id = $payment->method_data['stripe_id'];

    libraries_load('stripe-php');
    \Stripe\Stripe::setApiKey($api_key);
    $intent = $this->retrieveIntent($intent_id);

    // Save one off payment record.
    if ($intent->object == 'payment_intent') {
      $payment->stripe = $this->createRecord($payment->pid, $intent);
    }

    // Save recurrent payment record.
    if ($recurring_items = $this->filterRecurringLineItems($payment)) {
      $customer = $this->createCustomer($intent, $payment->contextObj);
      $currency = $payment->currency_code;
      foreach ($recurring_items as $name => $line_item) {
        // Since we have a payment date per line item and Stripe per subscription
        // lets create a new subscription (with only 1 plan) for each line item.
        $subscription = $this->createSubscription($line_item, $customer, $currency);
        $subscription_item = reset($subscription->items->data);
        $payment->stripe = $this->createRecord($payment->pid, $subscription, $subscription_item->plan->id);
      }
    }
    entity_save('payment', $payment);
  }

  public function createRecord($pid, $stripe, $plan_id = null) {
    return [
      'stripe_id' => $stripe->id,      // subscription id (sub_) or payment intent id (pi_)
      'type'      => $stripe->object,  // "subscription" or "payment_intent"
      'plan_id'   => $plan_id,
    ];
  }

  public function createCustomer($intent, $context) {
    return \Stripe\Customer::create([
      'payment_method' => $intent->payment_method,
      'invoice_settings' => [
        'default_payment_method' => $intent->payment_method,
      ],
      // TODO: Use CreditCardForm::mappedFields()
      'name' => $this->getName($context),
      'email' => $context->value('email'),
      ],
    ]);
  }

  public function getTotalAmount(\Payment $payment) {
    // convert amount to cents. Integer value.
    return (int) ($payment->totalAmount(0) * 100);
  }

  public function createIntent($payment) {
    // PaymentIntent: make a payment immediately
    if ($this->filterRecurringLineItems($payment, FALSE)) {
      return \Stripe\PaymentIntent::create([
        'amount'   => $this->getTotalAmount($payment),
        'currency' => $payment->currency_code
      ]);
    }
    // SetupIntent: save card details for later use without initial payment
    if ($this->filterRecurringLineItems($payment, TRUE)) {
      return \Stripe\SetupIntent::create();
    }
    // TODO: What now? Exception for "nothing to pay for"?
  }

  public function retrieveIntent($id) {
    // Get a matching item via the API:
    // SetupIntent ids start with `seti_`, PaymentIntent ids with `pi_`
    if (strpos($id, 'seti') === 0) {
      return \Stripe\SetupIntent::retrieve($id);
    }
    return \Stripe\PaymentIntent::retrieve($id);
  }

  public function createSubscription($line_item, $customer, $currency) {
    $plan = $this->getPlan($line_item, $currency);
    $options = [
      'customer' => $customer->id,
      'prorate' => FALSE,  // start with the next full billing cycle
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
      $subscription = \Stripe\Subscription::create($options);
    }
    catch(\Stripe\Error\InvalidRequest $e) {
      if ($e->getStripeCode() !== 'resource_already_exists') {
        throw $e;
      }
      try {
        // Create a new plan assuming the product already exists.
        \Stripe\Plan::create($plan);
      }
      catch(\Stripe\Error\InvalidRequest $e) {
        if ($e->getStripeCode() !== 'resource_already_exists') {
          throw $e;
        }
        // Create a new plan together with a new product.
        $plan['product'] = [
          'id' => $plan['product'],
          'name' => $line_item->description,
        ];
        \Stripe\Plan::create($plan);
      }
      $subscription = \Stripe\Subscription::create($options);
    }
    return $subscription;
  }

  public function getPlan($line_item, $currency) {
    $interval = $line_item->recurrence->interval_unit;
    $interval_count = $line_item->recurrence->interval_value;
    $product_id = $line_item->name;
    $id = "$interval_count-$interval-$line_item->name-$currency";  // e.g. "1-monthly-donation-EUR"
    $description = "$interval_count $interval $line_item->description in $currency";  // e.g. "1 monthly Donation in EUR"

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

  public function getName($context) {
    return trim(
      $context->value('title') . ' ' .
      $context->value('first_name') . ' ' .
      $context->value('last_name')
    );
  }

  public function getStartDate($recurrence) {
    if (empty($recurrence->start_date) && empty($recurrence->month) && empty($recurrence->day_of_month)) {
      return null;
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
