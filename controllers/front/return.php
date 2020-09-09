<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CcvOnlinePaymentsReturnModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $ref    = Tools::getValue('ref');
        $cartId = Tools::getValue("cartId");

        require_once __DIR__."/webhook.php";
        $webhookController = new CcvOnlinePaymentsWebhookModuleFrontController();
        $paymentStatus = $webhookController->processTransaction($ref, $cartId);

        if($paymentStatus === null) {
            return;
        }

        switch($paymentStatus->getStatus()) {
            case \CCVOnlinePayments\Lib\PaymentStatus::STATUS_SUCCESS:
                $cart = new Cart($cartId);
                Tools::redirect(
                    $this->context->link->getPageLink(
                        'order-confirmation',
                        true,
                        null,
                        array(
                            'id_cart'   => (int) $cart->id,
                            'id_module' => (int) $this->module->id,
                            'id_order'  => (int) Order::getIdByCartId($cart->id),
                            'key'       => $cart->secure_key,
                        )
                    )
                );
                break;
            case \CCVOnlinePayments\Lib\PaymentStatus::STATUS_PENDING:
                $this->context->smarty->assign('pollEndpoint', $this->context->link->getModuleLink(
                    $this->module->name,
                    'statuspoll',
                    array("ref" => $ref, "cartId" => $cartId)
                ));

                $this->setTemplate('module:ccvonlinepayments/views/templates/front/ccvonlinepayments_pending.tpl');
                break;
            case \CCVOnlinePayments\Lib\PaymentStatus::STATUS_FAILED:
                $this->errors[] = $this->trans("Your payment was unsuccesfull. Please try again",[], "Modules.Ccvonlinepayments.Shop");
                $this->redirectWithNotifications($this->context->link->getPagelink('order', true, null, array('step' => 3)));
                break;
        }
    }
}
