(function ($) {
Drupal.behaviors.stripe_payment = {
    attach: function(context, settings) {
        if ($('#stripe-id', context).length > 0) {
          if (!Drupal.payment_handler) {
            Drupal.payment_handler = {};
          }
          var self = this;
          for (var key in settings.stripe_payment) {
            var pmSettings = settings.stripe_payment[key];
            var pmid = pmSettings.pmid

            var stripe = Stripe(pmSettings.public_key);

            var elements = stripe.elements({locale: document.documentElement.lang});
            var cardElement = elements.create('card', {
              value: {postalCode: 'TODO', hidePostalCode: false}
            });
            cardElement.mount('#stripe-card-element');  // TODO: why called on prev step???

            Drupal.payment_handler[pmid] = function(pmid, $method, submitter) {
              // self.validateHandler(pmSettings, $method, submitter);
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
              };

              if (pmSettings.intent_type == 'setup_intent') {
                stripe.handleCardSetup(
                  pmSettings.client_secret, cardElement, data
                ).then(function(result) {
                  console.log(result.setupIntent);
                  if (result.error) {
                    console.log(result.error);
                    self.errorHandler(result.error.message);
                    submitter.error();
                  } else {
                    $('#stripe-id', context).val(result.setupIntent.id);
                    console.log('Success!!!!');
                    console.log(result.setupIntent.id);
                    submitter.ready();
                  }
                });
              }
              else {
                stripe.handleCardPayment(
                  pmSettings.client_secret, cardElement, data
                ).then(function(result) {
                  console.log(result.paymentIntent.payment_method);
                  console.log(result.paymentIntent.payment_method_options);
                  console.log(result.paymentIntent.payment_method_types);
                  if (result.error) {
                    console.log(result.error);
                    self.errorHandler(result.error.message);
                    submitter.error();
                  } else {
                    $('#stripe-id', context).val(result.paymentIntent.id);
                    console.log('Success!!!!');
                    console.log(result.paymentIntent.id);
                    submitter.ready();
                  }
                });
              }
            };
          }
        }
    },

    validateHandler: function(settings, $method, submitter) {
        this.form_id = $method.closest('form').attr('id');

        $('.mo-dialog-wrapper').addClass('visible');
        if (typeof Drupal.clientsideValidation !== 'undefined') {
          $('#clientsidevalidation-' + this.form_id + '-errors ul').empty();
        }

        var getField = function(name) {
            if (name instanceof Array) { name = name.join(']['); }
            return $method.find('[name$="[' + name + ']"]');
        };
        var params = {
            number:     getField('credit_card_number').val(),
            exp_month:  getField(['expiry_date', 'month']).val(),
            exp_year:   getField(['expiry_date', 'year']).val(),
            cvc:        getField('secure_code').val(),
        };

        Stripe.setPublishableKey(settings.public_key);
        if (!this.validateCreditCard(params)) {
          submitter.error();
          return false;
        }

        $method.find('.stripe-extra-data input').each(function() {
          var $input = $(this);
          params[$input.data('stripe')] = $input.val();
        });

        var self = this;
        Stripe.card.createToken(params, function(status, result) {
            if (typeof result.error !== 'undefined') {
                self.errorHandler(result.error.message);
                submitter.error();
            } else {
                $method.find('.stripe-payment-token').val(result.id);
                submitter.ready();
            }
        });
    },

    errorHandler: function(error) {
        var self = this;
        var settings, wrapper, child;
        if (typeof Drupal.clientsideValidation !== 'undefined') {
            settings = Drupal.settings.clientsideValidation['forms'][self.form_id];
            wrapper = document.createElement(settings.general.wrapper);
            child = document.createElement(settings.general.errorElement);
            child.className = settings.general.errorClass;
            child.innerHTML = error;
            wrapper.appendChild(child);

            $('#clientsidevalidation-' + self.form_id + '-errors ul')
            .append(wrapper).show()
            .parent().show();
        } else {
                if ($('#messages').length === 0) {
            $('<div id="messages"><div class="section clearfix">' +
              '</div></div>').insertAfter('#header');
                }
                $('<div class="messages error">' + error + '</div>')
            .appendTo("#messages .clearfix");
        }
    },

    validateCreditCard: function(p) {
        var number = Stripe.card.validateCardNumber(p.number),
            expiry = Stripe.card.validateExpiry(p.exp_month, p.exp_year),
            cvc    = Stripe.card.validateCVC(p.cvc);
        if (!number) { this.errorHandler(Drupal.t('Invalid card number.')); };
        if (!expiry) { this.errorHandler(Drupal.t('Invalid expiry date.')); };
        if (!cvc)    { this.errorHandler(Drupal.t('Invalid CVC.')); };

        return number && expiry && cvc;
    },
};
}(jQuery));
