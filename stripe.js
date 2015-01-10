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

        $form = $('.webform-client-form #payment-method-all-forms', context)
            .closest('form.webform-client-form', document);

        // the current webform page, does not contain a paymethod-selector.
        if (!$form.length) { return; }

        if ($('.mo-dialog-wrapper').length === 0) {
            $('<div class="mo-dialog-wrapper"><div class="mo-dialog-content">'+
              '</div></div>').appendTo('body');
        }

        self.$form = $form;
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
        var $form = self.$form;
        var $method = $form.find('.payment-method-form:visible');

        // Some non-stripe method was selected, do nothing on submit.
        if (!($method.data('pmid') in self.settings)) {
            return true;
        }
        event.preventDefault();
        event.stopImmediatePropagation();

        if (typeof Drupal.clientsideValidation !== 'undefined') {
          $('#clientsidevalidation-' + self.form_id + '-errors ul').empty();
        }

        $('.mo-dialog-wrapper').addClass('visible');

        var getField = function(name) {
            if (name instanceof Array) { name = name.join(']['); }
            return $method.find('[name$="[' + name + ']"]');
        };
        params = {
            number:     getField('credit_card_number').val(),
            exp_month:  getField(['expiry_date', 'month']).val(),
            exp_year:   getField(['expiry_date', 'year']).val(),
            cvc:        getField('secure_code').val(),
        };

        Stripe.setPublishableKey(self.settings[$method.data('pmid')].public_key);
        if (!self.validateCreditCard(params)) { return; }

        Stripe.card.createToken(params, function(status, result) {
            var self = Drupal.behaviors.stripe_payment;
            var ajax, button_id;
            if (typeof result.error !== 'undefined') {
                self.errorHandler(result.error.message);
            } else {
                $form.find('.stripe-payment-token').val(result.id);
                button_id = $(event.target).attr('id');

                if (Drupal.ajax && Drupal.ajax[button_id]) {
                    ajax = Drupal.ajax[button_id];
		                ajax.eventResponse(ajax.element, event);
                } else { // no webform_ajax
		                $form.submit()
		            }
            }
        });
	      return false;
    },

    errorHandler: function(error) {
        var self = Drupal.behaviors.stripe_payment;
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

        if (number && expiry && cvc) { return true; }
        else { return false; };
    },
};
}(jQuery));
