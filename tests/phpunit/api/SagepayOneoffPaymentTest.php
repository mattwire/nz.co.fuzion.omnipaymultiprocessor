<?php

use CRM_Omnipaymultiprocessor_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Psr7\Response;

/**
 * 
 *
 * @group headless
 */
class SagepayOneoffPaymentTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;
  use HttpClientTestTrait;
  use SagepayTestTrait;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();

    $this->_new = $this->getNewTransaction();

    $this->_contact = $this->callAPISuccess("Contact", "create", [
      "first_name" => $this->_new["card"]["firstName"],
      "last_name" => $this->_new["card"]["lastName"],
      "contact_type" => "Individual",
      "sequential" => 1,
      ])["values"][0];

    $this->_processor = $this->callAPISuccess("PaymentProcessor", "create", [
      "payment_processor_type_id" => "omnipay_SagePay_Server",
      "user_name" => "abc",
      "password" => "def",
      "is_test" => 1,
      "sequential" => 1,
    ])["values"][0];

    $this->_contribution = $this->callAPISuccess("Contribution", "create", [
      "contact_id" => $this->_contact["id"],
      "contribution_status_id" => "Pending",
      "financial_type_id" => "Donation",
      "receive_date" => "today",
      "total_amount" => $this->_new["amount"],
      "sequential" => 1,
    ])["values"][0];
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * When a payment is made, the Sagepay transaction identifier `VPSTxId`,
   * a secret security key `SecurityKey` and the corresponding `qfKey`
   * must be saved as part of the `trxn_id` JSON.
   */
  public function testSavesImportantFieldsInTrxnId() {
    Civi::$statics["Omnipay_Test_Config"] = [ "client" => $this->getHttpClient() ];

    $this->setMockHttpResponse("SagepayOneoffPaymentSecret.txt");
    $transactionSecret = $this->getSagepayTransactionSecret();

    $payment = $this->callAPISuccess("PaymentProcessor", "pay", [
      "payment_processor_id" => $this->_processor["id"],
      "amount" => $this->_new["amount"],
      "qfKey" => $this->getQfKey(),
      "currency" => $this->_new["currency"],
      "component" => "contribute",
      "email" => $this->_new["card"]["email"],
      "contactID" => $this->_contact["id"],
      "contributionID" => $this->_contribution["id"],
      "contribution_id" => $this->_contribution["id"],
    ]);

    $contribution = $this->callAPISuccess("Contribution", "get", [
      "return" => [ "trxn_id" ],
      "contact_id" => $this->_contact["id"],
      "sequential" => 1,
    ]);

    /*$this->setMockHttpResponse("SagepayOneoffPaymentSuccess.txt");
    $handler = new CRM_Core_Payment_OmnipayMultiProcessor('test', $this->_processor);
    $handler->processPaymentNotification($this->getSagepayPaymentConfirmation($this->_processor["id"]));*/

    $trxnId = json_decode($contribution["values"][0]["trxn_id"], TRUE);

    $this->assertEquals($trxnId["SecurityKey"], $transactionSecret["SecurityKey"]);
    $this->assertEquals($trxnId["VPSTxId"], $transactionSecret["VPSTxId"]);
    $this->assertEquals($trxnId["qfKey"], $this->getQfKey());
  }

}
