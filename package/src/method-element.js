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
    this.intent = null
  }

  /**
   * Make sure the Stripe library has been loaded before using it.
   */
  waitForLibrariesThenInit () {
    if (typeof Stripe !== 'undefined') {
      this.stripe = Stripe(this.settings.public_key, {
        locale: document.documentElement.lang,
      })
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
      </div>
      <div class="form-item form-type-stripe-payment-field">
        <input type="text" class="error invalid" />
      </div>`
    ).hide().appendTo(this.$element)
    const options = {}
    // copy base styles
    let styles = window.getComputedStyle($textField.find('input.default').get(0))
    for (const p of properties) {
      const styleOption = camelCase(p)
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
    this.stripeElements = this.stripe.elements({ fonts: this.getFontSrc() })
    const options = {
      style: this.getStyles(),
      classes: { invalid: 'invalid', complete: 'valid', focus: 'focus' }
    }
    this.$element.find('[data-stripe-element]').each((i, field) => {
      const name = field.dataset.stripeElement
      options.placeholder = name === 'cardExpiry' ? Drupal.t('MM / YY') : ''
      // Extra options for IBAN field:
      if (name === 'iban') {
        options.supportedCountries = ['SEPA']
        const $country = this.$element.find('[data-stripe="billing_details.address.country"]')
        options.placeholderCountry = $country.val() || 'DE'
      }
      const element = this.stripeElements.create(name, options)
      element.mount(field)
      if (this.clientsideValidationEnabled()) {
        const validator = Drupal.myClientsideValidation.validators[this.form_id]
        const $wrapper = $('#clientsidevalidation-' + this.form_id + '-errors')
        element.on('change', (event) => {
          if (event.error) {
            this.errorHandler(event.error, $(field))
          }
          if (event.complete) {
            // Remove error. jQuery validate does not provide a function for that.
            const errors = validator.errorsFor(field)
            validator.addWrapper(errors).remove()
            // Hide container if it’s empty.
            if ($wrapper.length && !$wrapper.find(validator.settings.errorElement).length) {
              $wrapper.hide()
            }
          }
        })
      }
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
    const data = {}
    this.$element.find('[data-stripe]').each((i, field) => {
      const keys = field.dataset.stripe.split('.')
      const value = $(field).val()
      if (value) {
        deepSet(data, keys, value)
      }
    })
    return data
  }

  /**
   * Fetch intent data from the server.
   */
  fetchIntent () {
    const form = this.$element.closest('form').get(0)
    const formData = new FormData()
    formData.append('form_build_id', form.form_build_id.value)
    return $.ajax({
      type: 'POST',
      url: this.settings.intent_callback_url,
      data: formData,
      processData: false,
      contentType: false,
    })
  }

  /**
   * Prepare data to handle each type of intent differently.
   */
  async intentData () {
    if (!this.intent) {
      this.intent = await this.fetchIntent()
    }
    const name = camelCase(this.intent.type)
    const data = { payment_method: this.extraData() }
    let handler
    if (this.intent.methods.includes('sepa_debit')) {
      data.payment_method.sepa_debit = this.stripeElements.getElement('iban')
      handler = name === 'setupIntent' ? 'confirmSepaDebitSetup' : 'confirmSepaDebitPayment'
    }
    else {
      data.payment_method.card = this.stripeElements.getElement('cardNumber')
      handler = name === 'setupIntent' ? 'confirmCardSetup' : 'confirmCardPayment'
    }
    if (this.intent.form_build_id) {
      this.$element.closest('form').find('[name=form_build_id]').val(this.intent.form_build_id.new)
    }
    return {
      name: name,
      data: data,
      handler: this.stripe[handler],
      secret: this.intent.client_secret,
    }
  }

  /**
   * Validate the input data.
   * @param {object} submitter - The Drupal form submitter.
   */
  async validate (submitter) {
    $('.mo-dialog-wrapper').addClass('visible')
    $('.stripe-error').remove()
    if (this.clientsideValidationEnabled()) {
      const $validator = Drupal.myClientsideValidation.validators[this.form_id]
      $validator.prepareForm()
      $validator.hideErrors()
    }
    const intent = await this.intentData()
    const result = await intent.handler(
      intent.secret, intent.data
    )
    if (result.error) {
      this.errorHandler(result.error)
      submitter.error()
    }
    else {
      this.setStripeId(result[intent.name].id)
      submitter.ready()
    }
  }

  /**
   * Display error messages.
   * @param {object} error - The Stripe error data.
   */
  errorHandler (error, $field = null) {
    // Trigger clientside validation for respective field.
    if (this.clientsideValidationEnabled()) {
      const validator = Drupal.myClientsideValidation.validators[this.form_id]
      if ($field === null) {
        switch (error.code) {
          case 'incorrect_number':
          case 'invalid_number':
          case 'incomplete_number':
            $field = this.$element.find('[data-stripe-element="cardNumber"]')
            break
          case 'incorrect_cvc':
          case 'invalid_cvc':
          case 'incomplete_cvc':
            $field = this.$element.find('[data-stripe-element="cardCvc"]')
            break
          case 'invalid_expiry_month':
          case 'invalid_expiry_year':
          case 'invalid_expiry_year_past':
          case 'incomplete_expiry':
          case 'expired_card':
            $field = this.$element.find('[data-stripe-element="cardExpiry"]')
            break
          case 'invalid_bank_account_iban':
          case 'invalid_iban_country_code':
          case 'invalid_iban_start':
          case 'invalid_iban':
          case 'incomplete_iban':
            $field = this.$element.find('[data-stripe-element="iban"]')
            break
        }
      }
      if ($field && $field.attr('name')) {
        const errors = {}
        errors[$field.attr('name')] = error.message
        // Needed so jQuery validate will find the element when removing errors.
        validator.currentElements.push($field)
        // Trigger validation error.
        validator.showErrors(errors)
      }
      else {
        // The error is not related to a payment field, reconstruct error markup.
        const settings = Drupal.settings.clientsideValidation.forms[this.form_id].general
        const $message = $(`<${settings.errorElement} class="${settings.errorClass}">`).text(error.message)
        const $wrapper = $('#clientsidevalidation-' + this.form_id + '-errors')
        // Add message to clientside validation wrapper if there is one.
        if ($wrapper.length) {
          const $list = $wrapper.find('ul')
          $message.wrap(`<${settings.wrapper}>`).parent().addClass('stripe-error').appendTo($list)
          $list.show()
          $wrapper.show()
        }
        // Show message above the payment fieldset in want of a better place.
        else {
          $message.addClass('stripe-error').insertBefore(this.$element)
        }
      }
    }
    // Without clientside validation render a message above the form.
    else {
      const $message = $('<div class="messages error">').text(error.message)
      $message.addClass('stripe-error').insertBefore(this.$element.closest('form'))
    }
  }

  /**
   * Checks whether clientside validation is enabled for this form.
   */
  clientsideValidationEnabled () {
    return typeof Drupal.clientsideValidation !== 'undefined' &&
           typeof Drupal.myClientsideValidation.validators[this.form_id] !== 'undefined' &&
           typeof Drupal.settings.clientsideValidation.forms[this.form_id] !== 'undefined'
  }
}

export { MethodElement }
