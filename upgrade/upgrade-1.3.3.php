<?php
/**
 * josraregenimages 1.3.3: licencia decidida desde el panel del servidor de actualizaciones.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_3($module)
{
    return $module->getUpdater()->registerHooks();
}
