<?php

namespace Drupal\stripe_payment;

use Drupal\little_helpers\ArrayConfig;
use Drupal\webform_paymethod_select\WebformPaymentContext;
use Upal\DrupalUnitTestCase;

/**
 * Tests for the payment request form.
 */
class PaymentRequestFormTest extends DrupalUnitTestCase {

  /**
   * Create a payment method stub for testing.
   */
  protected function paymentMethodStub() {
    $controller = new PaymentRequestController();
    $method = new \PaymentMethod([
      'pmid' => 1001,
      'controller' => $controller,
      'controller_data' => [],
    ]);
    ArrayConfig::mergeDefaults($method->controller_data, $controller->controller_data_defaults);
    return $method;
  }

  /**
   * Create a payment stub for testing.
   */
  protected function paymentStub() {
    $method = $this->paymentMethodStub();
    $context = $this->createMock(WebformPaymentContext::class);
    $context->method('callbackUrl')->willReturn('/ajax-callback');
    $payment = new \Payment([
      'pid' => 2001,
      'description' => 'stripe test payment',
      'currency_code' => 'EUR',
      'method' => $method,
      'contextObj' => $context,
    ]);
    return $payment;
  }

  /**
   * Test generating the form and settings based on a payment object.
   */
  public function testForm() {
    $payment = $this->paymentStub();
    $form_obj = new PaymentRequestForm();
    $form_state = [];
    $form = $form_obj->form([], $form_state, $payment);
    $settings = $form['#attached']['js'][0]['data']['stripe_payment']["pmid_{$payment->method->pmid}"];
    $this->assertEqual([
      'type' => 'default',
      'style' => 'dark',
    ], $settings['button']);
  }

}
