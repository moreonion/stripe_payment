<?php

namespace Drupal\stripe_payment;

use Upal\DrupalUnitTestCase;

use Stripe\Mandate;
use Stripe\PaymentMethod;
use Stripe\PaymentIntent;
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
    $mandate['payment_method_details']['sepa_debit']['reference'] = 'TEST-SEPA-REFERENCE';
    $pm['sepa_debit']['last4'] = '1234';
    $api->expects($this->once())
      ->method('retrieveIntent')
      ->with($this->equalTo(
        'seti_testsetupintent'),
        $this->equalTo(['mandate', 'payment_method'])
      )
      ->willReturn(SetupIntent::constructFrom([
        'id' => 'seti_testsetupintent',
        'object' => 'setupintent',
        'mandate' => Mandate::constructFrom($mandate),
        'payment_method' => PaymentMethod::constructFrom($pm),
      ]));
    $method->api = $api;
    $payment = entity_create('payment', ['method' => $method]);
    $payment->method_data = ['stripe_id' => 'seti_testsetupintent'];
    $method->controller->execute($payment);

    $this->assertEqual([
      'mandate_reference' => 'TEST-SEPA-REFERENCE',
      'last4' => '1234',
    ], $payment->stripe_sepa);
  }

  /**
   * Test executing without a prior AJAX call to create an intent.
   */
  public function testExecuteWithoutIntent() {
    $method = $this->method;
    $api = $this->createMock(Api::class);
    $api->expects($this->never())->method($this->anything());
    $method->api = $api;
    $payment = entity_create('payment', ['method' => $method]);
    $payment->method_data = [];
    $method->controller->execute($payment);
    $this->assertEqual(STRIPE_PAYMENT_STATUS_NO_INTENT, $payment->getStatus()->status);
    $this->assertTrue(payment_status_is_or_has_ancestor($payment->getStatus()->status, PAYMENT_STATUS_FAILED));
  }

  /**
   * Test that $payment->stripe_sepa is not set for one-off payments.
   */
  public function testExecuteOneOff() {
    $method = $this->method;
    $api = $this->createMock(Api::class);
    $charge['payment_method_details']['sepa_debit'] = [
      'mandate' => 'mandate_stripemandateid',
      'last4' => '1234',
    ];
    $intent['charges']['data'][] = $charge;
    $mandate['payment_method_details']['sepa_debit']['reference'] = 'TEST-SEPA-REFERENCE';
    $api->expects($this->once())
      ->method('retrieveIntent')
      ->with($this->equalTo('pi_testpaymentintent'), $this->equalTo([]))
      ->willReturn(PaymentIntent::constructFrom([
        'id' => 'pi_testpaymentintent',
        'object' => 'paymentintent',
      ] + $intent));
    $api->expects($this->once())
      ->method('retrieveMandate')
      ->with($this->equalTo('mandate_stripemandateid'))
      ->willReturn(Mandate::constructFrom($mandate));
    $method->api = $api;
    $payment = entity_create('payment', ['method' => $method]);
    $payment->method_data = ['stripe_id' => 'pi_testpaymentintent'];
    $payment->stripe_sepa = NULL;
    $method->controller->execute($payment);

    $this->assertEqual([
      'mandate_reference' => 'TEST-SEPA-REFERENCE',
      'last4' => '1234',
    ], $payment->stripe_sepa);
  }

  /**
   * Test the intent id is returned for webform results.
   */
  public function testWebformData() {
    $controller = $this->method->controller;
    $this->assertTrue(webform_paymethod_select_implements_data_interface($controller));

    $info = $controller->webformDataInfo();
    $this->assertArraySubset(['transaction_id' => 'Transaction ID'], $info);

    $payment = $payment = entity_create('payment', ['method' => $this->method]);
    $payment->stripe['stripe_id'] = 'seti_test';
    $data = $controller->webformData($payment);
    $this->assertArraySubset(['transaction_id' => 'seti_test'], $data);
  }

}
