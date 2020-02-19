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

  /**
   * Test one off payments with Sagepay.
   */
  public function testOneoffPayments() {

    $newTransaction = $this->getNewTransaction();

    $contact = $this->callAPISuccess("Contact", "create", [
      "first_name" => $newTransaction["card"]["firstName"],
      "last_name" => $newTransaction["card"]["lastName"],
      "contact_type" => "Individual"
    ]);

    $processor = $this->callAPISuccess("PaymentProcessor", "create", [
      "payment_processor_type_id" => "omnipay_SagePay_Server",
      "user_name" => "abc",
      "password" => "def",
      "is_test" => 1,
    ]);

    $contributionPage = $this->callAPISuccess("contribution_page", "create",
      $this->getNewContributionPage($processor["id"])
    );

    $priceSet = $this->callAPISuccess("price_set", "getsingle", [
      "name" => "default_contribution_amount"
    ]);

    $contributionPageSubmission = $this->callAPISuccess("contribution_page", "submit",
      $this->getContributionPageSubmission(
        $contributionPage["id"],
        $processor["id"],
        $priceSet["id"]
      )
    );

    $this->assertEquals("implemented", "not");

  }
}
