<?php

namespace Drupal\stripe_payment;

use Upal\DrupalUnitTestCase;

/**
 * Test entity hook implementations.
 */
class PaymentEntityTest extends DrupalUnitTestCase {

  /**
   * Payment method for the tests.
   *
   * @var \PaymentMethod
   */
  protected $method;

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
   * Test creation, updating and deletion of stripe payment data.
   */
  public function testStripeDataLifeCycle() {
    $stripe = ['stripe_id' => 'test-stripe-id', 'type' => 'test'];
    $sepa = ['mandate_reference' => 'test-mandate', 'last4' => '1234'];
    $payment = entity_create('payment', [
      'method' => $this->method,
      'stripe' => $stripe,
      'stripe_sepa' => $sepa,
    ]);
    entity_save('payment', $payment);

    // Test that new data was saved.
    $payment = entity_load_single('payment', $payment->pid);
    $this->assertEqual($stripe, $payment->stripe);
    $this->assertEqual($sepa, $payment->stripe_sepa);

    $payment->stripe['stripe_id'] = 'test-stripe-id-updated';
    $payment->stripe_sepa['mandate_reference'] = 'test-mandate-updated';
    entity_save('payment', $payment);

    // Test that updated data was saved.
    $payment = entity_load_single('payment', $payment->pid);
    $this->assertEqual('test-stripe-id-updated', $payment->stripe['stripe_id']);
    $this->assertEqual('test-mandate-updated', $payment->stripe_sepa['mandate_reference']);

    entity_delete('payment', $payment->pid);

    // Test that all stripe data was removed.
    $this->assertEqual(0, db_select('stripe_payment', 's')->condition('s.pid', $payment->pid)->countQuery()->execute()->fetchField());
    $this->assertEqual(0, db_select('stripe_payment_sepa_mandate_info', 's')->condition('s.pid', $payment->pid)->countQuery()->execute()->fetchField());
  }

  /**
   * Test non SEPA payment save and load.
   */
  public function testNonSepaSaveLoad() {
    $stripe = ['stripe_id' => 'test-stripe-id', 'type' => 'test'];
    $payment = entity_create('payment', [
      'method' => $this->method,
      'stripe' => $stripe,
    ]);
    entity_save('payment', $payment);

    // Test that new data was saved.
    $payment = entity_load_single('payment', $payment->pid);
    $this->assertEqual($stripe, $payment->stripe);

    $payment->stripe['stripe_id'] = 'test-stripe-id-updated';
    entity_save('payment', $payment);

    // Test that updated data was saved.
    $payment = entity_load_single('payment', $payment->pid);
    $this->assertEqual('test-stripe-id-updated', $payment->stripe['stripe_id']);
  }

}
