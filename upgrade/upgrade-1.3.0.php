<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0($module)
{
    Db::getInstance()->execute('
        ALTER TABLE `'._DB_PREFIX_.'ccvonlinepayments_payments`
        ADD `transaction_type`  VARCHAR(16) NULL,
        ADD `capture_reference` VARCHAR(64) NULL
    ');

    $module->registerHook('actionOrderHistoryAddAfter');
}
