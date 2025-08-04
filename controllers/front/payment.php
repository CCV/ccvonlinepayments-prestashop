<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CcvOnlinePaymentsPaymentModuleFrontController extends ModuleFrontController
{

    public function initContent() : void
    {
        $cart = $this->context->cart;

        /** @var Link $link */
        $link = Context::getContext()?->link;

        if ($cart === null || $cart->id_customer === 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect($link->getPageLink('index', true));
            return;
        }

        $errors = [];

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $enabled = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'ccvonlinepayments') {
                $enabled = true;
            }
        }

        if(!$enabled) {
            $errors[] = $this->trans('This payment method is not available.', [], "Modules.Ccvonlinepayments.Shop");
        }

        $amount = $cart->getOrderTotal(
            true,
            Cart::BOTH
        );
        if(!$amount) {
            Tools::redirect($link->getPageLink('index', true));
            return;
        }

        if (sizeof($errors) > 0) {
            foreach($errors as $error) {
                $this->errors[] = $error;
            }

            /** @phpstan-ignore-next-line arguments.count */
            $this->redirectWithNotifications($this->context->link->getPagelink('order', true, null, array('step' => 3)));
            return;
        }

        $orderReference = Order::generateReference();

        /** @var CcvOnlinePayments $module */
        $module = $this->module;
        $ccvOnlinePaymentsApi = $module->getApi();

        $methodId = Tools::getValue('method');
        $method = $ccvOnlinePaymentsApi->getMethodById($methodId);
        if($method === null) {
            throw new Exception("Method not found");
        }

        if($method->isTransactionTypeSaleSupported()) {
            $transactionType = \CCVOnlinePayments\Lib\Enum\TransactionType::SALE;
        }elseif($method->isTransactionTypeAuthoriseSupported()){
            $transactionType = \CCVOnlinePayments\Lib\Enum\TransactionType::AUTHORIZE;
        }else{
            throw new \Exception("No transaction types supported");
        }

        $currencyCode = $this->context->currency?->iso_code;
        if($currencyCode === null) {
            throw new Exception("No currency found");
        }

        $paymentRequest = new \CCVOnlinePayments\Lib\PaymentRequest();
        $paymentRequest->setTransactionType($transactionType);
        $paymentRequest->setAmount($amount);
        $paymentRequest->setCurrency(strtoupper($currencyCode));
        $paymentRequest->setMerchantOrderReference($orderReference);
        $paymentRequest->setReturnUrl($link->getModuleLink(
            "ccvonlinepayments",
            "return",
            array("cartId" => $cart->id, "ref" => $orderReference),
            true
        ));
        $paymentRequest->setWebhookUrl($link->getModuleLink(
            "ccvonlinepayments",
            "webhook",
            array("cartId" => $cart->id, "ref" => $orderReference),
            true
        ));

        $language = "eng";
        if($this->context->cookie !== null) {
            switch(Language::getIsoById( $this->context->cookie->id_lang)) {
                case "nl":  $language = "nld"; break;
                case "de":  $language = "deu"; break;
                case "fr":  $language = "fra"; break;
            }
        }
        $paymentRequest->setLanguage($language);


        $issuerKey  = Tools::getValue('issuerKey_'.$methodId);
        $issuer     = Tools::getValue('issuer_'.$methodId);

        $paymentRequest->setMethod($methodId);

        if($issuerKey === "issuerid") {
            $paymentRequest->setIssuer($issuer);
        }elseif($issuerKey === "brand") {
            $paymentRequest->setBrand($issuer);
        }

        $invoiceAddress = new Address($cart->id_address_invoice);
        $paymentRequest->setBillingAddress($invoiceAddress->address1);
        $paymentRequest->setBillingCity($invoiceAddress->city);
        $paymentRequest->setBillingPostalCode($invoiceAddress->postcode);
        $paymentRequest->setBillingCountry((new Country($invoiceAddress->id_country))->iso_code);
        $paymentRequest->setBillingState((new State($invoiceAddress->id_state))->iso_code);
        $paymentRequest->setBillingPhoneNumber($invoiceAddress->phone);
        $paymentRequest->setBillingLastName($invoiceAddress->lastname);
        $paymentRequest->setBillingFirstName($invoiceAddress->firstname);

        $deliveryAddress = new Address($cart->id_address_delivery);
        $paymentRequest->setShippingAddress($deliveryAddress->address1);
        $paymentRequest->setShippingCity($deliveryAddress->city);
        $paymentRequest->setShippingPostalCode($deliveryAddress->postcode);
        $paymentRequest->setShippingCountry((new Country($deliveryAddress->id_country))->iso_code);
        $paymentRequest->setShippingState((new State($deliveryAddress->id_state))->iso_code);
        $paymentRequest->setShippingLastName($deliveryAddress->lastname);
        $paymentRequest->setShippingFirstName($deliveryAddress->firstname);


        $customer = new Customer($cart->id_customer);
        $paymentRequest->setAccountInfoAccountIdentifier(strval($customer->id));

        $creationDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $customer->date_add??"");
        if($creationDate === false) {
            $creationDate = null;
        }
        $paymentRequest->setAccountInfoAccountCreationDate($creationDate);

        $changeDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $customer->date_upd);
        if($changeDate === false) {
            $changeDate = null;
        }

        $paymentRequest->setAccountInfoAccountChangeDate($changeDate);
        $paymentRequest->setAccountInfoEmail($customer->email);
        $paymentRequest->setAccountInfoHomePhoneNumber($invoiceAddress->phone);
        $paymentRequest->setAccountInfoMobilePhoneNumber($invoiceAddress->phone_mobile);

        $paymentRequest->setBillingEmail($customer->email);
        $paymentRequest->setShippingEmail($customer->email);

        $paymentRequest->setBrowserFromServer();
        $paymentRequest->setBrowserIpAddress(Tools::getRemoteAddr());

        if($method->isOrderLinesRequired()) {
            $paymentRequest->setOrderLines($module->getOrderLinesByCart($cart));
        }

        try {
            $paymentResponse = $ccvOnlinePaymentsApi->createPayment($paymentRequest);
        }catch(\CCVOnlinePayments\Lib\Exception\ApiException $apiException) {
            $this->errors[] = $this->trans("There was an unexpected error processing your payment", [], "Modules.Ccvonlinepayments.Shop");

            /** @phpstan-ignore-next-line arguments.count */
            $this->redirectWithNotifications($link->getPagelink('order', true, null, array('step' => 3)));
            return;
        }

        Db::getInstance()->insert(
            'ccvonlinepayments_payments',
            array(
                'order_reference'   => pSQL($orderReference),
                'payment_reference' => pSQL($paymentResponse->getReference()??""),
                'cart_id'           => (int) $cart->id,
                'status'            => 'pending',
                'method'            => $method->getId(),
                'transaction_type'  => $transactionType->value
            )
        );

        $payUrl = $paymentResponse->getPayUrl();
        if($payUrl === null) {
            throw new Exception("There was an unexpected error processing the payment");
        }

        Tools::redirect($payUrl);
    }
}
