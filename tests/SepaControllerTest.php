<?php

namespace Drupal\stripe_payment;

use Upal\DrupalUnitTestCase;

use Stripe\Mandate;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;

/**
 * Tests for the SEPA payment controller.
 */
class SepaControllerTest extends DrupalUnitTestCase {

  /**
   * Create a test payment method.
   */
  public function setUp() : void {
    parent::setUp();
    $controller = payment_method_controller_load('stripe_payment_sepa');
    $method = entity_create('payment_method', [
      'controller' => $controller,
      'controller_data' => $controller->controller_data_defaults,
    ]);
    entity_save('payment_method', $method);
    $this->method = $method;
  }

  /**
   * Delete the test payment method and all payments using it.
   */
  public function tearDown() : void {
    $pids = db_select('payment', 'p')
      ->fields('p', ['pid'])
      ->condition('p.pmid', $this->method->pmid)
      ->execute()
      ->fetchCol();
    foreach ($pids as $pid) {
      entity_delete('payment', $pid);
    }
    entity_delete('payment_method', $this->method->pmid);
    parent::tearDown();
  }

  /**
   * Test that $payment->stripe_sepa is set in execute().
   */
  public function testExecute() {
    $method = $this->method;
    $api = $this->createMock(Api::class);
    $api->method('retrieveIntent')->willReturn(SetupIntent::constructFrom([
      'id' => 'seti_testsetupintent',
      'object' => 'setupintent',
      'mandate' => 'test_mandate_id',
      'payment_method' => 'test_payment_method_id',
    ]));
    $mandate['payment_method_details']['sepa_debit']['reference'] = 'TEST-SEPA-REFERENCE';
    $api->method('retrieveMandate')->willReturn(Mandate::constructFrom($mandate));
    $pm['sepa_debit']['last4'] = '1234';
    $api->method('retrievePaymentMethod')->willReturn(PaymentMethod::constructFrom($pm));
    $method->api = $api;
    $payment = entity_create('payment', ['method' => $method]);
    $payment->method_data = ['stripe_id' => 'seti_testsetupintent'];
    $method->controller->execute($payment);

    $this->assertEqual([
      'mandate_reference' => 'TEST-SEPA-REFERENCE',
      'last4' => '1234',
    ], $payment->stripe_sepa);
  }

}
