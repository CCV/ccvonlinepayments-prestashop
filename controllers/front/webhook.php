<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
class CcvOnlinePaymentsWebhookModuleFrontController extends ModuleFrontController
{

    public function initContent() : void {
        $ref    = Tools::getValue('ref');
        $cartId = Tools::getValue("cartId");

        PrestaShopLogger::addLog("CCVOnlinePayments webhook: $ref/$cartId");

        $paymentStatus = $this->processTransaction($ref, $cartId, true);

        PrestaShopLogger::addLog("CCVOnlinePayments webhook status: $ref/$cartId status:".$paymentStatus?->getStatus()?->value.", amount:".$paymentStatus?->getAmount());

        die("OK");
    }

    public function processTransaction(string $orderRef, string $cartId, bool $skipContextCheck = false) : ?\CCVOnlinePayments\Lib\PaymentStatus {
        $cart = new Cart(intval($cartId));

        $payment = Db::getInstance()->getRow(sprintf(
            "SELECT payment_reference, method FROM "._DB_PREFIX_."ccvonlinepayments_payments WHERE `order_reference`='%s' AND `cart_id`='%s'",
            pSQL($orderRef),
            pSQL($cartId)
        ));

        if(!is_array($payment)) {
            $this->errors[] = $this->trans("You are not authorized to view this page",[], "Modules.Ccvonlinepayments.Shop");

            /** @phpstan-ignore-next-line arguments.count */
            $this->redirectWithNotifications(Context::getContext()->link->getPageLink('index', true));
            return null;
        }

        /** @var CcvOnlinePayments $module */
        $module = $this->module;

        $ccvOnlinePaymentsApi = $module->getApi();
        $paymentStatus = $ccvOnlinePaymentsApi->getPaymentStatus($payment['payment_reference']);

        if(!$skipContextCheck) {
            if($cart->id_customer !== $this->context->customer?->id) {
                $this->errors[] = $this->trans("You are not authorized to view this page",[], "Modules.Ccvonlinepayments.Shop");

                /** @phpstan-ignore-next-line arguments.count */
                $this->redirectWithNotifications(Context::getContext()->link->getPageLink('index', true));
                return null;
            }
        }

        if($paymentStatus->getStatus() === \CCVOnlinePayments\Lib\Enum\PaymentStatus::SUCCESS) {
            if (Validate::isLoadedObject($cart)) {
                if ($cart->id !== null && Order::getIdByCartId($cart->id) === false) {
                    $module->validateOrder(
                        $cart->id,
                        intval(Configuration::get('PS_OS_PAYMENT')),
                        $paymentStatus->getAmount()??0,
                        $module->getMethodNameById($payment['method']),
                        null,
                        array(),
                        null,
                        false,
                        $cart->secure_key,
                        null,
                        $orderRef,
                    );
                }
            }

            $this->updateStatus($payment['payment_reference'], $paymentStatus->getStatus());
        }elseif($paymentStatus->getStatus() === \CCVOnlinePayments\Lib\Enum\PaymentStatus::FAILED) {
            $this->updateStatus($payment['payment_reference'], $paymentStatus->getStatus());
        }

        return $paymentStatus;
    }

    private function updateStatus(string $paymentReference, \CCVOnlinePayments\Lib\Enum\PaymentStatus $status) : void {
        Db::getInstance()->update(
            'ccvonlinepayments_payments',
            array(
                "status" => $status->value
            ),
            sprintf(
                "`payment_reference`='%s'",
                pSQL($paymentReference)
            )
        );
    }
}
