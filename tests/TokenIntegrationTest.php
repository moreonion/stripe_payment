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
    $api = $this->createMock(Api::class);
    $api->method('retrieveIntent')->willReturn([
      'mandate' => 'test_mandate_id',
      'payment_method' => 'test_payment_method_id',
    ]);
    $mandate['payment_method_details']['sepa_debit']['reference'] = 'TEST-SEPA-REFERENCE';
    $api->method('retrieveMandate')->willReturn($mandate);
    $pm['sepa_debit']['last4'] = '1234';
    $api->method('retrievePaymentMethod')->willReturn($pm);
    $method->api = $api;
    $payment = entity_create('payment', ['method' => $method]);
    $payment->stripe = ['stripe_id' => 'seti_1GMDoqISFtZjFMJ3oSNcAvSS'];
    $result = token_replace('[payment:stripe-sepa-mandate-info]', ['payment' => $payment]);
    $expected = <<<HTML
<h3>SEPA-Information</h3>
<p>Die SEPA-Lastschrift erfolgt mit der Mandatsreferenz TEST-SEPA-REFERENCE und der Creditor-ID TEST-CREDITOR-ID von deinem Konto mit den Endziffern ***1234.</p>

HTML;
    $this->assertEqual($expected, $result);
  }

}
