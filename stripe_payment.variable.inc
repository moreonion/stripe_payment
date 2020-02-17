<?php

/**
 * @file
 * Information about configuration variables defined by this module.
 */

/**
 * Implements hook_variable_info().
 */
function stripe_payment_variable_info($options) {
  $v['stripe_payment_sepa_authorization'] = [
    'title' => t('SEPA authorization text', [], $options),
    'description' => t('Display the following authorization text for your donor to implicitly sign the SEPA Direct Debit mandate.', [], $options),
    'type' => 'text_format',
    'default' => [
      'value' => t('By providing your IBAN and confirming this payment, you are authorizing us and Stripe, our payment service provider, to send instructions to your bank to debit your account in accordance with those instructions. You are entitled to a refund from your bank under the terms and conditions of your agreement with your bank. A refund must be claimed within eight weeks starting from the date on which your account was debited.', [], $options),
      'format' => filter_fallback_format(),
    ],
    'localize' => TRUE,
  ];
  return $v;
}
