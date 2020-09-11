<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CcvOnlinePaymentsPaymentModuleFrontController extends ModuleFrontController
{

    public function initContent()
    {
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect(Context::getContext()->link->getPageLink('index', true));
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
            Tools::redirect(Context::getContext()->link->getPageLink('index', true));
            return;
        }

        if (sizeof($errors) > 0) {
            foreach($errors as $error) {
                $this->errors[] = $error;
            }
            $this->redirectWithNotifications($this->context->link->getPagelink('order', true, null, array('step' => 3)));
            return;
        }

        $orderReference = Order::generateReference();

        $ccvOnlinePaymentsApi = $this->module->getApi();

        $paymentRequest = new \CCVOnlinePayments\Lib\PaymentRequest();
        $paymentRequest->setAmount($amount);
        $paymentRequest->setCurrency(Tools::strtoupper($this->context->currency->iso_code));
        $paymentRequest->setMerchantOrderReference($orderReference);
        $paymentRequest->setReturnUrl($this->context->link->getModuleLink(
            "ccvonlinepayments",
            "return",
            array("cartId" => $cart->id, "ref" => $orderReference),
            true
        ));
        $paymentRequest->setWebhookUrl($this->context->link->getModuleLink(
            "ccvonlinepayments",
            "webhook",
            array("cartId" => $cart->id, "ref" => $orderReference),
            true
        ));

        $language = "eng";
        switch(Language::getIsoById( $this->context->cookie->id_lang)) {
            case "nl":  $language = "nld"; break;
            case "de":  $language = "deu"; break;
            case "fr":  $language = "fra"; break;
        }
        $paymentRequest->setLanguage($language);


        $method     = Tools::getValue('method');
        $issuerKey  = Tools::getValue('issuerKey_'.$method);
        $issuer     = Tools::getValue('issuer_'.$method);

        $paymentRequest->setMethod($method);

        if($issuerKey === "issuerid") {
            $paymentRequest->setIssuer($issuer);
        }elseif($issuerKey === "brand") {
            $paymentRequest->setBrand($issuer);
        }

        $paymentRequest->setScaReady(true);

        $invoiceAddress = new Address($cart->id_address_invoice);
        $paymentRequest->setBillingAddress($invoiceAddress->address1);
        $paymentRequest->setBillingCity($invoiceAddress->city);
        $paymentRequest->setBillingPostalCode($invoiceAddress->postcode);
        $paymentRequest->setBillingCountry((new Country($invoiceAddress->id_country))->iso_code);
        $paymentRequest->setBillingState((new State($invoiceAddress->id_state))->iso_code);
        $paymentRequest->setBillingPhoneNumber($invoiceAddress->phone);

        $deliveryAddress = new Address($cart->id_address_delivery);
        $paymentRequest->setShippingAddress($deliveryAddress->address1);
        $paymentRequest->setShippingCity($deliveryAddress->city);
        $paymentRequest->setShippingPostalCode($deliveryAddress->postcode);
        $paymentRequest->setShippingCountry((new Country($deliveryAddress->id_country))->iso_code);
        $paymentRequest->setShippingState((new State($deliveryAddress->id_state))->iso_code);

        $customer = new Customer($cart->id_customer);
        $paymentRequest->setAccountInfoAccountIdentifier($customer->id);
        $paymentRequest->setAccountInfoAccountCreationDate(DateTime::createFromFormat('Y-m-d H:i:s', $customer->date_add));
        $paymentRequest->setAccountInfoAccountChangeDate(DateTime::createFromFormat('Y-m-d H:i:s', $customer->date_upd));
        $paymentRequest->setAccountInfoEmail($customer->email);
        $paymentRequest->setAccountInfoHomePhoneNumber($invoiceAddress->phone);
        $paymentRequest->setAccountInfoMobilePhoneNumber($invoiceAddress->phone_mobile);

        $paymentRequest->setBrowserFromServer();
        $paymentRequest->setBrowserIpAddress(Tools::getRemoteAddr());

        try {
            $paymentResponse = $ccvOnlinePaymentsApi->createPayment($paymentRequest);
        }catch(\CCVOnlinePayments\Lib\Exception\ApiException $apiException) {
            $this->errors[] = $this->trans("There was an unexpected error processing your payment", [], "Modules.Ccvonlinepayments.Shop");
            $this->redirectWithNotifications($this->context->link->getPagelink('order', true, null, array('step' => 3)));
            return;
        }

        Db::getInstance()->insert(
            'ccvonlinepayments_payments',
            array(
                'order_reference'   => pSQL($orderReference),
                'payment_reference' => pSQL($paymentResponse->getReference()),
                'cart_id'           => (int) $cart->id,
                'status'            => 'pending',
                'method'            => $method,
            )
        );

        Tools::redirect($paymentResponse->getPayUrl());
    }
}
