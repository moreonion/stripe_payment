<?php

namespace Drupal\stripe_payment;

class CreditCardController extends \PaymentMethodController implements \Drupal\webform_paymethod_select\PaymentRecurrentController {
  public $controller_data_defaults = array(
    'private_key' => '',
    'public_key'  => '',
  );

  public function __construct() {
    $this->title = t('Stripe Credit Card');
    $this->form = new \Drupal\stripe_payment\CreditCardForm();

    $this->payment_configuration_form_elements_callback = 'payment_forms_method_form';
    $this->payment_method_configuration_form_elements_callback = '\Drupal\stripe_payment\configuration_form';
  }

  public function execute(\Payment $payment) {
    libraries_load('stripe-php');

    $context = &$payment->context_data['context'];
    $api_key = $payment->method->controller_data['private_key'];

    switch ($context->value('donation_interval')) {
      case 'm': $interval = 'month'; break;
      case 'y': $interval = 'year'; break;
      default:  $interval = NULL; break;
    }

    try {
      \Stripe::setApiKey($api_key);
      \Stripe::setApiVersion('2014-01-31');

      $customer = $this->createCustomer(
        $payment->method_data['stripe_payment_token'],
        $this->getName($context),
        $context->value('email')
      );

      $stripe  = NULL;
      $plan_id = NULL;
      if (!$interval) {
        $stripe = $this->createCharge($customer, $payment);
      } else {
        $plan_id = $this->createPlan($customer, $payment, $interval);
        $stripe  = $this->createSubscription($customer, $plan_id);
      }

      $payment->setStatus(new \PaymentStatusItem(PAYMENT_STATUS_SUCCESS));
      entity_save('payment', $payment);
      $params = array(
        'pid'       => $payment->pid,
        'stripe_id' => $stripe->id,
        'type'      => $stripe->object,
        'plan_id'   => $plan_id,
      );
      drupal_write_record('stripe_payment', $params);
    }
    catch(\Stripe_Error $e) {
      $payment->setStatus(new \PaymentStatusItem(PAYMENT_STATUS_FAILED));
      entity_save('payment', $payment);

      $message =
        '@method payment method encountered an error while contacting ' .
        'the stripe server. The status code "@status" and the error ' .
        'message "@message". (pid: @pid, pmid: @pmid)';
      $variables = array(
        '@status'   => $e->getHttpStatus(),
        '@message'  => $e->getMessage(),
        '@pid'      => $payment->pid,
        '@pmid'     => $payment->method->pmid,
        '@method'   => $payment->method->title_specific,
      );
      watchdog('stripe_payment', $message, $variables, WATCHDOG_ERROR);
    }
  }


  public function createCustomer($token, $description, $email) {
    return \Stripe_Customer::create(array(
        'card'        => $token,
        'description' => $description,
        'email'       => $email
      ));
  }

  public function getTotalAmount(\Payment $payment) {
    // convert amount to cents. Integer value.
    return (int) ($payment->totalAmount(0) * 100);
  }

  public function createCharge($customer, $payment) {
    return \Stripe_Charge::create(array(
        'customer' => $customer->id,
        'amount'   => $this->getTotalAmount($payment),
        'currency' => $payment->currency_code
      ));
  }

  public function createPlan($customer, $payment, $interval) {
    $amount = $this->getTotalAmount($payment);
    $currency = $payment->currency_code;
    $description = ($amount/100) . ' ' . $currency . ' / ' . $interval;

    $existing_id = db_select('stripe_payment_plans', 'p')
      ->fields('p', array('id'))
      ->condition('payment_interval', $interval)
      ->condition('amount', $amount)
      ->condition('currency', $currency)
      ->execute()
      ->fetchField();

    if ($existing_id) {
      return $existing_id;
    } else {
      $params = array(
        'id'       => $description,
        'amount'   => $amount,
        'payment_interval' => $interval,
        'name'     => 'donates ' . $description,
        'currency' => $currency,
      );
      drupal_write_record('stripe_payment_plans', $params);

      // This ugly hack is necessary because 'interval' is a reserved keyword
      // in mysql and drupal does not enclose the field names in '"'.
      $params['interval'] = $params['payment_interval'];
      unset($params['payment_interval']);
      unset($params['pid']);
      return \Stripe_Plan::create($params)->id;
    }
  }

  public function createSubscription($customer, $plan_id) {
    return $customer->subscriptions->create(array('plan' => $plan_id));
  }

  public function getName($context) {
    return trim(
      $context->value('title') . ' ' .
      $context->value('first_name') . ' ' .
      $context->value('last_name')
    );
  }

  /**
   * Helper for entity_load().
   */
  public static function load($entities) {
    $pmids = array();
    foreach ($entities as $method) {
      if ($method->controller instanceof CreditCardController) {
        $pmids[] = $method->pmid;
      }
    }
    if ($pmids) {
      $query = db_select('stripe_payment_payment_method_controller', 'controller')
        ->fields('controller')
        ->condition('pmid', $pmids);
      $result = $query->execute();
      while ($data = $result->fetchAssoc()) {
        $method = $entities[$data['pmid']];
        unset($data['pmid']);
        $method->controller_data = (array) $data;
        $method->controller_data += $method->controller->controller_data_defaults;
      }
    }
  }

  /**
   * Helper for entity_insert().
   */
  public function insert($method) {
    $method->controller_data += $this->controller_data_defaults;

    $query = db_insert('stripe_payment_payment_method_controller');
    $values = array_merge($method->controller_data, array('pmid' => $method->pmid));
    $query->fields($values);
    $query->execute();
  }

  /**
   * Helper for entity_update().
   */
  public function update($method) {
    $query = db_update('stripe_payment_payment_method_controller');
    $values = array_merge($method->controller_data, array('pmid' => $method->pmid));
    $query->fields($values);
    $query->condition('pmid', $method->pmid);
    $query->execute();
  }

  /**
   * Helper for entity_delete().
   */
  public function delete($method) {
    db_delete('stripe_payment_payment_method_controller')
      ->condition('pmid', $method->pmid)
      ->execute();
  }
}

/* Implements PaymentMethodController::payment_method_configuration_form_elements_callback().
 *
 * @return array
 *   A Drupal form.
 */
function configuration_form(array $form, array &$form_state) {
  $controller_data = $form_state['payment_method']->controller_data;

  $library = libraries_detect('stripe-php');
  if (empty($library['installed'])) {
    drupal_set_message($library['error message'], 'error', FALSE);
  }

  $form['private_key'] = array(
    '#type' => 'textfield',
    '#title' => t('Private key'),
    '#description' => t('Available from Your Account / Settings / API keys on stripe.com'),
    '#required' => true,
    '#default_value' => isset($controller_data['private_key']) ? $controller_data['private_key'] : '',
  );

  $form['public_key'] = array(
    '#type' => 'textfield',
    '#title' => t('Public key'),
    '#description' => t('Available from Your Account / Settings / API keys on stripe.com'),
    '#required' => true,
    '#default_value' => isset($controller_data['public_key']) ? $controller_data['public_key'] : '',
  );

  return $form;
}

/**
 * Implements form validate callback for
 * \stripe_payment\configuration_form().
 */
function configuration_form_validate(array $element, array &$form_state) {
  $values = drupal_array_get_nested_value($form_state['values'], $element['#parents']);
  $form_state['payment_method']->controller_data['private_key'] = $values['private_key'];
  $form_state['payment_method']->controller_data['public_key'] = $values['public_key'];
}
