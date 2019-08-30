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
    'data-stripe' => $vars['element']['#field_name'],
  ];
  $vars['attributes']['class'][] = 'stripe-payment-field-iframe-wrapper';
  $vars['attributes']['class'][] = 'text-input';
}
