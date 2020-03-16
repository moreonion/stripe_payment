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
 *   - mandate: The stripe mandate info as loaded from the API.
 *   - payment_method: The stripe payment method information as loaded from the
 *              API.
 */
function template_preprocess_stripe_payment_sepa_mandate_info(array &$variables) {
  $variables['creditor_id'] = $variables['payment']->method->controller_data['creditor_id'];
  $variables['mandate_reference'] = $variables['mandate']['payment_method_details']['sepa_debit']['reference'];
  $variables['iban_last4'] = $variables['payment_method']['sepa_debit']['last4'];
}
