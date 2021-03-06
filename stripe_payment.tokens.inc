<?php

/**
 * @file
 * Implement hooks for Drupal’s token system.
 */

use Drupal\stripe_payment\Api;

/**
 * Implements hook_token_info().
 */
function stripe_payment_token_info() {
  $info['tokens']['payment']['stripe-sepa-mandate-info'] = [
    'name' => t('SEPA mandate information (via stripe)'),
    'description' => t('This includes all the mandatory information needed for SEPA mandates: The last 4 digits of the account number, and the mandate reference.'),
  ];
  return $info;
}

/**
 * Implements hook_tokens().
 */
function stripe_payment_tokens($type, array $tokens, array $data = [], array $options = []) {
  if ($type != 'payment' || !($payment = $data['payment'] ?? NULL)) {
    return [];
  }
  if ($payment->method->controller->name != 'stripe_payment_sepa') {
    return [];
  }
  $replacements = [];
  if (($original = $tokens['stripe-sepa-mandate-info'] ?? NULL) && !empty($payment->stripe_sepa)) {
    $replacements[$original] = theme('stripe_payment_sepa_mandate_info', [
      'payment' => $payment,
    ]);
  }
  return $replacements;
}
