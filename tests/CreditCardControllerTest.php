<?php

namespace Drupal\stripe_payment;

use Upal\DrupalUnitTestCase;

/**
 * Tests for the SEPA payment controller.
 */
class CreditCardControllerTest extends DrupalUnitTestCase {

  /**
   * Create a test payment method.
   */
  public function setUp() : void {
    parent::setUp();
    $controller = payment_method_controller_load('stripe_payment_credit_card');
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
   * Test the intent id is returned for webform results.
   */
  public function testWebformData() {
    $controller = $this->method->controller;
    $this->assertTrue(webform_paymethod_select_implements_data_interface($controller));

    $info = $controller->webformDataInfo();
    $this->assertArraySubset(['stripe_intent' => 'Stripe intent ID'], $info);

    $payment = $payment = entity_create('payment', ['method' => $this->method]);
    $payment->stripe['stripe_id'] = 'pi_test';
    $data = $controller->webformData($payment);
    $this->assertArraySubset(['stripe_intent' => 'pi_test'], $data);
  }

}
