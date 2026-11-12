<?php
/**
 * josraregenimages 1.3.2: conexión con el servidor de actualizaciones de josra.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_2($module)
{
    return $module->getUpdater()->registerHooks();
}
