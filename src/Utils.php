<?php

namespace Drupal\stripe_payment;

/**
 * Helper functions for dealing with payments.
 */
abstract class Utils {

  /**
   * Check if a payment method uses a Stripe Payment controller.
   *
   * @param \PaymentMethod $method
   *   The payment to check.
   *
   * @return bool
   *   Whether the payment method uses a Stripe Payment controller.
   */
  public static function isStripeMethod(\PaymentMethod $method) {
    $controller = array_values(stripe_payment_payment_method_controller_info());
    foreach (stripe_payment_payment_method_controller_info() as $name => $controller) {
      if ($method->controller instanceof $controller) {
        return TRUE;
      }
    }
    return FALSE;
  }

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
      'statement_descriptor' => self::getStatementDescriptor($line_item->description),
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
    $options = [
      // Expect no more user interaction.
      'off_session' => TRUE,
      'payment_behavior' => 'error_if_incomplete',
      // Start with the next full billing cycle.
      'prorate' => FALSE,
    ];
    if ($start_date = self::getStartDate($line_item)) {
      $options['billing_cycle_anchor'] = $start_date->getTimestamp();
    }
    return $options;
  }

  /**
   * Calculate the subscription start date for a recurring line item.
   *
   * Stripe has no direct support for recurring dates calculated from the end
   * of a month (negative day_of_month values). As a workaround we require them
   * to start in a 31-day month.
   *
   * @param \PaymentLineItem $line_item
   *   A recurring line item.
   * @param \DateTimeImmutable $now
   *   The date and time considered to be now, defaults to now in UTC.
   *
   * @return \DateTime|null
   *   The calculated start date or
   *   `null` if the line item doesn’t include any date settings.
   */
  public static function getStartDate(\PaymentLineItem $line_item, \DateTimeImmutable $now = NULL) {
    $recurrence = $line_item->recurrence;
    if (empty($recurrence->start_date) && empty($recurrence->month) && empty($recurrence->day_of_month)) {
      return NULL;
    }
    $now = $now ?? new \DateTimeImmutable('', new \DateTimeZone('UTC'));
    $interval_unit = rtrim($recurrence->interval_unit, 'ly');
    $interval_value = $recurrence->interval_value ?? 1;
    $threshold = $now->modify("+$interval_value $interval_unit");
    // Earliest possible date, either tomorrow or future recurrence start date.
    $earliest = max($now->modify('+1 day'), $recurrence->start_date ?? NULL);
    $earliest = $earliest instanceof \DateTime ? \DateTimeImmutable::createFromMutable($earliest) : $earliest;
    $day_of_month = $recurrence->day_of_month ?? NULL;
    // Deal with negative day_of_month values.
    $offset_days = NULL;
    if ($day_of_month < 0) {
      // If we are calculating from the month’s end we use the next month’s 1st
      // instead and then add the difference as buffer to earliest so we can
      // subtract it from the result in the end.
      $offset_days = abs($day_of_month);
      $day_of_month = 1;
      $earliest = $earliest->modify("+$offset_days day");
    }
    if (in_array($interval_unit, ['month', 'year'])) {
      $month = $recurrence->month ?? NULL;
      $interval = $interval_unit == 'year' ? 12 : $interval_value ?? 1;
      $meets_constraints = function (\DateTimeImmutable $date) use ($day_of_month, $month, $interval) {
        return (!$day_of_month || $date->format('d') == $day_of_month || ($date->modify('+1 day')->format('d') == 1 && $date->format('d') < $day_of_month))
          && (!$month || $date->format('m') % $interval == $month % $interval);
      };
    }
    else {
      $meets_constraints = function (\DateTimeImmutable $date) {
        return TRUE;
      };
    }
    // Start at the earliest possible date and look for one date matching all
    // the constraints.
    $date = $earliest;
    while (!$meets_constraints($date)) {
      $date = $date->modify('+1 day');
      if ($date > $threshold) {
        throw new \UnexpectedValueException('Unable to find a suitable start date for the given constraints.');
      }
    }
    return $offset_days ? $date->modify("-$offset_days day") : $date;
  }

  /**
   * Prepare a string for use as statement descriptor.
   *
   * - Contains only ASCII characters (only if transliteration is enabled).
   * - Does not contain <, >, \, ", '.
   * - Has a maximum of 22 characters.
   *
   * @param string $s
   *   The string to modify.
   *
   * @return string|null
   *   The safe string or `null` if the string is too long.
   */
  public static function getStatementDescriptor(string $s) {
    // Transliterate non-ASCII characters if the transliteration module is enabled,
    // otherwise Stripe will strip them or use similar looking characters (ä→a).
    if (function_exists('transliteration_get')) {
      $s = transliteration_get($s);
    }
    // Remove characters not allowed in bank statements: <, >, \, ", '.
    $s = preg_replace('(<|>|\\\\|"|\')', '', $s);
    // Only return strings with a max length of 22 characters.
    // (Stripe would not accept a longer string and cutting it off after 22 characters
    // might produce a weird result on the subscriber’s bank statement.)
    return strlen($s) > 22 ? NULL : $s;
  }

}
