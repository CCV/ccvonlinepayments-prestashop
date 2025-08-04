<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0(ModuleCore $module) : bool
{
    return $module->registerHook('actionOrderSlipAdd');
}
