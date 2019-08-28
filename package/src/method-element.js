/* global Drupal, jQuery, Stripe */

var $ = jQuery

/**
 * Representing a Stripe payment method element
 */
class MethodElement {
  /**
   * Create a Stripe payment method element.
   * @param {object} $element - The jQuery element containing the payment form.
   * @param {object} setting - The Drupal settings for this payment form.
   */
  constructor ($element, settings) {
    this.$element = $element
    this.settings = settings
    this.form_id = this.$element.closest('form').attr('id')
    this.waitForLibrariesThenInit()
  }

  /**
   * Make sure the Stripe library has been loaded before using it.
   */
  waitForLibrariesThenInit () {
    if (typeof Stripe !== 'undefined') {
      this.stripe = Stripe(this.settings.public_key)
      this.initElements()
    }
    else {
      window.setTimeout(() => {
        this.waitForLibrariesThenInit()
      }, 100)
    }
  }

  /**
   * Initialize empty containers with Stripe elements (iframes for form input).
   */
  initElements () {
    let elements = this.stripe.elements({ locale: document.documentElement.lang })
    this.cardElement = elements.create('card', {
      value: { postalCode: 'test', hidePostalCode: false }
    })
    this.cardElement.mount('[data-stripe-field="card"]')
  }

  /**
   * Pass the Stripe id to the backend via a hidden form field.
   */
  setStripeId (value) {
    this.$element.find('[name$="[stripe_id]"]').val(value)
  }

  /**
   * Validate the input data.
   * @param {object} submitter - The Drupal form submitter.
   */
  validate (submitter) {
    $('.mo-dialog-wrapper').addClass('visible')
    if (typeof Drupal.clientsideValidation !== 'undefined') {
      $('#clientsidevalidation-' + this.form_id + '-errors ul').empty()
    }
    var data = {
    // payment_method_data: {
    //   billing_details:
    //     "address": {
    //       "city": null,
    //       "country": null,
    //       "line1": null,
    //       "line2": null,
    //       "postal_code": null,
    //       "state": null
    //     },
    //     "email": null,
    //     "name": null,
    //     "phone": null
    //   },
    // }
    }
    let intent = {
      name: 'paymentIntent',
      handler: this.stripe.handleCardPayment
    }
    if (this.settings.intent_type === 'setup_intent') {
      intent.type = 'setupIntent'
      intent.handler = this.stripe.handleCardSetup
    }
    intent.handler(
      this.settings.client_secret, this.cardElement, data
    ).then((result) => {
      if (result.error) {
        console.log(result.error)
        this.errorHandler(result.error.message)
        submitter.error()
      }
      else {
        this.setStripeId(result[intent.name].id)
        console.log('Success!!!!')
        submitter.ready()
      }
    })
  }

  /**
   * Display error messages.
   * @param {object} error - The Stripe error data.
   */
  errorHandler (error) {
    var settings, wrapper, child
    if (typeof Drupal.clientsideValidation !== 'undefined') {
      settings = Drupal.settings.clientsideValidation['forms'][this.form_id]
      wrapper = document.createElement(settings.general.wrapper)
      child = document.createElement(settings.general.errorElement)
      child.className = settings.general.errorClass
      child.innerHTML = error
      wrapper.appendChild(child)

      $('#clientsidevalidation-' + this.form_id + '-errors ul')
        .append(wrapper).show()
        .parent().show()
    }
    else {
      if ($('#messages').length === 0) {
        $('<div id="messages"><div class="section clearfix"></div></div>').insertAfter('#header')
      }
      $('<div class="messages error">' + error + '</div>').appendTo('#messages .clearfix')
    }
  }
}

export { MethodElement }
