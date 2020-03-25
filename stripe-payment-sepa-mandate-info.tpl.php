<?php

/**
 * @file
 * Renders the SEPA mandate information for a payment.
 *
 * Available variables:
 * - $payment: The full payment object.
 * - $creditor_id: The creditor ID as configured in the payment settings.
 * - $mandate: The mandate information as loaded from the stripe API.
 * - $mandate_reference: The SEPA mandate reference.
 * - $payment_method: The stripe payment method data as loaded from the API.
 * - $iban_last4: Last 4 digits of the payee account’s IBAN.
 */
?>
<h3><?php echo t('SEPA information'); ?></h3>
<p><?php
echo t('The SEPA direct debit is set up with the mandate reference @reference and the Creditor ID @creditor from your account with the final digits ***@last4.', [
  '@reference' => $mandate_reference,
  '@creditor' => $creditor_id,
  '@last4' => $iban_last4,
]);
?></p>
