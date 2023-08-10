/* global Drupal, jQuery */

import regeneratorRuntime from 'regenerator-runtime'
import { MethodElement } from './method-element'
import { MethodButton } from './method-button'

const $ = jQuery
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
    const $method = $(this).closest('.payment-method-form')
    const pmid = $method.attr('data-pmid')
    const methodSettings = settings.stripe_payment['pmid_' + pmid]
    const MethodClass = methodSettings.button ? MethodButton : MethodElement
    const element = new MethodClass($method, methodSettings)

    Drupal.payment_handler[pmid] = function (pmid, $method, submitter, paymethodSelect) {
      element.validate(submitter, paymethodSelect)
    }
  })
}
