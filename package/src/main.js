/* global Drupal, jQuery */

import regeneratorRuntime from 'regenerator-runtime'
import { MethodElement } from './method-element'

var $ = jQuery
Drupal.behaviors.stripe_payment = {}
Drupal.behaviors.stripe_payment.attach = function (context, settings) {
  if (!Drupal.payment_handler) {
    Drupal.payment_handler = {}
  }
  $('input[name$="[stripe_id]"]', context).each(function () {
    if (!document.body.contains(this)) {
      // Guard against running for unmounted elements.
      return
    }
    var $method = $(this).closest('.payment-method-form')
    var pmid = $method.attr('data-pmid')
    var element = new MethodElement($method, settings.stripe_payment['pmid_' + pmid])

    Drupal.payment_handler[pmid] = function (pmid, $method, submitter) {
      element.validate(submitter)
    }
  })
}
