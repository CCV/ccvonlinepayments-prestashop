<?php
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\PrestaShop\Adapter\StockManager;
use CCVOnlinePayments\Lib\CcvOnlinePaymentsApi;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/src/Cache.php";

class CcvOnlinePayments extends PaymentModule
{

    const CCVONLINEPAYMENTS_MIN_PHP_VERSION = "8.1.0";

    public function __construct(
    )
    {
        $this->name = 'ccvonlinepayments';
        $this->tab = 'payments_gateways';
        $this->version = '1.6.0';
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => '9.0.999');
        $this->author = 'CCV';
        $this->controllers = array('payment', 'webhook', 'return', 'statuspoll');

        $this->currencies       = true;
        $this->currencies_mode  = 'checkbox';

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('CCV Online Payments', [],"Modules.Ccvonlinepayments.Admin");
        $this->description = $this->trans('CCV Online Payments integration', [],"Modules.Ccvonlinepayments.Admin");

        if ($this->id !== null) {
            $currencies = Currency::checkPaymentCurrencies($this->id);
            if(!is_array($currencies) || count($currencies)===0) {
                $this->warning = $this->trans('No currency has been set for this module.', [], "Modules.Ccvonlinepayments.Admin");
            }
        }
    }

    public function install() : bool
    {
        if(!extension_loaded('curl')) {
            die('CCV OnlinePayments requires the curl php extension.');
        }

        if(version_compare(PHP_VERSION, self::CCVONLINEPAYMENTS_MIN_PHP_VERSION, '<')) {
            die('CCV OnlinePayments requires php '.self::CCVONLINEPAYMENTS_MIN_PHP_VERSION.' or greater.');
        }

        if (!parent::install() || !$this->registerHook('paymentOptions') || !$this->registerHook('paymentReturn') || !$this->registerHook('actionOrderSlipAdd') || !$this->registerHook('actionOrderHistoryAddAfter')) {
            return false;
        }

        Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'ccvonlinepayments_payments` (
				`payment_reference` VARCHAR(64)  NOT NULL PRIMARY KEY,
				`order_reference`   VARCHAR(64),
				`cart_id`           INT(64),
				`status`            VARCHAR(24),
				`method`            VARCHAR(24),
				`transaction_type`  VARCHAR(16) NULL,
				`capture_reference` VARCHAR(64) NULL,
				 INDEX (cart_id)
			) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;
        ');

        return true;
    }

    public function uninstall()
    {
        if(!parent::uninstall()) {
            return false;
        }

        Db::getInstance()->execute('
            DROP TABLE IF EXISTS `'._DB_PREFIX_.'ccvonlinepayments_payments`
        ');

        return true;
    }

    /**
     * @return array<\CCVOnlinePayments\Lib\Method>
     */
    private function getFilteredAndSortedMethods(?string $invoiceCountry = null) : array {
        $api = $this->getApi();
        $methods = $api->sortMethods($api->getMethods(),$invoiceCountry);

        $filterMethods = [
            "terminal"      => true,
            "landingpage"   => true,
            "gift"          => true,
            "softpos"       => true,
            "payconiq"      => true,
            "alipay"        => true,
            "sdd"           => true
        ];
        return array_filter($methods, function($method) use ($filterMethods) {
            return !isset($filterMethods[$method->getId()]);
        });
    }

    public function getMethodNameById(string $methodId) : string {
        $methodName = $methodId;
        switch($methodId) {
            case "applepay":        $methodName = $this->trans("Apple Pay",                     [], "Modules.Ccvonlinepayments.Shop"); break;
            case "googlepay":       $methodName = $this->trans("Google Pay",                     [], "Modules.Ccvonlinepayments.Shop"); break;
            case "banktransfer":    $methodName = $this->trans("Bank Transfer",                 [], "Modules.Ccvonlinepayments.Shop"); break;
            case "card_bcmc":       $methodName = $this->trans("Bancontact",                    [], "Modules.Ccvonlinepayments.Shop"); break;
            case "card_maestro":    $methodName = $this->trans("Maestro",                       [], "Modules.Ccvonlinepayments.Shop"); break;
            case "card_mastercard": $methodName = $this->trans("Mastercard",                    [], "Modules.Ccvonlinepayments.Shop"); break;
            case "card_visa":       $methodName = $this->trans("Visa",                          [], "Modules.Ccvonlinepayments.Shop"); break;
            case "card_amex":       $methodName = $this->trans("American Express",              [], "Modules.Ccvonlinepayments.Shop"); break;
            case "ideal":           $methodName = $this->trans("iDeal",                         [], "Modules.Ccvonlinepayments.Shop"); break;
            case "paypal":          $methodName = $this->trans("PayPal",                        [], "Modules.Ccvonlinepayments.Shop"); break;
            case "landingpage":     $methodName = $this->trans("CCV Online Payments",           [], "Modules.Ccvonlinepayments.Shop"); break;
            case "giropay":         $methodName = $this->trans("GiroPay",                       [], "Modules.Ccvonlinepayments.Shop"); break;
            case "terminal":        $methodName = $this->trans("Terminal (instore solution)",   [], "Modules.Ccvonlinepayments.Shop"); break;
            case "payconiq":        $methodName = $this->trans("Payconiq",                      [], "Modules.Ccvonlinepayments.Shop"); break;
            case "eps":             $methodName = $this->trans("Eps",                           [], "Modules.Ccvonlinepayments.Shop"); break;
            case "alipay":          $methodName = $this->trans("AliPay",                        [], "Modules.Ccvonlinepayments.Shop"); break;
            case "klarna":          $methodName = $this->trans("Klarna",                        [], "Modules.Ccvonlinepayments.Shop"); break;
        }

        return $methodName;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function hookPaymentOptions(array $params) : array
    {
        if (!$this->active) {
            return [];
        }

        if (!$this->checkCurrency($params['cart'])) {
            return [];
        }

        /** @var Context $context */
        $context = Context::getContext();

        /** @var Link $link */
        $link = $context->link;

        $this->getApi();

        $invoiceAddress = new Address($context->cart?->id_address_invoice);
        $invoiceCountryCode = (new Country($invoiceAddress->id_country))->iso_code;

        $methods = $this->getFilteredAndSortedMethods($invoiceCountryCode);

        $paymentOptions = [];
        foreach($methods as $method) {
            if($context->currency !== null && $method->isCurrencySupported($context->currency->iso_code)) {
                if(Configuration::get('CCVONLINEPAYMENTS_METHOD_ACTIVE_'.$method->getId())) {
                    $methodName = $this->getMethodNameById($method->getId());

                    $option = new PaymentOption();
                    $option->setCallToActionText($methodName);
                    $option->setModuleName(strval($this->name??""));

                    if($this->hasMethodImage($method->getId())) {
                        $option->setLogo($this->getMethodImagePath($method->getId()));
                    }

                    $option->setAction($link->getModuleLink(
                        strval($this->name??""),
                        "payment",
                        array("method" => $method->getId(), "rnd" => time()),
                        true
                    ));
                    $option->setInputs(array(
                        array(
                            "name" => "issuer_{$method->getId()}",
                            "type" => "hidden",
                            "value" => "",
                        ),
                        array(
                            "name" => "issuerKey_{$method->getId()}",
                            "type" => "hidden",
                            "value" => $method->getIssuerKey(),
                        ),
                    ));
                    if ($method->getId() !== "ideal" && $method->getIssuers() !== null) {
                        $context->smarty?->assign(array(
                            "method"    => $method->getId(),
                            "issuerKey" => $method->getIssuerKey(),
                            "issuers"   => $method->getIssuers(),
                        ));

                        $option->setAdditionalInformation($this->display(__FILE__, 'ccvonlinepayments_issuers.tpl'));
                    }

                    $paymentOptions[] = $option;
                }
            }
        }

        return $paymentOptions;
    }

    public function checkCurrency(Cart $cart) : bool
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getContent() : string
    {
        $output = null;

        /** @var AdminController $adminController */
        $adminController = $this->context->controller;
        $adminController->addJqueryPlugin('select2');

        if (Tools::isSubmit('submit'.$this->name)) {
            $apiKey = strval(Tools::getValue('API_KEY'));

            if (
                empty($apiKey) ||
                !Validate::isString($apiKey)
            ) {
                $output .= $this->displayError($this->trans('Invalid Api Key', [], "Modules.Ccvonlinepayments.Admin"));
            } else {
                Configuration::updateValue('CCVONLINEPAYMENTS_API_KEY', $apiKey);

                Configuration::updateValue('CCVONLINEPAYMENTS_ORDER_STATUS_CAPTURE', implode(",",Tools::getValue('ORDER_STATUS_CAPTURE',[])));
                Configuration::updateValue('CCVONLINEPAYMENTS_ORDER_STATUS_REVERSAL', implode(",",Tools::getValue('ORDER_STATUS_REVERSAL',[])));

                foreach(Tools::getAllValues() as $key => $value) {
                    if(strpos($key,"METHOD_ACTIVE_") === 0) {
                        Configuration::updateValue('CCVONLINEPAYMENTS_'.$key, $value != "");
                    }
                }

                $output .= $this->displayConfirmation($this->trans('Settings updated', [], "Modules.Ccvonlinepayments.Admin"));
            }
        }

        return  $output.
                $this->display(__FILE__, 'views/templates/admin/configure.tpl').
                $this->displayForm();
    }

    public function displayForm() : string
    {
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');
        $orderStates = $this->getOrderStates();

        /** @var AdminController $adminController */
        $adminController = $this->context->controller;

        $helper = new HelperForm();

        $fieldsForm = [];
        $fieldsForm[]['form'] = [
            'legend' => [
                'title'  => $this->trans('Settings', [], "Modules.Ccvonlinepayments.Admin"),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->trans('Api Key', [], "Modules.Ccvonlinepayments.Admin"),
                    'name' => 'API_KEY',
                    'size' => 64,
                    'required' => true
                ]
            ],
            'submit' => [
                'title' => $this->trans('Save', [], "Modules.Ccvonlinepayments.Admin"),
                'class' => 'btn btn-default pull-right'
            ],
        ];

        $apiKey = Tools::getValue('API_KEY', Configuration::get('CCVONLINEPAYMENTS_API_KEY'));
        $helper->fields_value['API_KEY'] = $apiKey;

        $helper->fields_value['ORDER_STATUS_CAPTURE[]'] = explode(",", strval(Configuration::get('CCVONLINEPAYMENTS_ORDER_STATUS_CAPTURE')));
        $helper->fields_value['ORDER_STATUS_REVERSAL[]'] = explode(",",strval(Configuration::get('CCVONLINEPAYMENTS_ORDER_STATUS_REVERSAL')));

        if(trim($apiKey) !== "") {
            $api = $this->getApi($apiKey);

            if(!$api->isKeyValid()) {
                $adminController->warnings[] = $this->trans(
                    'Invalid Api Key',
                    [],
                    "Modules.Ccvonlinepayments.Admin"
                );
            }else{
                foreach($this->getFilteredAndSortedMethods() as $method) {
                    $imageCode = "";
                    if($this->hasMethodImage($method->getId())) {
                        $imageCode = "<img src='".htmlspecialchars($this->getMethodImagePath($method->getId()))."' style='max-height:80%'> ";
                    }

                    $formFields = [
                        'legend' => [
                            'title' => $imageCode.$this->getMethodNameById($method->getName()),
                        ],
                        'input' => [
                            [
                                'type'      => 'switch',
                                'label'     => $this->trans('Active', [], "Modules.Ccvonlinepayments.Admin"),
                                'name'      => 'METHOD_ACTIVE_'.$method->getId(),
                                'is_bool'   => 'true',
                                'required'  => false,
                                'default'   => false,
                                'values'    => array(
                                    array(
                                        'id' => 'on',
                                        'value' => true,
                                        'label' => $this->trans('Yes', [], "Modules.Ccvonlinepayments.Admin"),
                                    ),array(
                                        'id' => 'off',
                                        'value' => false,
                                        'label' => $this->trans('No', [], "Modules.Ccvonlinepayments.Admin"),
                                    ),
                                )
                            ]
                        ],
                        'submit' => [
                            'title' => $this->trans('Save', [], "Modules.Ccvonlinepayments.Admin"),
                            'class' => 'btn btn-default pull-right'
                        ],
                    ];

                    if($method->getName() === "klarna") {
                        $formFields['input'][] = [
                            'tab' => 'order_management_config',
                            'class' => 'ccv_select2',
                            'type' => 'select',
                            'name' => 'ORDER_STATUS_CAPTURE[]',
                            'desc' => $this->trans("Choose the order statuses that should trigger a 'capture'. For orders that are paid with Klarna a capture needs to take please after delivery to the consumer. Common statuses are 'delivered' or 'shipped'.", [], "Modules.Ccvonlinepayments.Admin"),
                            'label' => $this->trans('Capture Order Status', [], "Modules.Ccvonlinepayments.Admin"),
                            'options' => array(
                                'query'     => $orderStates,
                                'id'        => 'id',
                                'name'      => 'name'
                            ),
                            'multiple' => true,
                        ];
                        $formFields['input'][] = [
                            'tab' => 'order_management_config',
                            'class' => 'ccv_select2',
                            'type' => 'select',
                            'name' => 'ORDER_STATUS_REVERSAL[]',
                            'desc' => $this->trans("Choose the order statuses that should cancel/reserve an already started authorization. A common status is 'Cancelled'. Please note that a reversal can only take place for the parts of an order that have not yet been captured.", [], "Modules.Ccvonlinepayments.Admin"),
                            'label' => $this->trans('Reversal Order Status', [], "Modules.Ccvonlinepayments.Admin"),
                            'options' => array(
                                'query'     => $orderStates,
                                'id'        => 'id',
                                'name'      => 'name'
                            ),
                            'multiple' => true,
                        ];
                    }

                    $fieldsForm[]['form'] = $formFields;

                    $helper->fields_value['METHOD_ACTIVE_'.$method->getId()] = Tools::getValue('METHOD_ACTIVE_'.$method->getId(), Configuration::get('CCVONLINEPAYMENTS_METHOD_ACTIVE_'.$method->getId()));
                }
            }
        }

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;

        return $helper->generateForm($fieldsForm);
    }

    public function isUsingNewTranslationSystem() : bool
    {
        return true;
    }

    public function getApi(?string $apiKey = null) : CcvOnlinePaymentsApi {
        if($apiKey === null) {
            $apiKey = Configuration::get('CCVONLINEPAYMENTS_API_KEY');
            if($apiKey === false) {
                $apiKey = "";
            }
        }

        /** @phpstan-ignore-next-line if.alwaysTrue */
        if(version_compare(_PS_VERSION_, '9', '<')) {
            require_once __DIR__ . "/src/Logger.php";
            $logger = new CcvOnlinePaymentsPaymentPrestashopLogger();
        }else{
            require_once __DIR__ . "/src/LoggerV3.php";
            $logger = new CcvOnlinePaymentsPaymentPrestashopLoggerV3();
        }


        $api =  new CcvOnlinePaymentsApi(
            new CcvOnlinePaymentsPaymentPrestashopCache(),
            $logger,
            $apiKey
        );

        $api->setMetadata([
            "CCVOnlinePayments" => $this->version,
            "Prestashop"        => _PS_VERSION_
        ]);

        return $api;
    }

    public function hasMethodImage(string $methodId) : bool {
        return file_exists(__DIR__."/images/methods/".$methodId.".png");
    }

    public function getMethodImagePath(string $methodId) : string {
        return $this->_path."images/methods/".$methodId.".png";
    }

    /**
     * @param array<mixed> $params
     */
    public function hookActionOrderSlipAdd(array $params = []) : bool
    {
        if ($params['order']->module !== 'ccvonlinepayments') {
            return false;
        }

        $payment = Db::getInstance()->getRow(sprintf(
            "SELECT payment_reference, method, transaction_type, capture_reference FROM "._DB_PREFIX_."ccvonlinepayments_payments WHERE `order_reference`='%s'",
            pSQL($params['order']->reference)
        ));

        if(!is_array($payment)) {
            return false;
        }

        if(isset($payment['transaction_type']) && $payment['transaction_type'] === \CCVOnlinePayments\Lib\Enum\TransactionType::AUTHORIZE->value) {
            $refundPaymentReference = isset($payment['capture_reference']) ? $payment['capture_reference'] : "";
        }else{
            $refundPaymentReference = isset($payment['payment_reference']) ? $payment['payment_reference'] : "";
        }

        if($refundPaymentReference == "") {
            return false;
        }

        $method = null;
        foreach($this->getApi()->getMethods() as $m) {
            if($m->getId() === $payment['method']) {
                $method = $m;
            }
        }

        if($method === null) {
            return false;
        }

        if(Tools::getValue('partialRefundShippingCost')) {
            $shippingCost = floatval(str_replace(',', '.', Tools::getValue('partialRefundShippingCost')));
        }elseif(Tools::getValue('cancel_product')) {
            $cancelProduct = Tools::getValue('cancel_product');
            $shippingCost = floatval(str_replace(',', '.', $cancelProduct['shipping_amount'] ?? 0));
        }else{
            $shippingCost = 0;
        }

        $refundAmount = 0;
        foreach ($params['productList'] as $productListItem) {
            $refundAmount += $productListItem['amount'];
        }
        $refundAmount += floatval(str_replace(',', '.', Tools::getValue('partialRefundShippingCost')));


        $refundRequest = new \CCVOnlinePayments\Lib\RefundRequest();
        $refundRequest->setAmount($refundAmount);
        $refundRequest->setReference($refundPaymentReference);

        if($method->isOrderLinesRequired()) {
            $refundRequest->setOrderLines($this->getOrderlinesByOrder($params['order'], $params['productList'], $shippingCost));
        }

        try {
            $this->getApi()->createRefund($refundRequest);
        }catch(\CCVOnlinePayments\Lib\Exception\ApiException $apiException) {
            $errorMessage = $this->trans("The partial refund has been created, but we failed to create a refund at CCV Online Payments: ", [],"Modules.Ccvonlinepayments.Admin").$apiException->getMessage();
            $this->getFlashBag()?->add('error', $errorMessage);
            $this->addMessageToOrder($params['order'], $errorMessage);
            return false;
        }

        $successMessage = $this->trans("The refunded has been created at CCV Online Payments", [],"Modules.Ccvonlinepayments.Admin");
        $this->getFlashBag()?->add('success', $successMessage);
        return true;
    }

    /**
     * @return array<array{'id': int, 'name': string}>
     */
    protected function getOrderStates() : array {
        /** @var ?Language $language */
        $language = $this->context->language;
        $states = OrderState::getOrderStates($language->id ?? 0);

        $retVal = [];
        foreach ($states as $state) {
            $retVal[] = [
                'id'        => $state['id_order_state'],
                'name'      => $state['name'],
            ];
        }

        return $retVal;
    }

    /**
     * @return array<\CCVOnlinePayments\Lib\OrderLine>
     */
    public function getOrderlinesByCart(Cart $cart) : array{
        $orderLines = [];

        foreach($cart->getProducts() as $cartProduct) {
            $orderLine = new \CCVOnlinePayments\Lib\OrderLine();
            $orderLine->setType(\CCVOnlinePayments\Lib\Enum\OrderLineType::PHYSICAL);
            $orderLine->setName($cartProduct['name']);
            $orderLine->setQuantity($cartProduct['cart_quantity']);
            $orderLine->setTotalPrice(Tools::ps_round($cartProduct['total_wt'], 2));
            $orderLine->setUnit('pcs');
            $orderLine->setUnitPrice(Tools::ps_round($cartProduct['price_wt'], 2));
            $orderLine->setVatRate($cartProduct['rate']);
            $orderLine->setVat(($cartProduct['price_wt'] - $cartProduct['price']) * $cartProduct['quantity']);
            $orderLines[] = $orderLine;
        }

        $shippingCost = $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
        if ($shippingCost > 0) {
            $carrier = new Carrier($cart->id_carrier);
            $carrieraddress = new Address($cart->id_address_delivery);
            $vatRate = $carrier->getTaxesRate($carrieraddress);

            $shippingCostExclVat = $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
            $vatAmount = Tools::ps_round($shippingCost - $shippingCostExclVat, 2);

            $orderLine = new \CCVOnlinePayments\Lib\OrderLine();
            $orderLine->setType(\CCVOnlinePayments\Lib\Enum\OrderLineType::SHIPPING_FEE);
            $orderLine->setName("Shipping");
            $orderLine->setQuantity(1);;
            $orderLine->setTotalPrice($shippingCost);
            $orderLine->setVat($vatAmount);
            $orderLine->setVatRate($vatRate);
            $orderLine->setUnitPrice($shippingCost);
            $orderLines[] = $orderLine;
        }

        /* Add discounts */
        if ($cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS) > 0) {
            $rules = $cart->getCartRules(CartRule::FILTER_ACTION_ALL, false);

            if(is_array($rules)) {
                foreach($rules as $rule) {
                    $vatRate = (($rule["value_real"] / $rule["value_tax_exc"]) - 1) * 100;
                    $vatRate = Tools::ps_round($vatRate, 2);
                    $value   = Tools::ps_round($rule["value_real"], 2);

                    if($value > 0) {
                        $vatValue = $value - ($value / (1 + ($vatRate / 100)));
                        $vatValue = Tools::ps_round($vatValue, 2);
                    }else{
                        $vatValue = 0;
                    }

                    $orderLine = new \CCVOnlinePayments\Lib\OrderLine();
                    $orderLine->setType(\CCVOnlinePayments\Lib\Enum\OrderLineType::DISCOUNT);
                    $orderLine->setName($rule['name']);
                    $orderLine->setQuantity(1);;
                    $orderLine->setTotalPrice($value);
                    $orderLine->setVat($vatValue);
                    $orderLine->setVatRate($vatRate);
                    $orderLine->setUnitPrice($value);
                    $orderLines[] = $orderLine;
                }
            }
        }

        return $orderLines;
    }

    /**
     * @param array<int, mixed> $productList
     * @return array<\CCVOnlinePayments\Lib\OrderLine>
     */
    public function getOrderlinesByOrder(Order $order, array $productList, float $partialRefundShippingCost) : array {
        $orderLines = [];

        foreach($order->getProducts() as $orderProduct) {
            $productListItem = $productList[$orderProduct['id_order_detail']] ?? 0;

            if($productListItem['quantity'] > 0) {
                $orderLine = new \CCVOnlinePayments\Lib\OrderLine();
                $orderLine->setType(\CCVOnlinePayments\Lib\Enum\OrderLineType::PHYSICAL);
                $orderLine->setName($orderProduct['product_name']);
                $orderLine->setQuantity($productListItem['quantity']);
                $orderLine->setTotalPrice($productListItem['total_refunded_tax_incl']);
                $orderLine->setUnit('pcs');
                $orderLine->setVatRate($orderProduct['tax_rate']);
                $orderLine->setVat($productListItem['total_refunded_tax_incl'] - $productListItem['total_refunded_tax_excl']);
                $orderLines[] = $orderLine;
            }
        }

        $shippingCost = $partialRefundShippingCost;
        if ($shippingCost > 0) {
            $orderLine = new \CCVOnlinePayments\Lib\OrderLine();
            $orderLine->setType(\CCVOnlinePayments\Lib\Enum\OrderLineType::SHIPPING_FEE);
            $orderLine->setName("Shipping");
            $orderLine->setQuantity(1);;
            $orderLine->setTotalPrice($shippingCost);
            $orderLine->setUnitPrice($shippingCost);
            $orderLines[] = $orderLine;
        }

        return $orderLines;
    }

    /**
     * @param array<mixed> $params
     */
    public function hookActionOrderHistoryAddAfter(array $params) : void
    {
        $orderHistory = $params['order_history'];

        $newOrderStateId  = (int)$orderHistory->id_order_state;
        $orderId        = $orderHistory->id_order;

        if ($orderId < 1) {
            return;
        }

        if (!Validate::isLoadedObject($orderHistory)) {
            return;
        }

        $order = new Order((int) $orderId);

        if ($order->module !== 'ccvonlinepayments') {
            return;
        }

        $payment = Db::getInstance()->getRow(sprintf(
            "SELECT payment_reference,transaction_type  FROM "._DB_PREFIX_."ccvonlinepayments_payments WHERE `order_reference`='%s'",
            pSQL($order->reference)
        ));

        if(!is_array($payment)) {
            return;
        }

        $orderStatus = new OrderState($newOrderStateId);

        /** @var null|string[]|string $orderStatusTemplate */
        $orderStatusTemplate = $orderStatus->template;
        if(is_string($orderStatusTemplate)) {
            $orderStatusTemplate = [$orderStatusTemplate];
        }

        if($orderStatusTemplate !== null) {
            foreach($orderStatusTemplate as $template) {
                if($template === "refund") {
                    $this->refund($order);
                    break;
                }
            }
        }

        if($payment['transaction_type'] !== \CCVOnlinePayments\Lib\Enum\TransactionType::AUTHORIZE->value) {
            return;
        }

        $captureStates = explode(',', strval(Configuration::get('CCVONLINEPAYMENTS_ORDER_STATUS_CAPTURE')));
        $reversalStates = explode(',', strval(Configuration::get('CCVONLINEPAYMENTS_ORDER_STATUS_REVERSAL')));

        try {
            if (in_array($newOrderStateId, $captureStates)) {
                $this->capture($order, $payment['payment_reference']);
            }

            if (in_array($newOrderStateId, $reversalStates)) {
                $this->reversal($order, $payment['payment_reference']);
            }
        }catch(\CCVOnlinePayments\Lib\Exception\ApiException $apiException) {
            $this->getFlashBag()?->add('error', $apiException->getMessage());
        }
    }

    private function capture(Order $order, string $paymentReference) : void {
        $captureRequest = new \CCVOnlinePayments\Lib\CaptureRequest();
        $captureRequest->setReference($paymentReference);
        $captureRequest->setAmount($order->total_paid);
        $captureResponse = $this->getApi()->createCapture($captureRequest);

        Db::getInstance()->update(
            'ccvonlinepayments_payments',
            array(
                "capture_reference" => $captureResponse->getReference()
            ),
            sprintf(
                "`payment_reference`='%s'",
                pSQL($paymentReference)
            )
        );
    }

    private function reversal(Order $order, string $paymentReference) : void {
        global $cookie;
        if($cookie->id_employee > 0) {
            $payment = Db::getInstance()->getRow(sprintf(
                "SELECT payment_reference, method, transaction_type, capture_reference FROM "._DB_PREFIX_."ccvonlinepayments_payments WHERE `order_reference`='%s'",
                pSQL($order->reference)
            ));

            if(!is_array($payment)) {
                return;
            }

            if(array_key_exists('capture_reference', $payment) && $payment['capture_reference'] !== null) {
                $errorMessage = $this->trans("This order has already been (partially) captured. The captured part of the order cannot be reversed. Please create a refund using the partial refund functionality.", [],"Modules.Ccvonlinepayments.Admin");
                $this->getFlashBag()?->add('error', $errorMessage);
                $this->addMessageToOrder($order, $errorMessage);
            }
        }

        $reversalRequest = new \CCVOnlinePayments\Lib\ReversalRequest();
        $reversalRequest->setReference($paymentReference);
        $this->getApi()->createReversal($reversalRequest);
    }

    private function refund(Order $order) : void {
        global $cookie;

        if ($order->module !== 'ccvonlinepayments') {
            return;
        }

        if($cookie->id_employee > 0) {
            $errorMessage = $this->trans("Changing the status to refunded will not create a refund at CCV Online Payments. Please use the partial refund functionality.", [],"Modules.Ccvonlinepayments.Admin");
            $this->getFlashBag()?->add('error', $errorMessage);
            $this->addMessageToOrder($order, $errorMessage);
        }
    }

    private function addMessageToOrder(Order $order, string $message) : void {
        global $cookie;

        $customer_thread = new CustomerThread();
        $customer_thread->id_contact = 0;
        $customer_thread->id_customer = (int) $order->id_customer;
        $customer_thread->id_shop = (int) $order->id_shop;
        $customer_thread->id_order = (int) $order->id;
        $customer_thread->id_lang = (int) $order->id_lang;
        $customer_thread->email = $order->getCustomer()->email;
        $customer_thread->status = 'open';
        $customer_thread->token = Tools::passwdGen(12);
        $customer_thread->add();

        $msg = new CustomerMessage();
        $message = strip_tags($message, '<br>');
        $msg->message = $message;
        $msg->id_customer_thread = (int)$customer_thread->id;
        $msg->id_employee = $cookie->id_employee;
        $msg->private = true;
        $msg->add();
    }

    private function getFlashBag() : ?\Symfony\Component\HttpFoundation\Session\Flash\FlashBag {
        /** @var ?\Symfony\Component\HttpFoundation\Session\Session $session */
        /** @phpstan-ignore-next-line class.notFound */
        $session = $this->context->controller?->getContainer()?->get('request_stack')?->getSession();
        if($session === null) {
            /** @var ?\Symfony\Component\HttpFoundation\Session\Session $session */
            $session = $this->get('session');
        }

        /** @var ?\Symfony\Component\HttpFoundation\Session\Flash\FlashBag $flashBag */
        $flashBag =  $session?->getFlashBag();

        return $flashBag;
    }


}
