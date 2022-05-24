<?php

namespace Drupal\stripe_payment;

use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\InvalidRequestException;
use Stripe\Mandate;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Plan;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\WebhookEndpoint;

/**
 * Stripe API-wrapper.
 */
class Api {

  const API_VERSION = '2020-08-27';
  const MODULE_BRANCH = '7.x-1.x';
  const MODULE_URL = 'https://github.com/moreonion/stripe_payment';
  const PARTNER_KEY = 'pp_partner_FWt5K2Mb47nSUP';

  /**
   * Hardcoded module version number.
   *
   * @var string
   */
  protected static $version = NULL;

  /**
   * Get the stripe_payment module version.
   *
   * @return string
   *   The module’s version (eg. '7.x-1.0').
   */
  public static function getModuleVersion() {
    if (!static::$version) {
      $info = unserialize(db_select('system', 's')
        ->fields('s', ['info'])
        ->condition('name', 'stripe_payment')
        ->execute()
        ->fetchField());
      static::$version = $info['version'] ?? static::MODULE_BRANCH;
    }
    return static::$version;
  }

  /**
   * Load the library and set global settings.
   *
   * @param \PaymentMethod $method
   *   A Stripe payment method.
   *
   * @return Api
   *   A new Api instance.
   */
  public static function init(\PaymentMethod $method) {
    // This is a simple way to inject mock API objects during testing.
    if (!empty($method->api)) {
      return $method->api;
    }
    libraries_load('stripe-php');
    Stripe::setApiVersion(static::API_VERSION);
    Stripe::setAppInfo('drupal/stripe-payment', static::getModuleVersion(), static::MODULE_URL, static::PARTNER_KEY);
    Stripe::setApiKey($method->controller_data['private_key']);
    return new static();
  }

  /**
   * Create a new payment/setup intent using the API.
   *
   * @param \Payment $payment
   *   The payment for which to create an intent.
   *
   * @return \Stripe\PaymentIntent|\Stripe\SetupIntent
   *   A payment intent for one off (or one off + reccurring) payments or
   *   a setup intent if there are only recurring line items.
   */
  public function createIntent(\Payment $payment) {
    $settings = $payment->method->controller->intentSettings;
    $settings['metadata'] = Utils::metadata($payment);
    list($one_off, $recurring) = Utils::splitRecurring($payment);
    // PaymentIntent: Make a payment immediately.
    if ($one_off->line_items) {
      return PaymentIntent::create([
        'amount'   => (int) ($one_off->totalAmount(TRUE) * 100),
        'currency' => $payment->currency_code,
        'setup_future_usage' => $recurring->line_items ? 'off_session' : NULL,
      ] + $settings);
    }
    // SetupIntent: Save card details for later use without initial payment.
    return SetupIntent::create($settings);
  }

  /**
   * Get a payment intent by its ID from the API.
   *
   * @param string $id
   *   The intent ID.
   * @param string[] $expand
   *   Array of sub-element keys that should be expanded.
   *
   * @return \Stripe\PaymentIntent|\Stripe\SetupIntent
   *   The intent identified by the ID.
   */
  public function retrieveIntent($id, array $expand = []) {
    // Get a matching item via the API:
    // SetupIntent ids start with `seti_`, PaymentIntent ids with `pi_`.
    $class = strpos($id, 'seti') === 0 ? SetupIntent::class : PaymentIntent::class;
    return $class::retrieve([
      'id' => $id,
      'expand' => $expand,
    ]);
  }

  /**
   * Retrieve data about a mandate by its ID.
   *
   * @param string $id
   *   The mandate ID.
   */
  public function retrieveMandate($id) {
    return Mandate::retrieve($id);
  }

  /**
   * Retrieve a payment method by its ID.
   */
  public function retrievePaymentMethod($id) {
    return PaymentMethod::retrieve($id);
  }

  /**
   * Create a new customer using the API.
   *
   * @param string $payment_method_id
   *   The id of the customer’s payment method.
   * @param \Payment $payment
   *   Payment object containing additional data about the customer.
   *
   * @return \Stripe\Customer
   *   The customer.
   */
  public function createCustomer(string $payment_method_id, \Payment $payment) {
    return Customer::create([
      'payment_method' => $payment_method_id,
      'invoice_settings' => [
        'default_payment_method' => $payment_method_id,
      ],
      'metadata' => Utils::metadata($payment),
    ] + $payment->method_data['customer']);
  }

  /**
   * Create a new subscription based on a plan using the API.
   *
   * The plan and the product will be created too if they do not exist yet.
   *
   * @param array $options
   *   Array containing 'customer', 'subscription', 'plan' and 'product' data.
   *
   * @return \Stripe\Subscription
   *   The subscription.
   */
  public function createSubscription(array $options) {
    try {
      // Assuming the plan already exists.
      $subscription = Subscription::create([
        'customer' => $options['customer'],
        'metadata' => $options['metadata'],
      ] + $options['subscription']);
    }
    catch (InvalidRequestException $e) {
      if ($e->getStripeCode() !== 'resource_missing') {
        throw $e;
      }
      try {
        // Create a new plan assuming the product already exists.
        Plan::create($options['plan']);
      }
      catch (InvalidRequestException $e) {
        if ($e->getStripeCode() !== 'resource_missing') {
          throw $e;
        }
        // Create a new plan together with a new product.
        $options['plan']['product'] = $options['product'];
        Plan::create($options['plan']);
      }
      $subscription = Subscription::create([
        'customer' => $options['customer'],
        'metadata' => $options['metadata'],
      ] + $options['subscription']);
    }
    return $subscription;
  }

  /**
   * Register a new webhook endpoint using the API.
   *
   * @param string $url
   *   The URL of the webhook endpoint.
   *
   * @return \Stripe\WebhookEndpoint
   *   The registered webhook endpoint.
   */
  public function registerWebhook(string $url) {
    return WebhookEndpoint::create([
      'url' => $url,
      'enabled_events' => [
        Event::SETUP_INTENT_SUCCEEDED,
        Event::SETUP_INTENT_SETUP_FAILED,
        Event::PAYMENT_INTENT_PAYMENT_FAILED,
        Event::PAYMENT_INTENT_SUCCEEDED,
      ],
    ]);
  }

  /**
   * Delete a webhook endpoint using the API.
   *
   * @param string $id
   *   The id of the webhook endpoint.
   */
  public function deleteWebhook(string $id) {
    $endpoint = WebhookEndpoint::retrieve($id);
    $endpoint->delete();
  }

}
