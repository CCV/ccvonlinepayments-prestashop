<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
class CcvOnlinePaymentsWebhookModuleFrontController extends ModuleFrontController
{

    public function initContent() {
        $ref    = Tools::getValue('ref');
        $cartId = Tools::getValue("cartId");

        Logger::addLog("CCVOnlinePayments webhook: $ref/$cartId");

        $paymentStatus = $this->processTransaction($ref, $cartId, true);

        Logger::addLog("CCVOnlinePayments webhook status: $ref/$cartId status:".$paymentStatus->getStatus().", amount:".$paymentStatus->getAmount());

        die("OK");
    }

    public function processTransaction($orderRef, $cartId, $skipContextCheck = false) {
        $cart = new Cart($cartId);

        $payment = Db::getInstance()->getRow(sprintf(
            "SELECT payment_reference, method FROM "._DB_PREFIX_."ccvonlinepayments_payments WHERE `order_reference`='%s' AND `cart_id`='%s'",
            pSQL($orderRef),
            pSQL($cartId)
        ));

        $ccvOnlinePaymentsApi = $this->module->getApi();
        $paymentStatus = $ccvOnlinePaymentsApi->getPaymentStatus($payment['payment_reference']);

        if(!$skipContextCheck) {
            if((int)$cart->id_customer !== (int)$this->context->customer->id) {
                $this->errors[] = $this->trans("You are not authorized to view this page",[], "Modules.Ccvonlinepayments.Shop");
                $this->redirectWithNotifications(Context::getContext()->link->getPageLink('index', true));
                return null;
            }
        }

        if($paymentStatus->getStatus() === \CCVOnlinePayments\Lib\PaymentStatus::STATUS_SUCCESS) {
            if (isset($cart) && Validate::isLoadedObject($cart)) {
                if (Order::getIdByCartId($cart->id) === false) {
                    $this->module->validateCcvOnlinePaymentsOrder(
                        $cart->id,
                        Configuration::get('PS_OS_PAYMENT'),
                        $paymentStatus->getAmount(),
                        $orderRef,
                        $this->module->getMethodNameById($payment['method']),
                        null,
                        array(),
                        null,
                        false,
                        $cart->secure_key
                    );
                }
            }

            $this->updateStatus($payment['payment_reference'], $paymentStatus->getStatus());
        }elseif($paymentStatus->getStatus() === \CCVOnlinePayments\Lib\PaymentStatus::STATUS_FAILED) {
            $this->updateStatus($payment['payment_reference'], $paymentStatus->getStatus());
        }

        return $paymentStatus;
    }

    private function updateStatus($paymentReference, $status) {
        Db::getInstance()->update(
            'ccvonlinepayments_payments',
            array(
                "status" => $status
            ),
            sprintf(
                "`payment_reference`='%s'",
                pSQL($paymentReference)
            )
        );
    }
}
