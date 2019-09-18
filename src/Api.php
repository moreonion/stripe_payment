<?php

namespace Drupal\stripe_payment;

use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\Plan;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\WebhookEndpoint;

/**
 * Stripe API-wrapper.
 */
class Api {

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
    libraries_load('stripe-php');
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
    list($one_off, $recurring) = Utils::splitRecurring($payment);
    // PaymentIntent: Make a payment immediately.
    if ($one_off->line_items) {
      return PaymentIntent::create([
        'amount'   => (int) ($one_off->totalAmount(TRUE) * 100),
        'currency' => $payment->currency_code,
      ]);
    }
    // SetupIntent: Save card details for later use without initial payment.
    return SetupIntent::create();
  }

  /**
   * Get a payment intent by its ID from the API.
   *
   * @param string $id
   *   The intent ID.
   *
   * @return \Stripe\PaymentIntent|\Stripe\SetupIntent
   *   The intent identified by the ID.
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
   * Create a new customer using the API.
   *
   * @param \Stripe\PaymentIntent|\Stripe\SetupIntent $intent
   *   The intent object with the customers payment data.
   * @param array $extra_data
   *   Additional data about the customer.
   *
   * @return \Stripe\Customer
   *   The customer.
   */
  public function createCustomer($intent, array $extra_data) {
    return Customer::create([
      'payment_method' => $intent->payment_method,
      'invoice_settings' => [
        'default_payment_method' => $intent->payment_method,
      ],
    ] + $extra_data);
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
      $subscription = Subscription::create(['customer' => $options['customer']] + $options['subscription']);
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
        $plan['product'] = $options['product'];
        Plan::create($plan);
      }
      $subscription = Subscription::create($options['subscription']);
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
      // If not specified the account’s default API version will be used.
      'api_version' => '2019-08-14',
    ]);
  }

}
