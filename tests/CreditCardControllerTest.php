<?php

namespace Drupal\stripe_payment;

use Upal\DrupalUnitTestCase;

use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Subscription;
use Stripe\Exception\InvalidRequestException;

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
   * Create a new example payment.
   */
  protected function createPayment() {
    return $payment = new \Payment([
      'description' => 'test payment',
      'currency_code' => 'EUR',
      'method' => $this->method,
      'finish_callback' => 'webform_paymethod_select_payment_finish',
    ]);
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
   * Test one-off payments are saved.
   */
  public function testExecuteOneOff() {
    $method = $this->method;
    $api = $this->createMock(Api::class);
    $api->expects($this->once())
      ->method('retrieveIntent')
      ->with($this->equalTo(
        'pi_testpaymentintent'),
      )
      ->willReturn(PaymentIntent::constructFrom([
        'id' => 'pi_testpaymentintent',
        'object' => 'paymentintent',
        'payment_method' => 'pm_testpaymentmethod',
      ]));
    $method->api = $api;
    $payment = entity_create('payment', ['method' => $method]);
    $payment->method_data = ['stripe_id' => 'pi_testpaymentintent'];
    $this->assertTrue($method->controller->execute($payment));

    $this->assertEqual([
      'stripe_id' => 'pi_testpaymentintent',
      'type' => 'paymentintent',
    ], $payment->stripe);
  }

  /**
   * Test recurrent payments are saved without creating subscriptions again.
   */
  public function testExecuteRecurring() {
    $method = $this->method;
    $api = $this->createMock(Api::class);
    $api->expects($this->never())->method('retrieveIntent');
    $method->api = $api;
    $payment = entity_create('payment', ['method' => $method]);
    $payment->method_data = ['stripe_id' => 'seti_testsetupintent'];
    entity_save('payment', $payment);
    db_insert('stripe_payment_subscriptions')
      ->fields(['pid', 'stripe_id'])
      ->values(['pid' => $payment->pid, 'stripe_id' => 'sub_testsubscription'])
      ->execute();
    $this->assertTrue($method->controller->execute($payment));
  }

  /**
   * Test AJAX callback creates a new intent.
   */
  public function testAjaxCallbackReturnsNewIntent() {
    $method = $this->method;
    $intent_data = [
      'id' => 'pi_testpaymentintent',
      'object' => 'paymentintent',
      'payment_method_types' => ['card'],
      'client_secret' => 'testsecret',
    ];
    $payment = entity_create('payment', ['method' => $method]);
    $api = $this->createMock(Api::class);
    $api->expects($this->once())
      ->method('createIntent')
      ->with($this->equalTo($payment))
      ->willReturn(PaymentIntent::constructFrom($intent_data));
    $method->api = $api;
    $result = $method->controller->ajaxCallback($payment, [], []);
    $this->assertEqual([
      'client_secret' => $intent_data['client_secret'],
      'type' => $intent_data['object'],
      'methods' => $intent_data['payment_method_types'],
      'needs_confirmation' => TRUE,
    ], $result);
    $this->assertEqual([
      'stripe_id' => $intent_data['id'],
      'type' => $intent_data['object'],
    ], $payment->stripe);
    $this->assertEqual(STRIPE_PAYMENT_STATUS_INTENT_CREATED, $payment->getStatus()->status);
    $this->assertTrue(payment_status_is_or_has_ancestor($payment->getStatus()->status, PAYMENT_STATUS_PENDING));
  }

  /**
   * Test AJAX callback returns data for an existing intent.
   */
  public function testAjaxCallbackReturnsExistingIntent() {
    $method = $this->method;
    $intent_data = [
      'id' => 'pi_testpaymentintent',
      'object' => 'paymentintent',
      'payment_method_types' => ['card'],
      'client_secret' => 'testsecret',
    ];
    $api = $this->createMock(Api::class);
    $api->expects($this->once())
      ->method('retrieveIntent')
      ->with($this->equalTo($intent_data['id']))
      ->willReturn(PaymentIntent::constructFrom($intent_data));
    $method->api = $api;
    $payment = entity_create('payment', [
      'method' => $method,
      'stripe' => ['stripe_id' => $intent_data['id']],
    ]);
    $result = $method->controller->ajaxCallback($payment, [], []);
    $this->assertEqual([
      'client_secret' => $intent_data['client_secret'],
      'type' => $intent_data['object'],
      'methods' => $intent_data['payment_method_types'],
      'needs_confirmation' => TRUE,
    ], $result);
  }

  /**
   * Test AJAX callback returns the intent from a subscription.
   */
  public function testAjaxCallbackReturnsSubscriptionIntent() {
    $method = $this->method;
    $intent_data = [
      'id' => 'seti_testsetupintent',
      'object' => 'setupintent',
      'payment_method_types' => ['card'],
      'client_secret' => 'testsecret',
    ];
    $payment = entity_create('payment', [
      'description' => 'test payment',
      'currency_code' => 'EUR',
      'method' => $method,
      'method_data' => [
        'customer' => ['name' => 'Tester', 'email' => 'test@example.com'],
      ],
    ]);
    $start_date = (new \DateTimeImmutable('', new \DateTimeZone('UTC')))->modify('+10 day');
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'item1',
      'description' => 'Item 1 test',
      'amount' => 3,
      'quantity' => 5,
      'recurrence' => (object) [
        'interval_unit' => 'monthly',
        'interval_value' => 1,
        'start_date' => $start_date->format('Y-m-d'),
      ],
    ]));
    $form_state = ['input' => ['stripe_pm' => 'pm_testpaymentmethod']];
    $api = $this->createMock(Api::class);
    $api->expects($this->once())
      ->method('createCustomer')
      ->willReturn(Customer::constructFrom(['id' => 'cus_testcustomer']));
    $api->expects($this->once())
      ->method('createSubscription')
      ->willReturn(Subscription::constructFrom([
        'id' => 'sub_testsubscription',
        'plan' => ['id' => 'price_testplan', 'amount' => 300],
        'quantity' => 5,
        'billing_cycle_anchor' => $start_date->getTimeStamp(),
        'pending_setup_intent' => $intent_data,
        'latest_invoice' => NULL,
      ]));
    $method->api = $api;
    $result = $method->controller->ajaxCallback($payment, [], $form_state);
    $this->assertEqual([
      'client_secret' => $intent_data['client_secret'],
      'type' => $intent_data['object'],
      'methods' => $intent_data['payment_method_types'],
      'needs_confirmation' => TRUE,
    ], $result);
    $this->assertEqual([
      'stripe_id' => $intent_data['id'],
      'type' => $intent_data['object'],
    ], $payment->stripe);
    $this->assertEqual(STRIPE_PAYMENT_STATUS_INTENT_CREATED, $payment->getStatus()->status);
    $this->assertTrue(payment_status_is_or_has_ancestor($payment->getStatus()->status, PAYMENT_STATUS_PENDING));
  }

  /**
   * Test AJAX callback returns no intent from a subscription.
   */
  public function testAjaxCallbackReturnsNoIntent() {
    $method = $this->method;
    $payment = entity_create('payment', [
      'description' => 'test payment',
      'currency_code' => 'EUR',
      'method' => $method,
      'method_data' => [
        'customer' => ['name' => 'Tester', 'email' => 'test@example.com'],
      ],
    ]);
    $start_date = (new \DateTimeImmutable('', new \DateTimeZone('UTC')))->modify('+10 day');
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'item1',
      'description' => 'Item 1 test',
      'amount' => 3,
      'quantity' => 5,
      'recurrence' => (object) [
        'interval_unit' => 'monthly',
        'interval_value' => 1,
        'start_date' => $start_date->format('Y-m-d'),
      ],
    ]));
    $form_state = ['input' => ['stripe_pm' => 'pm_testpaymentmethod']];
    $api = $this->createMock(Api::class);
    $api->expects($this->once())
      ->method('createCustomer')
      ->willReturn(Customer::constructFrom(['id' => 'cus_testcustomer']));
    $api->expects($this->once())
      ->method('createSubscription')
      ->willReturn(Subscription::constructFrom([
        'id' => 'sub_testsubscription',
        'plan' => ['id' => 'price_testplan', 'amount' => 300],
        'quantity' => 5,
        'billing_cycle_anchor' => $start_date->getTimeStamp(),
        'pending_setup_intent' => NULL,
        'latest_invoice' => NULL,
      ]));
    $method->api = $api;
    $result = $method->controller->ajaxCallback($payment, [], $form_state);
    $this->assertEqual([
      'client_secret' => NULL,
      'type' => 'no_intent',
      'methods' => [],
      'needs_confirmation' => FALSE,
    ], $result);
    $this->assertObjectNotHasAttribute('stripe', $payment);
  }

  /**
   * Test API error during AJAX callback returns error.
   */
  public function testAjaxCallbackApiError() {
    $method = $this->method;
    $payment = entity_create('payment', [
      'description' => 'test payment',
      'currency_code' => 'EUR',
      'method' => $method,
      'method_data' => ['customer' => ['name' => 'Tester']],
    ]);
    $start_date = (new \DateTimeImmutable('', new \DateTimeZone('UTC')))->modify('+10 day');
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'item1',
      'description' => 'Item 1 test',
      'amount' => 3,
      'quantity' => 5,
      'recurrence' => (object) [
        'interval_unit' => 'monthly',
        'interval_value' => 1,
        'start_date' => $start_date->format('Y-m-d'),
      ],
    ]));
    $form_state = ['input' => ['stripe_pm' => 'pm_testpaymentmethod']];
    $api = $this->createMock(Api::class);
    $api->expects($this->once())
      ->method('createCustomer')
      ->willThrowException(new InvalidRequestException('Missing required argument'));
    $method->api = $api;
    $result = $method->controller->ajaxCallback($payment, [], $form_state);
    $this->assertEqual(['error' => ['message' => 'Payment failed.']], $result);
  }

  /**
   * Test method can be used with 1 recurring line item.
   */
  public function testValidateSingleRecurringLineItem() {
    $payment = $this->createPayment();
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'test',
      'amount' => 10,
      'recurrence' => (object) [
        'interval_unit' => 'monthly',
      ],
    ]));
    $this->assertNull($this->method->controller->validate($payment, $this->method, TRUE));
  }

  /**
   * Test method can be used with mixed line item.
   */
  public function testValidateMixedLineItems() {
    $payment = $this->createPayment();
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'test-1',
      'amount' => 100,
    ]));
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'test-m',
      'amount' => 10,
      'recurrence' => (object) [
        'interval_unit' => 'monthly',
      ],
    ]));
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'test-y',
      'amount' => 50,
      'recurrence' => (object) [
        'interval_unit' => 'yearly',
      ],
    ]));
    $this->assertNull($this->method->controller->validate($payment, $this->method, TRUE));
  }

  /**
   * Test method throws exception for >1 recurring line item.
   */
  public function testValidateTooManyRecurringLineItems() {
    $payment = $this->createPayment();
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'test-m',
      'amount' => 10,
      'recurrence' => (object) [
        'interval_unit' => 'monthly',
      ],
    ]));
    $payment->setLineItem(new \PaymentLineItem([
      'name' => 'test-y',
      'amount' => 50,
      'recurrence' => (object) [
        'interval_unit' => 'yearly',
      ],
    ]));
    $this->expectException(\PaymentValidationException::class);
    $this->method->controller->validate($payment, $this->method, TRUE);
  }

  /**
   * Test the intent id is returned for webform results.
   */
  public function testWebformData() {
    $controller = $this->method->controller;
    $this->assertTrue(webform_paymethod_select_implements_data_interface($controller));

    $info = $controller->webformDataInfo();
    $this->assertEmpty(array_diff(['transaction_id' => 'Transaction ID'], $info));

    $payment = $payment = entity_create('payment', ['method' => $this->method]);
    $payment->stripe['stripe_id'] = 'pi_test';
    $data = $controller->webformData($payment);
    $this->assertEmpty(array_diff(['transaction_id' => 'pi_test'], $data));
  }

}
