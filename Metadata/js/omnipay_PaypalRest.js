// @see https://developer.paypal.com/docs/checkout/integrate/
/*jshint esversion: 6 */
(function($, ts) {

  var script = {
    name: 'omnipay',
    scriptLoading: false,

    renderPaypal: function() {
      paypal.Buttons({

        onInit: function (data, actions) {
          // On webform, hide the submit button as it's triggered automatically
          if (CRM.$('[type="submit"].webform-submit').length !== 0) {
            $('[type="submit"].webform-submit').hide();
          }

          $('[type="submit"][formnovalidate="1"]',
            '[type="submit"][formnovalidate="formnovalidate"]',
            '[type="submit"].cancel',
            '[type="submit"].webform-previous'
          ).on('click', function () {
            CRM.payment.debugging(scriptName, 'adding submitdontprocess: ' + this.id);
            CRM.payment.form.dataset.submitdontprocess = 'true';
          });

          $(CRM.payment.getBillingSubmit()).on('click', function () {
            CRM.payment.debugging(scriptName, 'clearing submitdontprocess');
            CRM.payment.form.dataset.submitdontprocess = 'false';
          });

          $(CRM.payment.form).on('submit', function (event) {
            if (CRM.payment.form.dataset.submitdontprocess === 'true') {
              CRM.payment.debugging(scriptName, 'non-payment submit detected - not submitting payment');
              event.preventDefault();
              return true;
            }
            if (document.getElementById('payment_token') && (document.getElementById('payment_token').value !== 'Authorisation token') &&
              document.getElementById('PayerID') && (document.getElementById('PayerID').value !== 'Payer ID')) {
              return true;
            }
            CRM.payment.debugging(scriptName, 'Unable to submit - paypal not executed');
            event.preventDefault();
            return true;
          });

          // Set up the buttons.
          if ($(CRM.payment.form).valid()) {
            actions.enable();
          }
          else {
            actions.disable();
          }

          $(CRM.payment.form)
            .on('blur keyup change', 'input', function (event) {
              if (CRM.vars[script.name] === undefined) {
                return;
              }
              script.paymentProcessorID = CRM.payment.getPaymentProcessorSelectorValue();
              if ((script.paymentProcessorID !== null) && (script.paymentProcessorID !== parseInt(CRM.vars[script.name].id))) {
                return;
              }

              script.debugging('New ID: ' + CRM.vars[script.name].id + ' pubKey: ' + CRM.vars[script.name].publishableKey);

              if ($(CRM.payment.form).valid()) {
                actions.enable();
              }
              else {
                actions.disable();
              }
            });
        },

        createBillingAgreement: function (data, actions) {
          // CRM.payment.getTotalAmount is implemented by webform_civicrm and mjwshared. The plan is to
          //   add CRM.payment.getTotalAmount() into CiviCRM core. This code allows it to work under any of
          //   these circumstances as well as if CRM.payment does not exist.
          var totalAmount = 0.0;
          if ((typeof CRM.payment !== 'undefined') && (CRM.payment.hasOwnProperty('getTotalAmount'))) {
            totalAmount = CRM.payment.getTotalAmount();
          }
          else
            if (typeof calculateTotalFee == 'function') {
              // This is ONLY triggered in the following circumstances on a CiviCRM contribution page:
              // - With a priceset that allows a 0 amount to be selected.
              // - When we are the ONLY payment processor configured on the page.
              totalAmount = parseFloat(calculateTotalFee());
            }
            else
              if (document.getElementById('total_amount')) {
                // The input#total_amount field exists on backend contribution forms
                totalAmount = parseFloat(document.getElementById('total_amount').value);
              }

          var frequencyInterval = $('#frequency_interval').val() || 1;
          var frequencyUnit = $('#frequency_unit')
            .val() ? $('#frequency_interval')
            .val() : CRM.vars.omnipay.frequency_unit;
          var isRecur = $('#is_recur').is(":checked");
          var recurText = isRecur ? ' recurring' : '';
          var qfKey = $('[name=qfKey]', $(CRM.payment.form)).val();

          return new Promise(function (resolve, reject) {
            CRM.api3('PaymentProcessor', 'preapprove', {
                'payment_processor_id': CRM.vars.omnipay.paymentProcessorId,
                'amount': totalAmount,
                'currencyID': CRM.vars.omnipay.currency,
                'qf_key': qfKey,
                'is_recur': isRecur,
                'installments': $('#installments').val(),
                'frequency_unit': frequencyUnit,
                'frequency_interval': frequencyInterval,
                'description': CRM.vars.omnipay.title + ' ' + CRM.formatMoney(totalAmount) + recurText,
              }
            ).then(function (result) {
              if (result.is_error === 1) {
                reject(result.error_message);
              }
              else {
                token = result.values[0].token;
                resolve(token);
              }
            })
              .fail(function (result) {
                reject('Payment failed. Check your site credentials');
              });
          });
        },

        onApprove: function (data, actions) {
          var isRecur = 1;
          var paymentToken = data.billingToken;
          if (!paymentToken) {
            paymentToken = data.paymentID;
            isRecur = 0;
          }

          document.getElementById('paypal-button-container').style.visibility = "hidden";
          var crmSubmitButtons = document.getElementById('crm-submit-buttons');
          if (crmSubmitButtons) {
            crmSubmitButtons.style.display = 'block';
          }
          document.getElementById('PayerID').value = data.payerID;
          document.getElementById('payment_token').value = paymentToken;

          CRM.$(CRM.payment.getBillingSubmit()).click();
        },

        onError: function (err) {
          CRM.payment.debugging(scriptName, err);
          alert('Site is not correctly configured to process payments');
        }

      })
        .render('#paypal-button-container');
    },

    /**
     * Destroy any payment elements we have already created
     */
    destroyPaymentElements: function() {},

    /**
     * Payment processor is not Stripe - cleanup
     */
    notScriptProcessor: function() {
      script.debugging('New payment processor is not ' + script.name + ', clearing CRM.vars.' + script.name);
      script.destroyPaymentElements();
      delete (CRM.vars[script.name]);
      $(CRM.payment.getBillingSubmit()).show();
      CRM.payment.resetBillingFieldsRequiredForJQueryValidate();
    },

    /**
     * Check environment and trigger loadBillingBlock()
     */
    checkAndLoad: function() {
      if (typeof CRM.vars[script.name] === 'undefined') {
        script.debugging('CRM.vars.' + script.name + ' not defined!');
        return;
      }

      if (typeof paypal === 'undefined') {
        if (script.scriptLoading) {
          return;
        }
        script.scriptLoading = true;
        script.debugging('Paypal.js is not loaded!');

        $.ajax({
          url: 'https://www.paypal.com/sdk/js?client-id=' + CRM.vars.omnipay.client_id + '&currency=' + CRM.vars.omnipay.currency + '&commit=false&vault=true',
          dataType: 'script',
          cache: true,
          timeout: 5000,
          crossDomain: true
        })
          .done(function(data) {
            script.scriptLoading = false;
            script.debugging("Script loaded and executed.");
            script.renderPaypal();
          })
          .fail(function() {
            script.scriptLoading = false;
            script.debugging('Failed to load Paypal.js');
            script.triggerEventCrmBillingFormReloadFailed();
          });
      }
      else {
        script.renderPaypal();
      }
    },

    /**
     * Output debug information
     * @param {string} errorCode
     */
    debugging: function(errorCode) {
      CRM.payment.debugging(script.name, errorCode);
    },

    /**
     * Trigger the crmBillingFormReloadFailed event and notify the user
     */
    triggerEventCrmBillingFormReloadFailed: function() {
      CRM.payment.triggerEvent('crmBillingFormReloadFailed');
      CRM.payment.displayError(ts('Could not load payment element - Is there a problem with your network connection?'), true);
    }
  };

  // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
  window.onbeforeunload = null;

  if (CRM.payment.hasOwnProperty(script.name)) {
    return;
  }

  // Currently this just flags that we've already loaded
  var crmPaymentObject = {};
  crmPaymentObject[script.name] = script;
  $.extend(CRM.payment, crmPaymentObject);

  CRM.payment.registerScript(script.name);

  // Re-prep form when we've loaded a new payproc via ajax or via webform
  $(document).ajaxComplete(function (event, xhr, settings) {
    if (CRM.payment.isAJAXPaymentForm(settings.url)) {
      CRM.payment.debugging(script.name, 'triggered via ajax');
      load();
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    CRM.payment.debugging(script.name, 'DOMContentLoaded');
    load();
  });

  /**
   * Called on every load of this script (whether billingblock loaded via AJAX or DOMContentLoaded)
   */
  function load() {
    if (window.civicrmPaypalHandleReload) {
      // Call existing instance of this, instead of making new one.
      CRM.payment.debugging(script.name, "calling existing HandleReload.");
      window.civicrmPaypalHandleReload();
    }
  }

  /**
   * This function boots the UI.
   */
  window.civicrmPaypalHandleReload = function() {
    CRM.payment.scriptName = script.name;
    CRM.payment.debugging(script.name, 'HandleReload');

    // Get the form containing payment details
    CRM.payment.form = CRM.payment.getBillingForm();
    if (typeof CRM.payment.form.length === 'undefined' || CRM.payment.form.length === 0) {
      CRM.payment.debugging(script.name, 'No billing form!');
      return;
    }

    // If we are reloading start with the form submit buttons visible
    // They may get hidden later depending on the element type.
    $(CRM.payment.getBillingSubmit()).show();

    // Load Paypal onto the form.
    var cardElement = document.getElementById('paypal-button-container');
    if ((typeof cardElement !== 'undefined') && (cardElement)) {
      if (!cardElement.children.length) {
        CRM.payment.debugging(script.name, 'checkAndLoad from document.ready');
        script.checkAndLoad();
      }
      else {
        CRM.payment.debugging(script.name, 'already loaded');
      }
    }
    else {
      script.notScriptProcessor();
      CRM.payment.triggerEvent('crmBillingFormReloadComplete', script.name);
    }
  };

})(CRM.$, CRM.ts('nz.co.fuzion.omnipaymultiprocessor'));
