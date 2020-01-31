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
 * Convert kebap and snake case strings to camel case.
 */
function camelCase (str) {
  return str.replace(/[-_]([a-z])/g, (g) => g[1].toUpperCase())
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
      let styleOption = camelCase(p)
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
   * Read font sources from the settings.
   *
   * If the item is an object, we just pass it on.
   * If it is a string, we assume it is a URL to a CSS with font-face
   * declarations and wrap it in a new settings object for Stripe JS.
   */
  getFontSrc () {
    return this.settings.font_src.map((item) => {
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
    this.stripeElements = this.stripe.elements({ locale: document.documentElement.lang, fonts: this.getFontSrc() })
    let options = {
      style: this.getStyles(),
      classes: { invalid: 'invalid', complete: 'valid', focus: 'focus' }
    }
    this.$element.find('[data-stripe-element]').each((i, field) => {
      let name = field.dataset.stripeElement
      options['placeholder'] = name === 'cardExpiry' ? Drupal.t('MM / YY') : ''
      // Extra options for IBAN field:
      if (name === 'iban') {
        options['supportedCountries'] = ['SEPA']
        const $country = this.$element.find('[data-stripe="billing_details.address.country"]')
        options['placeholderCountry'] = $country.val() || 'DE'
      }
      let element = this.stripeElements.create(name, options)
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
   * Prepare data to handle each type of intent differently.
   */
  intentData () {
    const name = camelCase(this.settings.intent_type)
    let handler = 'confirm'
    let data = { payment_method: this.extraData() }
    if (this.settings.intent_methods.includes('sepa_debit')) {
      handler += 'SepaDebit'
      data.payment_method.sepa_debit = this.stripeElements.get('iban')
    }
    else {
      handler += 'Card'
      data.payment_method.card = this.stripeElements.get('cardNumber')
    }
    handler += name.replace('Intent', '').replace(/^[a-z]/g, (g) => g.toUpperCase())
    return {
      name: name,
      handler: this.stripe[handler],
      data: data
    }
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
    const intent = this.intentData()
    intent.handler(
      this.settings.client_secret, intent.data
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
