<?php

namespace Drupal\stripe_payment;

use Drupal\webform_paymethod_select\PaymentRecurrentController;

/**
 * Recurrent version of the BacsController.
 *
 * This is useful while migrating to the payment_recurrence module.
 *
 * @see https://github.com/moreonion/webform_paymethod_select/issues/16
 */
class BacsControllerRecurrent extends BacsController implements PaymentRecurrentController {

}
