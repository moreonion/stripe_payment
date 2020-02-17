<?php

namespace Drupal\stripe_payment;

use Drupal\little_helpers\ArrayConfig;
use Drupal\little_helpers\ElementTree;

/**
 * Form for customer data that’s used for 3DS and billing.
 */
class CustomerDataForm {

  /**
   * Return default field input settings.
   */
  public function defaultSettings() {
    return [
      'billing_details' => [
        'name' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['name'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'first_name' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['first_name'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'last_name' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['last_name'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'email' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['email'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'phone' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['phone', 'phone_number', 'mobile_number'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'address_line1' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['street_address', 'address_line1'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'address_line2' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['address_line2'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'postcode' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['postcode', 'zip_code'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'country' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['country'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'city' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['city'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
        'state' => [
          'enabled' => TRUE,
          'display' => 'hidden',
          'keys' => ['region'],
          'required' => FALSE,
          'display_other' => 'hidden',
        ],
      ],
    ];
  }

  /**
   * Defines Stripe fields in a form-API like structure.
   *
   * Special #-attributes are used to denote where the data is sent to stripe.
   */
  public function fields() {
    require_once DRUPAL_ROOT . '/includes/locale.inc';
    $fields = [
      '#type' => 'container',
    ];
    $fields['billing_details'] = [
      '#type' => 'fieldset',
      '#title' => t('Billing details'),
    ];
    $fields['billing_details']['name'] = [
      '#type' => 'textfield',
      '#title' => t('Full name'),
      '#stripe_field' => 'billing_details.name',
      '#stripe_customer_field' => 'name',
    ];
    $fields['billing_details']['first_name'] = [
      '#type' => 'textfield',
      '#title' => t('First name'),
      '#stripe_customer_field' => 'first_name',
    ];
    $fields['billing_details']['last_name'] = [
      '#type' => 'textfield',
      '#title' => t('Last name'),
      '#stripe_customer_field' => 'last_name',
    ];
    $fields['billing_details']['email'] = [
      '#type' => 'emailfield',
      '#title' => t('Email'),
      '#stripe_field' => 'billing_details.email',
      '#stripe_customer_field' => 'email',
    ];
    $fields['billing_details']['phone'] = [
      '#type' => 'textfield',
      '#title' => t('Phone'),
      '#stripe_field' => 'billing_details.phone',
      '#stripe_customer_field' => 'phone',
    ];
    $fields['billing_details']['address_line1'] = [
      '#type' => 'textfield',
      '#title' => t('Address line 1'),
      '#stripe_field' => 'billing_details.address.line1',
      '#stripe_customer_field' => 'address.line1',
    ];
    $fields['billing_details']['address_line2'] = [
      '#type' => 'textfield',
      '#title' => t('Address line 2'),
      '#stripe_field' => 'billing_details.address.line2',
      '#stripe_customer_field' => 'address.line2',
    ];
    $fields['billing_details']['postcode'] = [
      '#type' => 'textfield',
      '#title' => t('Postal code'),
      '#stripe_field' => 'billing_details.address.postal_code',
      '#stripe_customer_field' => 'address.postal_code',
    ];
    $fields['billing_details']['country'] = [
      '#type' => 'select',
      '#options' => country_get_list(),
      '#title' => t('Country'),
      '#stripe_field' => 'billing_details.address.country',
      '#stripe_customer_field' => 'address.country',
    ];
    $fields['billing_details']['city'] = [
      '#type' => 'textfield',
      '#title' => t('City/Locality'),
      '#stripe_field' => 'billing_details.address.city',
      '#stripe_customer_field' => 'address.city',
    ];
    $fields['billing_details']['state'] = [
      '#type' => 'textfield',
      '#title' => t('State/County/Province/Region'),
      '#stripe_field' => 'billing_details.address.state',
      '#stripe_customer_field' => 'address.state',
    ];
    return $fields;
  }

  /**
   * Get display options for a customer data field.
   */
  public function displayOptions($required) {
    $display_options = [
      'ifnotset' => t('Show field if it is not available from the context.'),
      'always' => t('Always show the field - prefill with context values.'),
    ];
    if (!$required) {
      $display_options['hidden'] = t('Don’t display, use values from context if available.');
    }
    return $display_options;
  }

  /**
   * Get the input settings configuration form.
   */
  public function configurationForm(array $settings) {
    ArrayConfig::mergeDefaults($settings, $this->defaultSettings());
    $form['#type'] = 'container';
    // Configuration for extra data elements.
    $extra = $this->fields();
    $extra['#settings_element'] = &$form;
    $extra['#settings_defaults'] = $settings;
    $extra['#settings_root'] = TRUE;
    ElementTree::applyRecursively($extra, function (&$element, $key, &$parent) {
      if (!$key) {
        // Skip the root element.
        return;
      }
      else {
        $element['#settings_defaults'] = $parent['#settings_defaults'][$key];
      }
      if (in_array($element['#type'], ['fieldset', 'container'])) {
        $fieldset = [
          '#collapsible' => TRUE,
          '#collapsed' => TRUE,
        ] + $element;
      }
      else {
        $defaults = $element['#settings_defaults'];
        $fieldset = [
          '#type' => 'fieldset',
          '#title' => $element['#title'],
          '#collapsible' => TRUE,
          '#collapsed' => TRUE,
        ];
        $required = !empty($element['#required']);
        $defaults['required'] = $defaults['required'] || $required;
        $enabled_id = drupal_html_id('controller_data_enabled_' . $key);
        $fieldset['enabled'] = [
          '#type' => 'checkbox',
          '#title' => t('Enabled: Make this field available to stripe.'),
          '#default_value' => $defaults['enabled'] || $required,
          '#id' => $enabled_id,
          '#access' => !$required,
        ];
        $display_id = drupal_html_id('controller_data_display_' . $key);
        $fieldset['display'] = [
          '#type' => 'radios',
          '#title' => t('Display'),
          '#options' => $this->displayOptions($required),
          '#default_value' => $defaults['display'],
          '#id' => $display_id,
          '#states' => ['visible' => ["#$enabled_id" => ['checked' => TRUE]]],
        ];
        if (empty($parent['#settings_root'])) {
          $fieldset['display_other'] = [
            '#type' => 'radios',
            '#title' => t('Display when other fields in the same fieldset are visible.'),
            '#options' => $this->displayOptions($required),
            '#default_value' => $defaults['display_other'],
            '#states' => [
              'invisible' => ["#$display_id" => ['value' => 'always']],
              'visible' => ["#$enabled_id" => ['checked' => TRUE]],
            ],
          ];
        }
        $fieldset['required'] = [
          '#type' => 'checkbox',
          '#title' => t('Required'),
          '#states' => ['disabled' => ["#$display_id" => ['value' => 'hidden']]],
          '#default_value' => $defaults['required'],
          '#access' => !$required,
          '#states' => ['visible' => ["#$enabled_id" => ['checked' => TRUE]]],
        ];
        $fieldset['keys'] = [
          '#type' => 'textfield',
          '#title' => t('Context keys'),
          '#description' => t('When building the form these (comma separated) keys are used to ask the Payment Context for a (default) value for this field.'),
          '#default_value' => implode(', ', $defaults['keys']),
          '#element_validate' => ['_stripe_payment_validate_comma_separated_keys'],
          '#states' => ['visible' => ["#$enabled_id" => ['checked' => TRUE]]],
        ];
      }
      $parent['#settings_element'][$key] = &$fieldset;
      $element['#settings_element'] = &$fieldset;
    });
    return $form;
  }

  /**
   * Generate the form elements for the customer data.
   */
  public function form(array $settings, $context) {
    ArrayConfig::mergeDefaults($settings, $this->defaultSettings());
    $data_fieldset = $this->fields();
    $data_fieldset['#settings'] = $settings;

    // Recursively set #settings and remove #required.
    ElementTree::applyRecursively($data_fieldset, function (&$element, $key, &$parent) {
      if ($key) {
        $element['#settings'] = $parent['#settings'][$key];
      }
      $element['#controller_required'] = !empty($element['#required']) || !empty($element['#settings']['required']);
      if ($element['#controller_required']) {
        $element['#attributes']['data-controller-required'] = 'required';
      }
      unset($element['#required']);
      if (!empty($element['#stripe_field'])) {
        $element['#attributes']['data-stripe'] = $element['#stripe_field'];
      }
      $element['#user_visible'] = FALSE;

    });

    // Set default values from context.
    ElementTree::applyRecursively($data_fieldset, function (&$element, $key) use ($context) {
      if (!in_array($element['#type'], ['container', 'fieldset'])) {
        $element['#default_value'] = '';
        foreach ($element['#settings']['keys'] as $k) {
          if ($value = $context->value($k)) {
            $element['#default_value'] = $value;
            break;
          }
        }
      }
    });

    // Stripe only uses the name attribute instead of first_name/last_name.
    $bd = &$data_fieldset['billing_details'];
    if (empty($bd['name']['#default_value'])) {
      $bd['name']['#default_value'] = trim($bd['first_name']['#default_value'] . ' ' . $bd['last_name']['#default_value']);
    }

    $display = function ($element, $key, $mode = 'display') {
      $d = $element['#settings'][$mode];
      return ($d == 'always') || (empty($element['#default_value']) && $d == 'ifnotset');
    };

    // Set visibility.
    ElementTree::applyRecursively($data_fieldset, function (&$element, $key, &$parent) use ($display) {
      $element += ['#access' => FALSE];
      $is_container = in_array($element['#type'], ['fieldset', 'container']);
      if (!$is_container) {
        $element['#access'] = $element['#settings']['enabled'];
      }
      // If an element is accessible its parent should be visible too.
      if ($parent && $element['#access']) {
        $parent['#access'] = TRUE;
      }

      if (!$is_container) {
        $element['#user_visible'] = $display($element, $key, 'display');
      }
      if ($element['#user_visible'] && $parent) {
        $parent['#user_visible'] = TRUE;
      }
    }, TRUE);
    // Reset visibility if there are visible elements in the same fieldset.
    ElementTree::applyRecursively($data_fieldset, function (&$element, $key, &$parent) use ($display) {
      if ($parent && $parent['#user_visible']) {
        // Give child elements of visible fieldsets a chance to be displayed.
        if ($element['#type'] != 'fieldset' && !$element['#user_visible']) {
          $element['#user_visible'] = $display($element, $key, 'display_other');
        }
      }
    });
    // Transform elements that should not be visible for the user.
    ElementTree::applyRecursively($data_fieldset, function (&$element, $key, &$parent) use ($display) {
      if ($key && !$element['#user_visible']) {
        if ($element['#type'] == 'fieldset') {
          $element['#type'] = 'container';
        }
        else {
          $element += ['#default_value' => ''];
          $element['#type'] = 'hidden';
          $element['#value'] = $element['#default_value'];
        }
      }
    });
    return $data_fieldset;
  }

  /**
   * Extract customer data from a submitted form element.
   */
  public function getData($element) {
    $customer = [];
    ElementTree::applyRecursively($element, function (&$element, $key, &$parent) use (&$customer) {
      if (isset($element['#stripe_customer_field'])) {
        $this->deepSet($customer, explode('.', $element['#stripe_customer_field']), $element['#value']);
      }
    });
    if (empty($customer['address']['line1'])) {
      // If an address is passed then line1 is mandatory.
      unset($customer['address']);
    }
    if (empty($customer['name'])) {
      $customer['name'] = trim($customer['first_name'] . ' ' . $customer['last_name']);
    }
    unset($customer['first_name']);
    unset($customer['last_name']);
    return $customer;
  }

  /**
   * Helper for recursively settings values in arrays.
   */
  protected function deepSet(&$data, $keys, $value) {
    $key = array_shift($keys);
    if ($keys) {
      $data += [$key => []];
      $this->deepSet($data[$key], $keys, $value);
    }
    else {
      $data[$key] = $value;
    }
  }

}
