<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CcvOnlinePaymentsReturnModuleFrontController extends ModuleFrontController
{
    public function initContent() : void
    {
        parent::initContent();

        /** @var Link $link */
        $link = Context::getContext()?->link;

        $ref    = Tools::getValue('ref');
        $cartId = Tools::getValue("cartId");

        $payment = Db::getInstance()->getRow(sprintf(
            "SELECT status FROM "._DB_PREFIX_."ccvonlinepayments_payments WHERE `order_reference`='%s' AND `cart_id`='%s'",
            pSQL($ref),
            pSQL($cartId)
        ), false);

        if(!is_array($payment)) {
            $this->errors[] = $this->trans("Your payment was unsuccessful. Please try again",[], "Modules.Ccvonlinepayments.Shop");
            /** @phpstan-ignore-next-line arguments.count */
            $this->redirectWithNotifications($link->getPagelink('order', true, null, array('step' => 3)));
            return;
        }

        switch($payment['status']) {
            case \CCVOnlinePayments\Lib\Enum\PaymentStatus::SUCCESS->value:
                $cart = new Cart($cartId);
                Tools::redirect(
                    $link->getPageLink(
                        'order-confirmation',
                        true,
                        null,
                        array(
                            'id_cart'   => (int) $cart->id,
                            'id_module' => (int) $this->module->id,
                            'id_order'  => (int) Order::getIdByCartId($cart->id??-1),
                            'key'       => $cart->secure_key,
                        )
                    )
                );
                break;
            case \CCVOnlinePayments\Lib\Enum\PaymentStatus::PENDING->value:
                /** @var Smarty $smarty */
                $smarty = $this->context->smarty;

                $smarty->assign('pollEndpoint', $link->getModuleLink(
                    strval($this->module->name),
                    'statuspoll',
                    array("ref" => $ref, "cartId" => $cartId)
                ));

                $this->setTemplate('module:ccvonlinepayments/views/templates/front/ccvonlinepayments_pending.tpl');
                break;
            case \CCVOnlinePayments\Lib\Enum\PaymentStatus::FAILED->value:
                $this->errors[] = $this->trans("Your payment was unsuccessful. Please try again",[], "Modules.Ccvonlinepayments.Shop");

                /** @phpstan-ignore-next-line arguments.count */
                $this->redirectWithNotifications($link->getPagelink('order', true, null, array('step' => 3)));
                break;
        }
    }
}
