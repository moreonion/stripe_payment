/* global Drupal, jQuery */

import { MethodElement } from './method-element'
import { camelCase } from './utils'

const $ = jQuery

/**
 * Representing a Stripe payment method element
 */
class MethodButton extends MethodElement {
  /**
   * Hide the paymethod select radio for this payment method.
   */
  hidePaymethodSelectRadio () {
    const pmid = this.$element.data('pmid')
    const $radio = this.$element.closest('form').find(`[name*="[paymethod_select]"][value=${pmid}]`)
    if ($radio.length) {
      const $label = $radio.siblings(`label[for="${$radio.attr('id')}"]`)
      this.$paymethodRadio = $radio.add($label).hide()
      // Move button outside the fieldset.
      $radio.parent().closest('.paymethod-select-radios').append($('.stripe-payment-request-button', this.$element))
    }
  }

  /**
   * Select the hidden paymethod select radio for this payment method.
   */
  selectRadio () {
    if (this.$paymethodRadio) {
      this.$paymethodRadio.filter('input').prop('checked', true).trigger('change')
    }
  }

  /**
   * Submit the surrounding form.
   */
  submitForm () {
    // As a heuristic assume that the first submit button without formnovalidate
    // is the one we should trigger.
    this.$element.closest('form').find('[type="submit"]:not([formnovalidate])').first().click()
  }

  /**
   * Get styles for Stripe payment request buttons.
   */
  getStyles () {
    let height = '64px'
    if (this.$paymethodRadio) {
      // Match paymethod select radio labels.
      const $label = this.$paymethodRadio.filter('label')
      height = $label.outerHeight() + 'px'
    }
    return {
      paymentRequestButton: {
        type: this.settings.button.type,
        theme: this.settings.button.style,
        height: height,
      },
    }
  }

  /**
   * Initialize empty containers with Stripe payment request button.
   */
  initElements () {
    this.hidePaymethodSelectRadio()
    this.stripeElements = this.stripe.elements()
    const paymentRequest = this.stripe.paymentRequest(this.settings.transaction)
    const options = {
      paymentRequest,
      style: this.getStyles(),
    }
    const prButton = this.stripeElements.create('paymentRequestButton', options)
    paymentRequest.canMakePayment().then((result) => {
      if (result) {
        prButton.mount('.stripe-payment-request-button')
        paymentRequest.on('paymentmethod', this.paymentHandler.bind(this))
      }
      else {
        this.$element.append(`<p>${Drupal.t('This browser does not support Payment Requests or no payment method is available.')}</p>`)
      }
    })
  }

  /**
   * Event handler for the Stripe payment request button.
   * @param {object} ev - The paymentmethod event object.
   */
  async paymentHandler (ev) {
    this.resetValidation()
    this.selectRadio()
    const intent = await this.intentData()
    // Confirm the PaymentIntent without handling potential next actions (yet).
    const result = await intent.handler(
      intent.secret,
      { payment_method: ev.paymentMethod.id },
      { handleActions: false }
    )
    if (result.error) {
      // Report to the browser that the confirmation has failed.
      ev.complete('fail')
      this.errorHandler(result.error)
    }
    else {
      const confirmedIntent = result[intent.name]
      this.setStripeId(confirmedIntent.id)
      // Report to the browser that the confirmation was successful, prompting
      // it to close the browser payment method collection interface.
      ev.complete('success')
      // Check if the PaymentIntent requires any actions and if so let Stripe.js
      // handle the flow.
      if (confirmedIntent.status === 'requires_action') {
        // Let Stripe.js handle the rest of the payment flow.
        const { error } = await intent.handler(intent.secret)
        if (error) {
          this.errorHandler(error)
        }
        else {
          this.submitForm()
        }
      }
      else {
        this.submitForm()
      }
    }
  }

  /**
   * Prepare data to handle each type of intent differently.
   */
  async intentData () {
    if (!this.intent) {
      this.intent = await this.fetchIntent()
    }
    const name = camelCase(this.intent.type)
    const handler = name === 'setupIntent' ? 'confirmCardSetup' : 'confirmCardPayment'
    if (this.intent.form_build_id) {
      this.$element.closest('form').find('[name=form_build_id]').val(this.intent.form_build_id.new)
    }
    return {
      name: name,
      handler: this.stripe[handler],
      secret: this.intent.client_secret,
    }
  }

  /**
   * Validate the input data.
   * @param {object} submitter - The Drupal form submitter.
   */
  async validate (submitter) {
    submitter.ready()
  }
}

export { MethodButton }
