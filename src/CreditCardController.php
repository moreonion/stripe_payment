<?php

namespace Drupal\stripe_payment;

class CreditCardController extends \PaymentMethodController implements \Drupal\webform_paymethod_select\PaymentRecurrentController {
  public $controller_data_defaults = array(
    'private_key' => '',
    'public_key'  => '',
    'field_map' => [],
    'enable_recurrent_payments' => 1,
  );

  public function __construct() {
    $this->title = t('Stripe Credit Card');

    $this->payment_configuration_form_elements_callback = 'payment_forms_payment_form';
    $this->payment_method_configuration_form_elements_callback = 'payment_forms_method_configuration_form';
  }

  public function paymentForm() {
    return new CreditCardForm();
  }

  public function configurationForm() {
    return new CreditCardConfigurationForm();
  }

  /**
   * {@inheritdoc}
   */
  function validate(\Payment $payment, \PaymentMethod $method, $strict) {
    parent::validate($payment, $method, $strict);

    if (!($library = libraries_detect('stripe-php')) || empty($library['installed'])) {
      throw new \PaymentValidationException(t('The stripe-php library could not be found.'));
    }
    if (version_compare($library['version'], '3', '<')) {
      throw new \PaymentValidationException(t('stripe_payment needs at least version 3 of the stripe-php library (installed: @version).', array('@version' => $library['version'])));
    }

    if ($payment->contextObj && ($interval = $payment->contextObj->value('donation_interval'))) {
      if (empty($method->controller_data['enable_recurrent_payments']) && in_array($interval, ['m', 'y'])) {
        throw new \PaymentValidationException(t('Recurrent payments are disabled for this payment method.'));
      }
    }
  }

  public function execute(\Payment $payment) {
    libraries_load('stripe-php');

    $context = $payment->contextObj;
    $api_key = $payment->method->controller_data['private_key'];

    switch ($context->value('donation_interval')) {
      case 'm': $interval = 'month'; break;
      case 'y': $interval = 'year'; break;
      default:  $interval = NULL; break;
    }

    try {
      \Stripe\Stripe::setApiKey($api_key);

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
    catch(\Stripe\Error\Base $e) {
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
    return \Stripe\Customer::create(array(
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
    return \Stripe\Charge::create(array(
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
      return \Stripe\Plan::create($params)->id;
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

}
