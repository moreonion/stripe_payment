<?php

namespace Drupal\stripe_payment;

use Stripe\Customer;
use Stripe\Error\InvalidRequest;
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
   */
  public static function init(\PaymentMethod $method) {
    libraries_load('stripe-php');
    Stripe::setApiKey($method->controller_data['private_key']);
    return new static();
  }

  /**
   * Get a payment intent by its ID from the API.
   *
   * @param string $id
   *   The indent ID.
   *
   * @return \Stripe\PaymentIndent|\Stripe\SetupIndent
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
   * Create a new subscription based on a plan and create the product if needed.
   */
  public function createSubscription(array $options, array $plan, \PaymentLineItem $line_item) {
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
   * Register a new webhook endpoint.
   */
  public function registerWebhook($url) {
    return WebhookEndpoint::create([
      'url' => $url,
      'enabled_events' => [
        'setup_intent.succeeded',
        'setup_intent.setup_failed',
        'payment_intent.payment_failed',
        'payment_intent.succeeded',
      ],
      'api_version' => '2019-08-14',  // otherwise the account’s default API version will be used.
    ]);
  }

}
