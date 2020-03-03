[![Build Status](https://travis-ci.com/moreonion/stripe_payment.svg?branch=7.x-2.x)](https://travis-ci.com/moreonion/stripe_payment) [![codecov](https://codecov.io/gh/moreonion/stripe_payment/branch/7.x-2.x/graph/badge.svg)](https://codecov.io/gh/moreonion/stripe_payment)

# Stripe payment

This module integrates [stripe](https://stripe.com) with the Drupal [payment framework](https://drupal.org/project/payment). It enables credit card and SEPA transactions.

## Requirements

* [elements](https://drupal.org/project/elements) ≥ 1.2
* [libraries](https://drupal.org/project/libraries)
* [payment](https://drupal.org/project/payment) ≥ 1.10
* [payment context](https://drupal.org/project/payment_context) 
* [payment forms](https://drupal.org/project/payment_forms)
* [variable](https://drupal.org/project/variable)
* [xautoload](https://drupal.org/project/xautoload) ≥ 5.0
* The [stripe-php](https://github.com/stripe/stripe-php) library.


## Installation

1. Install the module and all its dependencies [as usual](https://www.drupal.org/documentation/install/modules-themes/modules-7)
2. Download the [stripe-php](https://github.com/stripe/stripe-php) library and copy it to the appropriate library folder. Make sure the folder is named `stripe-php`.
3. If needed add JavaScript polyfills to your theme or installation profile.


## Browser compatibility

The JavaScript in this module is transpiled for IE 11 and Edge 18 compatibility. However the required polyfills are not bundled. If you want to support these browsers you’ll neet to load an appropriate polyfill. For example you can use [polyfill.io](https://polyfill.io).
