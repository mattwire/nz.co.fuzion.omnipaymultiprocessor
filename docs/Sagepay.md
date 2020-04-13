# Sagepay

## Create a One-Off Transaction

### Prepare for Registering a Transaction

Before registering a transaction, we are meant to prepare it by
creating a `CreditCard` object, which should look more or less
like that:

```json
{
    "firstName": "Mary",
    "lastName": "Smith",
    "billingAddress1": "Testington Passage",
    "billingCity": "Mountaintown",
    "billingPostcode": "986DD",
    "billingCountry": "ES",
    "billingPhone": "399 904 130956",
    "email": "mary.smith@example.dom",
    "clientIp": "124.159.204.143",
    "shippingAddress1": "Testington Passage",
    "shippingCity": "Mountaintown",
    "shippingPostcode": "F791B",
    "shippingCountry": "BR",
    "shippingPhone": "167 111 141295"
}
```

### Register the Transaction

To create a new transaction using Sagepay you'll need to provide a `notifyUrl` where
Sagepay servers will redirect the user after the payment is finished. This is the
URL that will receive a notification with the payment result, so in our case it
is the IPN.

We'll call `purchase` like in this example:

```php
$response = $gateway->purchase($purchase)->send();
```

There our `$purchase` object would look like this:

```json
{
    "amount": 0.99,
    "currency": "GBP",
    "card": {},
    "notifyUrl": "http:\/\/your.server.com\/civicrm\/ipn\/1",
    "transactionId": "EDE57602DE5052A1CB45",
    "description": "Unreal product for testing purposes",
    "items": [
        {
            "name": "test-purchase",
            "description": "Unreal product for testing purposes",
            "quantity": 1,
            "price": 0.99
        }
    ]
}
```

As shown above, it's also important to provide a `transactionId`.

### Purchase Answer

If the Sagepay account was properly set up, the previous example would
result in a `$response` like this one:

```json
{
    "VPSProtocol": "3.00",
    "Status": "OK",
    "StatusDetail": "2014 : The Transaction was Registered Successfully.",
    "VPSTxId": "{05C1C3I1-1D3B-4212-C575-68231E73017B}",
    "SecurityKey": "BO9CYMN7RA",
    "NextURL": "https:\/\/test.sagepay.com\/gateway\/service\/cardselection?vpstxid={05C1C3I1-1D3B-4212-C575-68231E73017B}"
}
```

Even before the payment is attempted, Sagepay servers will give us a `SecurityKey`
that we must store with the transaction and use to check a further payment
notifications.

The Sagepay module for the CiviCRM Omnipay extension stores this as part
of the JSON saved in the `trxn_id` field.

### Redirect the User

Now, the user should be redirected by our site to `NextURL`, where Sagepay
servers will take care of asking the credit card details and completing
the payment.

Even in a successful case, our server would be free until the payment is
completed.

### Process an Unsuccessful Payment

If the user abandoned the previous payment, we would receive a time out message:

```json
{
    "VPSProtocol": "3.00",
    "TxType": "PAYMENT",
    "VendorTxCode": "EDE57602DE5052A1CB45",
    "VPSTxId": "{05C1C3I1-1D3B-4212-C575-68231E73017B}",
    "Status": "ABORT",
    "StatusDetail": "2008 : The Transaction timed-out.",
    "AVSCV2": "DATA NOT CHECKED",
    "AddressResult": "NOTPROVIDED",
    "PostCodeResult": "NOTPROVIDED",
    "CV2Result": "NOTPROVIDED",
    "GiftAid": "0",
    "3DSecureStatus": "NOTCHECKED",
    "VPSSignature": "837F23A1662D09724D35DDBF2E896970"
}
```

### Process a Successful Payment

Given this example purchase:

```json
{
    "amount": 0.99,
    "currency": "GBP",
    "card": [],
    "notifyUrl": "http:\/\/your.server.com\/civicrm\/ipn\/1",
    "transactionId": "A272CDF1DBC623C70806",
    "description": "Unreal product for testing purposes",
    "items": [
        {
            "name": "test-purchase",
            "description": "Unreal product for testing purposes",
            "quantity": 1,
            "price": 0.99
        }
    ]
}
```

and also given this example response:

```json
{
    "VPSProtocol": "3.00",
    "Status": "OK",
    "StatusDetail": "2014 : The Transaction was Registered Successfully.",
    "VPSTxId": "{A135H747-D177-4701-2B14-DE273DFE7FEF}",
    "SecurityKey": "CDUMRQCFJG",
    "NextURL": "https:\/\/test.sagepay.com\/gateway\/service\/cardselection?vpstxid={A135H747-D177-4701-2B14-DE273DFE7FEF}"
}
```

If the user was redirected to the `NextURL` and completed the
payment successfully, we would have received a payment notification
like this one:

```json
{
    "VPSProtocol": "3.00",
    "TxType": "PAYMENT",
    "VendorTxCode": "A272CDF1DBC623C70806",
    "VPSTxId": "{A135H747-D177-4701-2B14-DE273DFE7FEF}",
    "Status": "OK",
    "StatusDetail": "0000 : The Authorisation was Successful.",
    "TxAuthNo": "5333126",
    "AVSCV2": "SECURITY CODE MATCH ONLY",
    "AddressResult": "NOTMATCHED",
    "PostCodeResult": "NOTMATCHED",
    "CV2Result": "MATCHED",
    "GiftAid": "0",
    "3DSecureStatus": "NOTCHECKED",
    "CardType": "VISA",
    "Last4Digits": "0006",
    "VPSSignature": "36AEA188C388ABDBD353EC06B84C2F2D",
    "DeclineCode": "00",
    "ExpiryDate": "0124",
    "BankAuthCode": "999777"
}
```

When receiving a notification, a `VPSSignature` will be included. The Omnipay library
is able to check it if it has access to the `SecurityKey` that we were given previously.

## Repeat a Transaction

If our Sagepay account has **continuous authority** support, once we've completed
a transaction like the one just described before, we'd be able to repeat it
by calling `repeatAuthorize` using information from the previously sent
transaction:

```php
$request = $gateway->repeatAuthorize([
    // To identify the previous transaction
    'relatedTransactionId' => $prev['VendorTxCode'],
    'securityKey' => $prev['SecurityKey'],
    'vpsTxId' => $prev['VPSTxId'],
    'txAuthNo' => $prev['TxAuthNo'],
    // To create the new one
    'amount' => 0.99,
    'transactionId' => $newTransactionId,
    'currency' => 'GBP',
    'description' => 'Repeated transaction',
]);

$response = $request->send();

$responseData = $response->getData();
```

In this case, we'd directly obtain an answer telling us if the new transaction
was authorised, or not. A successful answer for the previous one would look
like this:

```json
{
    "VPSProtocol": "3.00",
    "Status": "OK",
    "StatusDetail": "0000 : The Authorisation was Successful.",
    "VPSTxId": "{C144B485-DE73-26F2-A9F0-393075B0FB69}",
    "SecurityKey": "0Y1BJDJ4AB",
    "TxAuthNo": "5333130",
    "BankAuthCode": "999777"
}
```