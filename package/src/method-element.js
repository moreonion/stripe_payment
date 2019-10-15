/* global Drupal, jQuery, Stripe */

var $ = jQuery

function deepSet (obj, keys, value) {
  var key = keys.shift()
  if (keys.length > 0) {
    if (typeof obj[key] === 'undefined') {
      obj[key] = {}
    }
    deepSet(obj[key], keys, value)
  }
  else {
    obj[key] = value
  }
}

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
   * Get values for CSS properties supported by Stripe elements.
   */
  getStyles () {
    const properties = [
      'color',
      'font-family',
      'font-size',
      'font-smoothing',
      'font-style',
      'font-variant',
      'font-weight',
      'line-height',
      'letter-spacing',
      'text-align',
      'text-decoration',
      'text-shadow',
      'text-transform',
    ]
    const $textField = $(`
      <div class="form-item form-type-stripe-payment-field">
        <input type="text" class="default" />
        <input type="text" class="error invalid" />
      </div>`
    ).hide().appendTo(this.$element)
    let options = {}
    // copy base styles
    let styles = window.getComputedStyle($textField.find('input.default').get(0))
    for (let p of properties) {
      let styleOption = p.replace(/-([a-z])/g, (g) => g[1].toUpperCase())
      deepSet(options, ['base', styleOption], styles.getPropertyValue(p))
    }
    // copy error color
    styles = window.getComputedStyle($textField.find('input.error').get(0))
    deepSet(options, ['invalid', 'color'], styles.getPropertyValue('color'))
    // tidy up
    $textField.remove()
    return options
  }

  /**
   * Read any cssSrc includes for font loading from the settings.
   *
   * If the item is a string, we assume it is a URL to a CSS with font-face
   * declarations.
   * The Stripe JS expects this to be in an object with the key `cssSrc`.
   * If the item is an object itself, we just use it -- Stripe does support
   * another option to declare font-faces as well.
   */
  getCssSrc () {
    return this.settings.css_src.map((item) => {
      if (typeof item === 'string') {
        return { cssSrc: item }
      }
      else {
        return item
      }
    })
  }

  /**
   * Initialize empty containers with Stripe elements (iframes for form input).
   */
  initElements () {
    const elements = this.stripe.elements({ locale: document.documentElement.lang, fonts: this.getCssSrc() })
    let options = {
      style: this.getStyles(),
      classes: { invalid: 'invalid', complete: 'valid', focus: 'focus' }
    }
    this.$element.find('[data-stripe-element]').each((i, field) => {
      let name = field.dataset.stripeElement
      options['placeholder'] = name === 'cardExpiry' ? Drupal.t('MM / YY') : ''
      let element = elements.create(name, options)
      if (name === 'cardNumber') {
        this.cardNumberElement = element
      }
      element.mount(field)
    })
  }

  /**
   * Pass the Stripe id to the backend via a hidden form field.
   */
  setStripeId (value) {
    this.$element.find('[name$="[stripe_id]"]').val(value)
  }

  /**
   * Read values from extra data fields.
   */
  extraData () {
    let data = {}
    this.$element.find('[data-stripe]').each((i, field) => {
      let keys = field.dataset.stripe.split('.')
      let value = $(field).val()
      if (value) {
        deepSet(data, keys, value)
      }
    })
    return data
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
    const data = {
      payment_method_data: this.extraData(),
    }
    let intent = {
      name: 'paymentIntent',
      handler: this.stripe.handleCardPayment
    }
    if (this.settings.intent_type === 'setup_intent') {
      intent.name = 'setupIntent'
      intent.handler = this.stripe.handleCardSetup
    }
    intent.handler(
      this.settings.client_secret, this.cardNumberElement, data
    ).then((result) => {
      if (result.error) {
        this.errorHandler(result.error.message)
        submitter.error()
      }
      else {
        this.setStripeId(result[intent.name].id)
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
