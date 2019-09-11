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
    $common = ['currency' => $payment->currency];
    return [
      new \Payment(['line_items' => $items[FALSE]] + $common),
      new \Payment(['line_items' => $items[TRUE]] + $common),
    ];
  }

}
