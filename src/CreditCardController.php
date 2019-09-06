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

    $context = $payment->contextObj;
    $api_key = $payment->method->controller_data['private_key'];

    libraries_load('stripe-php');
    \Stripe\Stripe::setApiKey($api_key);

    $intent_id = $payment->method_data['stripe_id'];
    $stripe = $this->retrieveIntent($intent_id);
    $plan_id = NULL;

    if ($intent->object == 'payment_intent') {
      // save payment
      $params = array(
        'pid'       => $payment->pid,
        'stripe_id' => $stripe->id,      // payment intent id (pi_)
        'type'      => $stripe->object,  // "payment_intent"
        'plan_id'   => $plan_id,
      );
      drupal_write_record('stripe_payment', $params);
    }

    if ($recurring_items = $this->filterRecurringLineItems($payment)) {
      $customer = $this->createCustomer($stripe, $context);
      $currency = $payment->currency_code;
      foreach ($recurring_items as $name => $line_item) {
        $plan_id = $this->createPlan($customer, $line_item, $currency);
        $stripe  = $this->createSubscription($customer, $plan_id);
        // save subscription
        $params = array(
          'pid'       => $payment->pid,
          'stripe_id' => $stripe->id,      // subscription id (sub_)
          'type'      => $stripe->object,  // "subscription"
          'plan_id'   => $plan_id,
        );
        drupal_write_record('stripe_payment', $params);
      }
    }
  }

  public function createCustomer($intent, $context) {
    return \Stripe\Customer::create([
      'payment_method' => $intent->payment_method,
      'name' => $this->getName($context),
      'email' => $context->value('email'),
      // TODO: 'address' => [],
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

  public function createPlan($customer, $line_item, $currency) {
    $amount = (int) ($line_item->amount * 100);
    $interval = $line_item->recurrence->interval_unit;
    $description = ($amount/100) . ' ' . $currency . ' / ' . $interval;

    $existing_id = db_select('stripe_payment_plans', 'p')
      ->fields('p', array('id'))
      ->condition('payment_interval', $interval)
      ->condition('amount', $amount)
      ->condition('currency', $currency)
      ->execute()
      ->fetchField();

    if ($existing_id) {
      return $existing_id;
    } else {
      $params = array(
        'id'       => $description,
        'amount'   => $amount,
        'payment_interval' => $interval,
        'nickname' => 'donates ' . $description,
        'currency' => $currency,
      );
      drupal_write_record('stripe_payment_plans', $params);
      // TODO: add product (required parameter!)

      // This ugly hack is necessary because 'interval' is a reserved keyword
      // in mysql and drupal does not enclose the field names in '"'.
      $params['interval'] = $params['payment_interval'];
      unset($params['payment_interval']);
      unset($params['pid']);
      // TODO: find out where $params['name'] comes from and eliminate
      unset($params['name']);
      return \Stripe\Plan::create($params)->id;
    }
  }

  public function createSubscription($customer, $plan_id) {
    return $customer->subscriptions->create(array('plan' => $plan_id));
  }

  public function getName($context) {
    return trim(
      $context->value('title') . ' ' .
      $context->value('first_name') . ' ' .
      $context->value('last_name')
    );
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
