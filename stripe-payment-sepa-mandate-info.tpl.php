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
<h3>SEPA-Information</h3>
<p>Die SEPA-Lastschrift erfolgt mit der Mandatsreferenz <?php echo $mandate_reference; ?> und der Creditor-ID <?php echo $creditor_id; ?> von deinem Konto mit den Endziffern ***<?php echo $iban_last4; ?>.</p>
