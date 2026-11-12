<?php
/**
 * josraregenimages - Regeneración de miniaturas de imágenes vía AJAX por lotes.
 * Toda la configuración vive dentro del propio módulo (Módulos > Configurar).
 * No registra ninguna pestaña en el menú de administración.
 *
 * @author José Ramón Guzmán Sobrino
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosraRegenImagesQueue.php';
require_once dirname(__FILE__) . '/classes/JosraRegenImagesRenderer.php';
require_once dirname(__FILE__) . '/classes/JosraregenimagesUpdater.php';

class JosraRegenImages extends Module
{
    const TABLE_QUEUE = 'josra_regenimages_queue';

    public function __construct()
    {
        $this->name = 'josraregenimages';
        $this->tab = 'administration';
        $this->version = '1.3.2';
        $this->author = 'José Ramón Guzmán Sobrino';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];

        parent::__construct();

        $this->displayName = $this->l('Josra - Regenerar imágenes (AJAX)');
        $this->description = $this->l('Regenera las miniaturas de productos, categorías, marcas, proveedores y tiendas por lotes AJAX, sin sobrecargar el servidor.');
        $this->confirmUninstall = $this->l('¿Seguro? Se eliminará la tabla de cola interna (no afecta a las imágenes ya generadas en el servidor).');
    }

    public function getUpdater()
    {
        return new JosraregenimagesUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    public function install()
    {
        return parent::install() && $this->createQueueTable() && $this->getUpdater()->registerHooks();
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();
        Configuration::deleteByName('JOSRAREGENIMAGES_UPD_TS');
        Configuration::deleteByName('JOSRAREGENIMAGES_UPD_INFO');
        Configuration::deleteByName('JOSRAREGENIMAGES_UPD_SU');
        Configuration::deleteByName('JOSRAREGENIMAGES_UPD_LT');

        return $this->dropQueueTable() && parent::uninstall();
    }

    protected function createQueueTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_QUEUE . '` (
            `id_queue` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
            `entity` VARCHAR(32) NOT NULL,
            `id_entity` INT UNSIGNED NOT NULL,
            `id_image` INT UNSIGNED NOT NULL DEFAULT 0,
            `image_type_name` VARCHAR(32) NOT NULL,
            `image_type_width` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `image_type_height` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `generate_webp` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL DEFAULT "pending",
            `error_message` VARCHAR(255) DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_queue`),
            INDEX `idx_status` (`status`),
            INDEX `idx_entity` (`entity`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return Db::getInstance()->execute($sql);
    }

    protected function dropQueueTable()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE_QUEUE . '`');
    }

    /**
     * Punto de entrada único: pantalla de configuración + dispatcher de acciones AJAX.
     * No hay controller admin separado ni tab: todo pasa por aquí.
     */
    public function getContent()
    {
        // Peticiones AJAX (cola, siguiente lote, progreso...). Parámetros propios (jrAjax/jrAction): con "ajax" y
        // "action" el controlador clásico de módulos de PS 1.7/8 se queda la petición y nunca llega aquí.
        if (Tools::getValue('jrAjax') && Tools::getValue('jrAction')) {
            $this->handleAjax();
            exit;
        }

        // Servidor de actualizaciones: botones del panel, hooks y upgrades pendientes
        $updater = $this->getUpdater();
        $output = $updater->processRequest();

        // Guardado de preferencias por defecto del formulario (batch size, etc.)
        if (Tools::isSubmit('submitJosraRegenImagesSave')) {
            Configuration::updateValue('JOSRA_REGENIMG_BATCH_SIZE', (int) Tools::getValue('batch_size', 20));
            $output .= $this->displayConfirmation($this->l('Preferencias guardadas.'));
        }
        if (Tools::isSubmit('submitJosraRegenQuality')) {
            Configuration::updateValue('JOSRA_REGENIMG_ENGINE', Tools::getValue('JOSRA_REGENIMG_ENGINE') ? 'own' : 'ps');
            Configuration::updateValue('JOSRA_REGENIMG_MAXCROP', max(0, min(100, (int) Tools::getValue('JOSRA_REGENIMG_MAXCROP'))));
            Configuration::updateValue('JOSRA_REGENIMG_FILL', Tools::getValue('JOSRA_REGENIMG_FILL') === 'white' ? 'white' : 'edge');
            Configuration::updateValue('JOSRA_REGENIMG_SHARPEN', Tools::getValue('JOSRA_REGENIMG_SHARPEN') ? '1' : '0');
            Configuration::updateValue('JOSRA_REGENIMG_UPSCALE', Tools::getValue('JOSRA_REGENIMG_UPSCALE') ? '1' : '0');
            $output .= $this->displayConfirmation($this->l('Ajustes de calidad guardados. Regenera con «Forzar» los tamaños que quieras rehacer.'));
        }

        $this->context->controller->addJS($this->_path . 'views/js/admin/josraregenimages.js');
        $this->context->controller->addCSS($this->_path . 'views/css/admin/josraregenimages.css');

        $forms = $this->renderForm() . $this->renderQualityForm();

        // Panel de actualizaciones: arriba si hay versión nueva, abajo si no
        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available'])
            ? $output . $panel . $forms
            : $output . $forms . $panel;
    }

    /** Ajustes del generador propio (formulario nativo) */
    protected function renderQualityForm()
    {
        $switch = function ($name, $label, $desc) {
            return [
                'type' => 'switch', 'label' => $label, 'name' => $name, 'is_bool' => true, 'desc' => $desc,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Sí')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ];
        };
        $form = ['form' => [
            'legend' => ['title' => $this->l('Calidad de las miniaturas'), 'icon' => 'icon-magic'],
            'description' => $this->l('Con «Calidad mejorada» el módulo genera él las miniaturas, con los mismos encajes, formatos y calidades de Diseño > Ajustes de imágenes, pero amplía con suavizado (PrestaShop amplía sin él y las fotos pequeñas salen pixeladas) y enfoca un poco al reducir. Solo afecta a lo que regeneres con este módulo.'),
            'input' => [
                $switch('JOSRA_REGENIMG_ENGINE', $this->l('Calidad mejorada'), JosraRegenImagesRenderer::available() ? $this->l('Si lo desactivas se usa el generador de PrestaShop, como antes.') : $this->l('El servidor no tiene la extensión GD: se usará el de PrestaShop.')),
                [
                    'type' => 'text', 'label' => $this->l('Recorte máximo en tamaños con «recortar»'), 'name' => 'JOSRA_REGENIMG_MAXCROP',
                    'suffix' => '%', 'class' => 'fixed-width-sm',
                    'desc' => $this->l('100 = recorta todo lo que sobre (como PrestaShop). Con 15-20 apenas se corta el producto y el hueco que queda se rellena según la opción de abajo. 0 = nunca recorta (igual que «ajustar»).'),
                ],
                [
                    'type' => 'select', 'label' => $this->l('Relleno del espacio sobrante'), 'name' => 'JOSRA_REGENIMG_FILL',
                    'desc' => $this->l('En «ajustar» y en el recorte suave. Con «color del borde», si el fondo de la foto es blanco se rellena de blanco y si no, del color de su borde, para que no se vea una banda.'),
                    'options' => ['query' => [
                        ['id' => 'edge', 'name' => $this->l('Color del borde de la foto')],
                        ['id' => 'white', 'name' => $this->l('Blanco')],
                    ], 'id' => 'id', 'name' => 'name'],
                ],
                $switch('JOSRA_REGENIMG_UPSCALE', $this->l('Ampliar fotos pequeñas en «ajustar»'), $this->l('Si la original es más pequeña que la miniatura, se amplía (con suavizado) hasta llenarla en vez de dejarla pequeña en el centro con mucho blanco alrededor.')),
                $switch('JOSRA_REGENIMG_SHARPEN', $this->l('Enfocar al reducir'), $this->l('Un enfoque suave que devuelve la nitidez que se pierde al reducir.')),
            ],
            'submit' => ['title' => $this->l('Guardar'), 'name' => 'submitJosraRegenQuality'],
        ]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->name;
        $helper->identifier = 'id_configuration';
        $helper->show_toolbar = false;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = 'submitJosraRegenQuality';
        $helper->currentIndex = $this->getConfigureBaseUrl();
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $maxcrop = Configuration::get('JOSRA_REGENIMG_MAXCROP');
        $helper->tpl_vars = ['fields_value' => [
            'JOSRA_REGENIMG_ENGINE' => Configuration::get('JOSRA_REGENIMG_ENGINE') !== 'ps' ? 1 : 0,
            'JOSRA_REGENIMG_MAXCROP' => $maxcrop === false ? 100 : (int) $maxcrop,
            'JOSRA_REGENIMG_FILL' => Configuration::get('JOSRA_REGENIMG_FILL') === 'white' ? 'white' : 'edge',
            'JOSRA_REGENIMG_UPSCALE' => Configuration::get('JOSRA_REGENIMG_UPSCALE') !== '0' ? 1 : 0,
            'JOSRA_REGENIMG_SHARPEN' => Configuration::get('JOSRA_REGENIMG_SHARPEN') !== '0' ? 1 : 0,
        ]];

        return $helper->generateForm([$form]);
    }

    protected function renderForm()
    {
        $imageTypes = JosraRegenImagesQueue::getImageTypesByEntity();
        $entityCounts = JosraRegenImagesQueue::getEntityCounts();

        $this->context->smarty->assign([
            'ajax_url' => $this->getConfigureUrl() . '&jrAjax=1',
            'token' => $this->getModuleToken(),
            'has_fitment' => JosraRegenImagesQueue::hasFitment(),
            'watermark_modules' => implode(', ', array_map(function ($m) { return $m['name']; }, JosraRegenImagesQueue::watermarkModules())),
            'entities' => JosraRegenImagesQueue::entities(),
            'image_settings_url' => $this->getImageSettingsUrl(),
            'batch_size' => (int) Configuration::get('JOSRA_REGENIMG_BATCH_SIZE') ?: 20,
            'image_types' => $imageTypes,
            'image_formats' => JosraRegenImagesQueue::getGenerationFormats(),
            'entity_counts' => $entityCounts,
            'module_dir' => $this->_path,
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }

    /**
     * URL de la página de configuración del módulo. En PrestaShop 8/9 ya no existe el controlador AdminModules
     * clásico: hay que pedir el enlace con los parámetros para que PrestaShop lo convierta a su ruta nueva
     * (antes se le pegaban a mano y las peticiones AJAX acababan en «Página no encontrada»).
     */
    /** Igual pero sin token (HelperForm le añade el suyo) */
    protected function getConfigureBaseUrl()
    {
        $params = ['configure' => $this->name, 'tab_module' => $this->tab, 'module_name' => $this->name];
        $url = $this->context->link->getAdminLink('AdminModules', false, [], $params);
        if (strpos($url, 'configure') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        return $url;
    }

    protected function getConfigureUrl()
    {
        $params = ['configure' => $this->name, 'tab_module' => $this->tab, 'module_name' => $this->name];
        if (version_compare(_PS_VERSION_, '1.7.5.0', '>=')) {
            $url = $this->context->link->getAdminLink('AdminModules', true, [], $params);
        } else {
            $url = $this->context->link->getAdminLink('AdminModules', true) . '&' . http_build_query($params);
        }

        return $url . (strpos($url, '?') === false ? '?' : '');
    }

    /**
     * Hasta PrestaShop 8 el token de AdminModules es fijo y se compara tal cual. En PrestaShop 9 es un token CSRF de
     * Symfony que cambia en cada generación (nunca coincidía: «Token inválido»); ahí la propia ruta de PrestaShop ya
     * valida el _token de la URL antes de llegar aquí, así que basta con exigir un empleado con sesión.
     */
    protected function isAjaxAllowed()
    {
        $token = (string) Tools::getValue('jrtoken');

        return $token !== ''
            && isset($this->context->employee)
            && Validate::isLoadedObject($this->context->employee)
            && hash_equals($this->getModuleToken(), $token);
    }

    /**
     * Token propio del módulo (fijo por empleado). El de PrestaShop 9 es un token CSRF de Symfony que cambia
     * en cada petición y no se puede comparar; la versión anterior en PS 9 aceptaba cualquier _token.
     */
    protected function getModuleToken()
    {
        $idEmployee = isset($this->context->employee->id) ? (int) $this->context->employee->id : 0;

        return Tools::substr(hash_hmac('sha256', $this->name . '|' . $idEmployee, _COOKIE_KEY_), 0, 32);
    }

    /** Enlace a Diseño > Ajustes de imágenes */
    protected function getImageSettingsUrl()
    {
        try {
            return $this->context->link->getAdminLink('AdminImages');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Dispatcher de acciones AJAX. Responde SIEMPRE en JSON.
     */
    protected function handleAjax()
    {
        header('Content-Type: application/json; charset=utf-8');

        // Seguridad: token de admin válido (evita CSRF / llamadas externas)
        if (!$this->isAjaxAllowed()) {
            die(json_encode(['success' => false, 'error' => 'Token inválido.']));
        }

        $action = Tools::getValue('jrAction');

        try {
            switch ($action) {
                case 'buildqueue':
                    $types = Tools::getValue('types', []);
                    $mode = (string) Tools::getValue('mode', 'missing');
                    if (!in_array($mode, ['missing', 'mismatch', 'force'], true)) {
                        $mode = 'missing';
                    }

                    if (!is_array($types) || empty($types)) {
                        die(json_encode(['success' => false, 'error' => 'Selecciona al menos un tamaño de imagen.']));
                    }

                    // Los formatos (jpg + webp/avif/png) son los configurados en PrestaShop, como la regeneración nativa
                    $total = JosraRegenImagesQueue::buildQueue($types, $mode, (bool) Tools::getValue('noimage'));
                    die(json_encode(['success' => true, 'total' => $total, 'reason' => $total ? '' : JosraRegenImagesQueue::emptyReason($mode)]));

                case 'nextbatch':
                    $batchSize = (int) Tools::getValue('batch_size', 20);
                    $result = JosraRegenImagesQueue::processNextBatch($batchSize, (bool) Tools::getValue('watermark'));
                    die(json_encode(array_merge(['success' => true], $result)));

                case 'progress':
                    $progress = JosraRegenImagesQueue::getProgress();
                    die(json_encode(array_merge(['success' => true], $progress)));

                case 'retryerrors':
                    $n = JosraRegenImagesQueue::retryErrors();
                    die(json_encode(array_merge(['success' => true, 'requeued' => $n], JosraRegenImagesQueue::getProgress())));

                case 'finish':
                    die(json_encode(['success' => true, 'cleared' => JosraRegenImagesQueue::clearAdminThumbCache()]));

                default:
                    die(json_encode(['success' => false, 'error' => 'Acción desconocida.']));
            }
        } catch (Throwable $e) {
            // Throwable: en PHP 8 los TypeError/Error no son Exception y rompían la respuesta JSON
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }
}
