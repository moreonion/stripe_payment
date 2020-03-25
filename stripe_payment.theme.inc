<?php

/**
 * @file
 * Theme processing functions for stripe_payment.
 */

/**
 * Prepares variables for stripe iframe field templates.
 *
 * @param array $variables
 *   An associative array containing:
 *   - element: The rendered form API element.
 */
function template_process_stripe_payment_field(&$vars) {
  $vars['attributes'] = $vars['element']['#attributes'] ?? [];
  $vars['attributes'] += [
    'id' => $vars['element']['#id'] ?? drupal_html_id('stripe-payment-field'),
    'data-stripe-element' => $vars['element']['#field_name'],
  ];
  $vars['attributes']['class'][] = 'stripe-payment-field-iframe-wrapper';
  $vars['attributes']['class'][] = 'text-input';
}

/**
 * Prepares variables for the SEPA mandate info template.
 *
 * @param array $variables
 *   An associative array containing:
 *   - payment: The payment object for which the info is produced.
 */
function template_preprocess_stripe_payment_sepa_mandate_info(array &$variables) {
  $payment = $variables['payment'];
  $variables['creditor_id'] = $payment->method->controller_data['creditor_id'];
  $variables['mandate_reference'] = $payment->stripe_sepa['mandate_reference'];
  $variables['iban_last4'] = $payment->stripe_sepa['last4'];
}
