<?php

namespace Drupal\stripe_payment;

/**
 * Helper functions for dealing with payments.
 */
abstract class Utils {

  /**
   * Split a payment into one with only one-off payments and the rest.
   *
   * @param \Payment $payment
   *   The payment to split.
   *
   * @return \Payment[]
   *   An array with two items:
   *   1. A payment containing only the one-off line-items of the original.
   *   2. A payment containing all the recurring line-items of the original.
   */
  public static function splitRecurring(\Payment $payment) {
    $items = [FALSE => [], TRUE => []];
    foreach ($payment->line_items as $name => $line_item) {
      $is_recurring = !empty($line_item->recurrence->interval_unit);
      $items[$is_recurring][$name] = $line_item;
    }
    $common = ['currency_code' => $payment->currency_code];
    return [
      new \Payment(['line_items' => $items[FALSE]] + $common),
      new \Payment(['line_items' => $items[TRUE]] + $common),
    ];
  }

  /**
   * Get subscription options for a list of line items.
   *
   * @param \Payment $payment
   *   A payment containing solely recurring line items.
   *
   * @return array[]
   *   An array of arrays containing 'subscription', 'plan' and 'product' data.
   */
  public static function generateSubscriptions(\Payment $payment) {
    $options = [];
    // Since we have a date per line item and Stripe per subscription
    // lets create a new subscription (with only 1 plan) for each line item.
    foreach (array_values($payment->line_items) as $line_item) {
      $subscription = self::subscriptionData($line_item);
      $plan = self::planData($line_item, $payment->currency_code);
      $product = self::productData($line_item);
      // Add product to plan.
      $plan['product'] = $product['id'];
      // Add plan to subscription.
      $subscription['items'][] = [
        'plan' => $plan['id'],
        'quantity' => $line_item->totalAmount(TRUE),
      ];
      $options[] = [
        'subscription' => $subscription,
        'plan' => $plan,
        'product' => $product,
      ];
    }
    return $options;
  }

  /**
   * Generate data for a product.
   *
   * @param \PaymentLineItem $line_item
   *   A recurring line item.
   *
   * @return array
   *   A product data array.
   */
  public static function productData(\PaymentLineItem $line_item) {
    return [
      'id' => $line_item->name,
      'name' => $line_item->description,
    ];
  }

  /**
   * Generate data for a payment plan.
   *
   * @param \PaymentLineItem $line_item
   *   A recurring line item.
   * @param string $currency
   *   An ISO 4217 currency code.
   *
   * @return array
   *   A stub plan data array (add 'product').
   */
  public static function planData(\PaymentLineItem $line_item, string $currency) {
    $interval = $line_item->recurrence->interval_unit;
    $interval_count = $line_item->recurrence->interval_value;
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
    ];
  }

  /**
   * Generate data for a subscription.
   *
   * @param \PaymentLineItem $line_item
   *   A recurring line item.
   *
   * @return array
   *   A stub subscription data array (add 'customer' and 'items').
   */
  public static function subscriptionData(\PaymentLineItem $line_item) {
    // Start with the next full billing cycle.
    $options['prorate'] = FALSE;
    if ($start_date = self::getStartDate($line_item)) {
      $options['billing_cycle_anchor'] = $start_date->getTimestamp();
    }
    return $options;
  }

  /**
   * Calculate the subscription start date for a recurring line item.
   *
   * @param \PaymentLineItem $line_item
   *   A recurring line item.
   *
   * @return \DateTime|null
   *   The calculated start date or
   *   `null` if the line item doesn’t include any date settings.
   */
  public static function getStartDate(\PaymentLineItem $line_item) {
    $recurrence = $line_item->recurrence;
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
