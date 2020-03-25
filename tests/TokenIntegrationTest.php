<?php

namespace Drupal\stripe_payment;

use Upal\DrupalUnitTestCase;

/**
 * Test token replacement.
 */
class TokenIntegrationTest extends DrupalUnitTestCase {

  /**
   * Test replacing [payment:stripe-sepa-mandate-info].
   */
  public function testSepaMandateInfo() {
    $controller = payment_method_controller_load('stripe_payment_sepa');
    $method = entity_create('payment_method', ['controller' => $controller]);
    $method->controller_data['creditor_id'] = 'TEST-CREDITOR-ID';
    $payment = entity_create('payment', ['method' => $method]);
    $payment->stripe_sepa = [
      'mandate_reference' => 'TEST-SEPA-REFERENCE',
      'last4' => '1234',
    ];
    $result = token_replace('[payment:stripe-sepa-mandate-info]', ['payment' => $payment]);
    $expected = <<<HTML
<h3>SEPA information</h3>
<p>The SEPA direct debit is set up with the mandate reference TEST-SEPA-REFERENCE and the Creditor ID TEST-CREDITOR-ID from your account with the final digits ***1234.</p>

HTML;
    $this->assertEqual($expected, $result);
  }

}
