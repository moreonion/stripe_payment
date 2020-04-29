<?php

namespace Drupal\stripe_payment;

use Upal\DrupalUnitTestCase;

/**
 * Test hooks other module functions.
 */
class ModuleTest extends DrupalUnitTestCase {

  /**
   * Test the method form alter implementation.
   */
  public function testAdminFormAlter() {
    $controller = new \PaymentMethodController();
    $method = new \PaymentMethod(['controller' => $controller]);
    $form = [];
    $form_state['payment_method'] = $method;
    stripe_payment_form_payment_form_payment_method_alter($form, $form_state);
    $this->assertEmpty($form);

    $controller = new CreditCardController();
    $controller->name = 'stripe_payment_credit_card';
    $method = new \PaymentMethod(['controller' => $controller]);
    $form = [];
    $form_state['payment_method'] = $method;
    stripe_payment_form_payment_form_payment_method_alter($form, $form_state);
    $this->assertEqual(['stripe_payment_method_configuration_form_submit'], $form['#submit']);
  }

}
