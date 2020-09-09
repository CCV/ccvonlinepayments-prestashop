<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CcvOnlinePaymentsStatusPollModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $ref    = Tools::getValue('ref');
        $cartId = Tools::getValue("cartId");

        die(json_encode(Db::getInstance()->getRow(sprintf(
            "SELECT status FROM "._DB_PREFIX_."ccvonlinepayments_payments WHERE `order_reference`='%s' AND `cart_id`='%s'",
            pSQL($ref),
            pSQL($cartId)
        ))));
    }
}
