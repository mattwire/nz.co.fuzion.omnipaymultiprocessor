<?php
/**
 * Pass all due recurring contributions to the processor to action (if possible).
 *
 * @param array $params
 *
 * @return array
 *   API result array.
 * @throws CiviCRM_API3_Exception
 */
function civicrm_api3_job_process_recurring($params) {
  $omnipayProcessors = civicrm_api3('PaymentProcessor', 'get', ['class_name' => 'Payment_OmnipayMultiProcessor', 'domain_id' => CRM_Core_Config::domainID()]);
  $recurringPayments = civicrm_api3('ContributionRecur', 'get', [
    'next_sched_contribution_date' => ['BETWEEN' => [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')]],
    'payment_processor_id' => ['IN' => array_keys($omnipayProcessors['values'])],
    'contribution_status_id' => ['IN' => ['In Progress', 'Pending', 'Overdue']],
    'options' => ['limit' => 0],
  ]);

  $result = [];
  foreach ($recurringPayments['values'] as $recurringPayment) {
    $paymentProcessorID = $recurringPayment['payment_processor_id'];
    try {
      $originalContribution = civicrm_api3('Contribution', 'getsingle', [
        'contribution_recur_id' => $recurringPayment['id'],
        'options' => ['limit' => 1],
        'is_test' => CRM_Utils_Array::value('is_test', $recurringPayment['is_test']),
        'contribution_test' => CRM_Utils_Array::value('is_test', $recurringPayment['is_test']),
      ]);
      $result[$recurringPayment['id']]['original_contribution'] = $originalContribution;
      $pending = civicrm_api3('Contribution', 'repeattransaction', [
        'original_contribution_id' => $originalContribution['id'],
        'contribution_status_id' => 'Pending',
        'payment_processor_id' => $paymentProcessorID,
        'is_email_receipt' => FALSE,
      ]);

      $paymentParams = [
        'amount' => $originalContribution['total_amount'],
        'currency' => $originalContribution['currency'],
        'payment_processor_id' => $paymentProcessorID,
        'contributionID' => $pending['id'],
        'contribution_id' => $pending['id'],
        'contactID' => $originalContribution['contact_id'],
        'description' => ts('Repeat payment, original was ' . $originalContribution['id']),
        'payment_action' => 'purchase',
      ];

      $paymentProcessor = $omnipayProcessors['values'][$paymentProcessorID];

      if (!is_continuous_authority($paymentProcessor['payment_processor_type_id'])) {
        $paymentParams['token'] = civicrm_api3('PaymentToken', 'getvalue', [
          'id' => $recurringPayment['payment_token_id'],
          'return' => 'token',
        ]);
      } else {
        $paymentParams['original_contribution_trxn_id'] = $originalContribution['trxn_id'];
        $paymentParams['continuous_authority_repeat'] = true;
      }

      $payment = civicrm_api3('PaymentProcessor', 'pay', $paymentParams);
      $payment = reset($payment['values']);

      civicrm_api3('Contribution', 'completetransaction', [
        'id' => $pending['id'],
        'trxn_id' => $payment['trxn_id'],
        'payment_processor_id' => $paymentProcessorID,
      ]);
      $result['success']['ids'] = $recurringPayment['id'];
    }
    catch (CiviCRM_API3_Exception $e) {
      // Failed - what to do?
      civicrm_api3('ContributionRecur', 'create', [
        'id' => $recurringPayment['id'],
        'failure_count' => $recurringPayment['failure_count'] + 1,
      ]);
      civicrm_api3('Contribution', 'create', [
          'id' => $pending['id'],
          'contribution_status_id' => 'Failed',
          'debug' => $params['debug'] ?? 0,
        ]
      );
      $result[$recurringPayment['id']]['error'] = $e->getMessage();
      $result['failed']['ids'] = $recurringPayment['id'];
    }
  }
  return civicrm_api3_create_success($result, $params);
}

/**
 * Action Payment.
 *
 * @param array $params
 *
 * @return array
 */
function _civicrm_api3_job_process_recurring_spec(&$params) {
}

/**
 * Checks if a given payment processor type is configured to work as
 * a continuous authority.
 *
 * @param array $processorTypeId Id of the processor type
 *
 * @return boolean
 */
function is_continuous_authority($processorTypeId) {
  $paymentProcessorType = civicrm_api3('PaymentProcessorType', 'get', array(
    'id' => $processorTypeId,
    'sequential' => 1,
  ));

  $property = get_processor_type_property(
    $paymentProcessorType['values'][0]['name'],
    'continuous_authority'
  );

  if($property['found']) {
    return $property['value'] == true;
  } else {
    return false;
  }
}

/**
 * Gets the value of a property for a given payment processor type.
 *
 * @param string $processorTypeName Name of the payment processor type
 * @param string $propertyName Property name
 *
 * @return array Array containing a boolean property `found`
 *    with a false value if the property isn't defined for
 *    the given processor type, or true otherwise.
 *    If the property is defined, the array will also contain
 *    its `value`
 */
function get_processor_type_property($processorTypeName, $propertyName) {
  $processorTypeMetadata = get_processor_type_metadata($processorTypeName);
  if(!array_key_exists('metadata', $processorTypeMetadata)) {
    return array('found' => false);
  }
  if(!array_key_exists($propertyName, $processorTypeMetadata['metadata'])) {
    return array('found' => false);
  }
  return array(
    'found' => true,
    'value' => $processorTypeMetadata['metadata'][$propertyName],
  );
}

/**
 * Get the metadata associated with a processor type.
 *
 * @param string $processorTypeName Name of the payment processor type
 *
 * @return mixed Metadata for the given payment processor type
 */
function get_processor_type_metadata($processorTypeName) {
  $processors = [];
  omnipaymultiprocessor_civicrm_managed($processors);
  $filter_by_name = function($processor) use($processorTypeName) {
    return filter_processor_type_by_name($processorTypeName, $processor);
  };
  return array_values(array_filter($processors, $filter_by_name))[0];
}

/**
 * Returns true if a managed entity represents a processor type with
 * a given name.
 *
 * @param string $processorTypeName Name of the payment processor type
 * @param array $processor Managed entity being evaluated
 *
 * @return boolean
 */
function filter_processor_type_by_name($processorTypeName, $processor) {
  if($processor['entity'] != 'payment_processor_type') { return false; }
  if(!array_key_exists('params', $processor)) { return false; }
  if(!array_key_exists('name', $processor['params'])) { return false; }
  return $processor['params']['name'] == $processorTypeName;
}
