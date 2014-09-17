(function ($) {
Drupal.behaviors.stripe_payment = {
    attach: function(context, settings) {
        var self = this;
        if (typeof self.settings === 'undefined') {
            self.settings = settings.stripe_payment;
            self.context = context;
        }

        if (typeof Stripe == 'undefined') {
            $.getScript('https://js.stripe.com/v2/').done(function() {
                self.attach();
            });
            return;
        }

        Stripe.setPublishableKey(self.settings.public_key);

        $form = $('.webform-client-form #payment-method-all-forms', context)
            .closest('form.webform-client-form', document);

        // the current webform page, does not contain a paymethod-selector.
        if (!$form.length) { return; }

        if ($('.mo-dialog-wrapper').length === 0) {
            $('<div class="mo-dialog-wrapper"><div class="mo-dialog-content">'+
              '</div></div>').appendTo('body');
        }

        self.form_id = $form.attr('id');
        self.form_num = self.form_id.split('-')[3];
        self.$button = $form.find('#edit-webform-ajax-submit-' + self.form_num);

	      if (self.$button.length === 0) { // no webform_ajax.
	          self.$button = $form.find('input.form-submit');
	      }
        self.$button.unbind('click');
        self.$button.click(self.submitHandler);
    },

    submitHandler: function(event) {
        var params;
        var self = Drupal.behaviors.stripe_payment;
        var controller = $('#' + self.form_id +
                           ' .payment-method-form:visible').attr('id');

        // Some non-paymill method was selected, do nothing on submit.
        if (controller !== 'Drupalstripe-paymentCreditCardController') {
            return true;
        }
        event.preventDefault();
        event.stopImmediatePropagation();

        $('.mo-dialog-wrapper').addClass('visible');

        var getField = function(name) {
            if (name instanceof Array) { name = name.join(']['); }
            return $('[name="submitted[paymethod_select]' +
                     '[payment_method_all_forms][' + controller + '][' +
                     name + ']"]');
        };
        params = {
            number:     getField('credit_card_number').val(),
            exp_month:  getField(['expiry_date', 'month']).val(),
            exp_year:   getField(['expiry_date', 'year']).val(),
            cvc:        getField('secure_code').val(),
        };
        if (!self.validateCreditCard(params)) { return; }

        Stripe.card.createToken(params, function(status, result) {
            var self = Drupal.behaviors.stripe_payment;
            var ajax, ajax_next, ajax_submit;
            if (result.error) {
                self.errorHandler(result.error.message);
            } else {
                $('#' + self.form_id + ' .stripe-payment-token').val(result.token);
		            ajax_next = 'edit-webform-ajax-next-'+self.form_num;
		            ajax_submit = 'edit-webform-ajax-submit-'+self.form_num;

                if (Drupal.ajax && Drupal.ajax[ajax_submit]) {
                    ajax = Drupal.ajax[ajax_submit];
		                ajax.eventResponse(ajax.element, event);
                } else if (Drupal.ajax && Drupal.ajax[ajax_next]) {
                    ajax = Drupal.ajax[ajax_next];
		                ajax.eventResponse(ajax.element, event);
                } else { // no webform_ajax
		                $('#' + self.form_id).submit()
		            }
            }
        });
	      return false;
    },

    errorHandler: function(error) {
        var self = Drupal.behaviors.stripe_payment;
        if ($('#messages').length === 0) {
            $('<div id="messages"><div class="section clearfix">' +
              '</div></div>').insertAfter('#header');
        }
        $('<div class="messages error">' + error + '</div>')
            .appendTo("#messages .clearfix");
        console.error(error);
    },

    validateCreditCard: function(p) {
        var number = Stripe.card.validateCardNumber(p.number),
            expiry = Stripe.card.validateExpiry(p.exp_month, p.exp_year),
            cvc    = Stripe.card.validateCVC(p.cvc);
        if (!number) { this.errorHandler(Drupal.t('Invalid card number.')); };
        if (!expiry) { this.errorHandler(Drupal.t('Invalid expiry date.')); };
        if (!cvc)    { this.errorHandler(Drupal.t('Invalid CVC.')); };

        if (number && expiry && cvc) { return true; }
        else { return false; };
    },
};
}(jQuery));
