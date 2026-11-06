<?php
/**
 * josraregenimages - cola de miniaturas
 *
 * @author José Ramón Guzmán Sobrino
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraRegenImagesQueue
{
    /** Encajes de PrestaShop 9.1+ (columna image_fitment de ps_image_type) */
    const FIT = 'fit';
    const CROP = 'crop';
    const BOUND = 'bound';

    /** @var array|null tamaños por nombre (medidas y encaje) */
    protected static $typesByName = null;

    /** @var array|null formatos a generar */
    protected static $formats = null;

    /** @var JosraRegenImagesRenderer|false|null */
    protected static $renderer = null;

    /** @var array|null módulos enganchados a actionWatermark */
    protected static $watermark = null;

    /** Carpeta de imágenes de producto (_PS_PROD_IMG_DIR_ ya no existe en PrestaShop 9) */
    protected static function productDir()
    {
        return defined('_PS_PRODUCT_IMG_DIR_') ? _PS_PRODUCT_IMG_DIR_ : _PS_PROD_IMG_DIR_;
    }

    /** @var array datos para explicar por qué la cola sale vacía */
    public static $stats = [];

    /**
     * Ruta (sin extensión) del original de una imagen de producto: la normal en subcarpetas (img/p/1/2/3/123.jpg)
     * o la antigua plana (img/p/ID_PRODUCTO-ID_IMAGEN.jpg), que dejan algunas migraciones y tiendas viejas.
     *
     * @return string|null
     */
    public static function productBase($idProduct, $idImage)
    {
        $dir = self::productDir();
        $split = $dir . Image::getImgFolderStatic((int) $idImage) . (int) $idImage;
        if (file_exists($split . '.jpg')) {
            return $split;
        }
        $legacy = $dir . (int) $idProduct . '-' . (int) $idImage;
        if ($idProduct && file_exists($legacy . '.jpg')) {
            return $legacy;
        }

        return null;
    }

    /** Explicación cuando no hay nada que regenerar */
    public static function emptyReason($mode)
    {
        $s = self::$stats;
        if (empty($s['entities'])) {
            return 'Los tamaños elegidos no se usan para ningún tipo de imagen (productos, categorías, marcas...). Revísalo en Diseño > Ajustes de imágenes.';
        }
        if (!empty($s['product_db']) && empty($s['product_found'])) {
            return sprintf('Hay %d imágenes de producto en la base de datos, pero no se encuentra ningún archivo original (img/p/.../ID.jpg). ¿Se han copiado las imágenes a esta tienda? Ejemplo buscado: %s', (int) $s['product_db'], isset($s['product_example']) ? $s['product_example'] : '');
        }
        if ($mode !== 'force') {
            return 'Todas las miniaturas de esos tamaños ya existen. Para rehacerlas todas, elige «Forzar».';
        }

        return 'No se ha encontrado ninguna imagen original para esos tamaños.';
    }

    /**
     * Entidades: carpeta y columna de ps_image_type que dice si el tamaño se usa para ellas.
     */
    public static function entities()
    {
        $entities = [
            'product' => ['dir' => self::productDir(), 'column' => 'products', 'label' => 'Productos'],
            'category' => ['dir' => _PS_CAT_IMG_DIR_, 'column' => 'categories', 'label' => 'Categorías'],
            'manufacturer' => ['dir' => _PS_MANU_IMG_DIR_, 'column' => 'manufacturers', 'label' => 'Marcas'],
            'supplier' => ['dir' => _PS_SUPP_IMG_DIR_, 'column' => 'suppliers', 'label' => 'Proveedores'],
        ];
        if (defined('_PS_STORE_IMG_DIR_')) {
            $entities['store'] = ['dir' => _PS_STORE_IMG_DIR_, 'column' => 'stores', 'label' => 'Tiendas'];
        }

        return $entities;
    }

    /**
     * Formatos que genera PrestaShop para cada miniatura: siempre jpg y, desde PrestaShop 8, los que estén activos
     * en Diseño > Ajustes de imágenes (webp, avif, png).
     */
    public static function getGenerationFormats()
    {
        if (self::$formats !== null) {
            return self::$formats;
        }
        $formats = ['jpg'];
        if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
            foreach (explode(',', (string) Configuration::get('PS_IMAGE_FORMAT')) as $format) {
                $format = trim(Tools::strtolower($format));
                if (in_array($format, ['png', 'webp', 'avif'], true) && !in_array($format, $formats, true)) {
                    $formats[] = $format;
                }
            }
        }

        return self::$formats = $formats;
    }

    /** Generador del módulo si está activado (opción «Calidad mejorada») */
    public static function renderer()
    {
        if (self::$renderer === null) {
            self::$renderer = false;
            if (Configuration::get('JOSRA_REGENIMG_ENGINE') !== 'ps' && JosraRegenImagesRenderer::available()) {
                $maxcrop = Configuration::get('JOSRA_REGENIMG_MAXCROP');
                self::$renderer = new JosraRegenImagesRenderer([
                    'maxcrop' => $maxcrop === false ? 100 : (int) $maxcrop,
                    'fill' => Configuration::get('JOSRA_REGENIMG_FILL') === 'white' ? 'white' : 'edge',
                    'sharpen' => Configuration::get('JOSRA_REGENIMG_SHARPEN') !== '0',
                    'upscale' => Configuration::get('JOSRA_REGENIMG_UPSCALE') !== '0',
                ]);
            }
        }

        return self::$renderer;
    }

    /** ¿Esta versión de PrestaShop tiene el ajuste de encaje? (9.1+) */
    public static function hasFitment()
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'image_type`');

        return is_array($row) && array_key_exists('image_fitment', $row);
    }

    protected static function getType($name)
    {
        if (self::$typesByName === null) {
            self::$typesByName = [];
            foreach ((array) Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'image_type`') as $row) {
                self::$typesByName[$row['name']] = $row;
            }
        }

        return isset(self::$typesByName[$name]) ? self::$typesByName[$name] : null;
    }

    /** Encaje del tamaño o null si la versión no lo tiene (comportamiento clásico) */
    protected static function getFitment($typeName)
    {
        $type = self::getType($typeName);

        return $type && !empty($type['image_fitment']) ? (string) $type['image_fitment'] : null;
    }

    /** Tamaños agrupados por entidad, para el formulario */
    public static function getImageTypesByEntity()
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'image_type` ORDER BY `name`');
        $grouped = [];
        foreach (self::entities() as $key => $e) {
            $grouped[$key] = [];
        }
        foreach ((array) $rows as $row) {
            $row['fitment'] = !empty($row['image_fitment']) ? $row['image_fitment'] : '';
            foreach (self::entities() as $key => $e) {
                if (!empty($row[$e['column']])) {
                    $grouped[$key][] = $row;
                }
            }
        }

        return $grouped;
    }

    /** Imágenes de origen por entidad (las que tienen el original en disco) */
    public static function getEntityCounts()
    {
        $counts = [];
        foreach (self::entities() as $key => $e) {
            $counts[$key] = $key === 'product'
                ? (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image`')
                : count(self::listOriginals($e['dir'], $key === 'category'));
        }

        return $counts;
    }

    /**
     * Originales de una carpeta "plana": ID.jpg y, en categorías, también ID_thumb.jpg (la miniatura
     * que sale en menús y subcategorías; la regeneración nativa también la procesa).
     *
     * @return array nombres sin extensión (p. ej. "12" o "12_thumb")
     */
    protected static function listOriginals($dir, $withThumb)
    {
        $files = @scandir($dir);
        $out = [];
        if (!$files) {
            return $out;
        }
        $re = $withThumb ? '/^([0-9]+(?:_thumb)?)\.jpg$/' : '/^([0-9]+)\.jpg$/';
        foreach ($files as $f) {
            if (preg_match($re, $f, $m)) {
                $out[] = $m[1];
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Cola                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Vacía la cola y la rellena con los tamaños elegidos.
     *
     * @param array $selectedTypeIds id_image_type
     * @param string $mode missing | mismatch | force
     * @param bool $noImage incluir las imágenes de "sin foto"
     *
     * @return int filas encoladas
     */
    public static function buildQueue(array $selectedTypeIds, $mode, $noImage = true)
    {
        @set_time_limit(0);
        $queueTable = _DB_PREFIX_ . JosraRegenImages::TABLE_QUEUE;
        Db::getInstance()->execute('TRUNCATE TABLE `' . $queueTable . '`');

        $selectedTypeIds = array_filter(array_map('intval', $selectedTypeIds));
        if (!$selectedTypeIds) {
            return 0;
        }
        $types = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'image_type` WHERE `id_image_type` IN (' . implode(',', $selectedTypeIds) . ')'
        );
        $types = is_array($types) ? $types : [];

        $idShop = (int) Context::getContext()->shop->id;
        $writer = new JosraRegenImagesQueueWriter($queueTable, $idShop);
        self::$stats = ['entities' => [], 'product_db' => 0, 'product_found' => 0];

        foreach (self::entities() as $entity => $e) {
            $entityTypes = array_values(array_filter($types, function ($t) use ($e) {
                return !empty($t[$e['column']]);
            }));
            if (!$entityTypes) {
                continue;
            }
            self::$stats['entities'][] = $entity;
            if ($entity === 'product') {
                self::queueProducts($writer, $entityTypes, $mode);
            } else {
                self::queueFlat($writer, $entity, $e['dir'], $entityTypes, $mode);
            }
            if ($noImage) {
                self::queueNoImage($writer, $entity, $e['dir'], $entityTypes, $mode);
            }
        }
        $writer->flush();

        return $writer->count;
    }

    /** ¿Hay que generar esta miniatura según el modo? */
    protected static function needed($mode, $sourcePath, $destBase, array $type, &$sourceSize)
    {
        if ($mode === 'force') {
            return true;
        }
        foreach (self::getGenerationFormats() as $format) {
            $file = $destBase . '.' . $format;
            if (!file_exists($file) || filesize($file) <= 0) {
                return true;
            }
        }
        if ($mode !== 'mismatch') {
            return false;
        }
        // "las que tienen otra medida": se compara la medida real del .jpg con la que saldría ahora
        if ($sourceSize === null) {
            $sourceSize = @getimagesize($sourcePath) ?: false;
        }
        $thumb = @getimagesize($destBase . '.jpg');
        if (!$sourceSize || !$thumb) {
            return true;
        }
        list($ew, $eh) = self::expectedSize((int) $sourceSize[0], (int) $sourceSize[1], (int) $type['width'], (int) $type['height'], self::getFitment($type['name']));

        return abs($thumb[0] - $ew) > 1 || abs($thumb[1] - $eh) > 1;
    }

    /**
     * Medida que dará ImageManager::resize (misma cuenta que PrestaShop).
     *
     * @return array [ancho, alto]
     */
    public static function expectedSize($sw, $sh, $w, $h, $fitment)
    {
        if ($sw <= 0 || $sh <= 0) {
            return [$w, $h];
        }
        $w = $w ?: $sw;
        $h = $h ?: $sh;
        $wd = $w / $sw;
        $hd = $h / $sh;
        if ($fitment === self::BOUND) {
            $r = min(1, $wd, $hd);

            return [(int) round($sw * $r), (int) round($sh * $r)];
        }
        if ($fitment === self::CROP) {
            return [$w, $h];
        }
        if (self::renderer()) {
            return [$w, $h]; // el generador del módulo siempre da la medida exacta en "ajustar" y "recortar"
        }
        $method = (int) Configuration::get('PS_IMAGE_GENERATION_METHOD');
        if (($wd > 1 && $hd > 1) || !$method) {
            return [$w, $h];
        }
        if ($method == 2) {
            return [(int) round($sw * $h / $sh), $h];
        }

        return [$w, (int) round($sh * $w / $sw)];
    }

    protected static function queueProducts(JosraRegenImagesQueueWriter $writer, array $types, $mode)
    {
        $images = Db::getInstance()->executeS('SELECT `id_image`, `id_product` FROM `' . _DB_PREFIX_ . 'image` ORDER BY `id_image`');
        foreach ((array) $images as $img) {
            $idImage = (int) $img['id_image'];
            ++self::$stats['product_db'];
            $base = self::productBase((int) $img['id_product'], $idImage);
            if ($base === null) {
                if (!isset(self::$stats['product_example'])) {
                    self::$stats['product_example'] = 'img/p/' . Image::getImgFolderStatic($idImage) . $idImage . '.jpg';
                }
                continue;
            }
            ++self::$stats['product_found'];
            $source = $base . '.jpg';
            $size = null;
            foreach ($types as $type) {
                if (self::needed($mode, $source, $base . '-' . $type['name'], $type, $size)) {
                    $writer->add('product', (int) $img['id_product'], $idImage, $type);
                }
            }
        }
    }

    /** Categorías (también ID_thumb.jpg), marcas, proveedores y tiendas: un archivo por id, sin subcarpetas */
    protected static function queueFlat(JosraRegenImagesQueueWriter $writer, $entity, $dir, array $types, $mode)
    {
        foreach (self::listOriginals($dir, $entity === 'category') as $base) {
            $isThumb = substr($base, -6) === '_thumb';
            $id = (int) $base;
            $size = null;
            foreach ($types as $type) {
                if (self::needed($mode, $dir . $base . '.jpg', $dir . $base . '-' . $type['name'], $type, $size)) {
                    // id_image = 1 marca la miniatura ID_thumb de la categoría
                    $writer->add($entity, $id, $isThumb ? 1 : 0, $type);
                }
            }
        }
    }

    /** Imágenes de "sin foto" por idioma: es-default-home_default.jpg, etc. */
    protected static function queueNoImage(JosraRegenImagesQueueWriter $writer, $entity, $dir, array $types, $mode)
    {
        foreach (Language::getLanguages(false) as $lang) {
            $source = self::noImageSource($dir, $lang['iso_code']);
            if (!$source) {
                continue;
            }
            $size = null;
            foreach ($types as $type) {
                if (self::needed($mode, $source, $dir . $lang['iso_code'] . '-default-' . $type['name'], $type, $size)) {
                    $writer->add('noimage_' . $entity, (int) $lang['id_lang'], 0, $type);
                }
            }
        }
    }

    /** Original de "sin foto": el del idioma, el del idioma por defecto o el general (como PrestaShop) */
    protected static function noImageSource($dir, $iso)
    {
        $candidates = [$dir . $iso . '.jpg'];
        $default = Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT'));
        if ($default) {
            $candidates[] = $dir . $default . '.jpg';
        }
        $candidates[] = _PS_IMG_DIR_ . 'noimageavailable.jpg';
        foreach ($candidates as $file) {
            if (is_file($file) && filesize($file) > 0) {
                return $file;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Proceso                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Siguiente lote pendiente.
     *
     * @param int $batchSize
     * @param bool $watermark avisar a los módulos de marca de agua al terminar cada imagen de producto
     */
    public static function processNextBatch($batchSize, $watermark = false)
    {
        $queueTable = _DB_PREFIX_ . JosraRegenImages::TABLE_QUEUE;
        $batchSize = max(1, min(200, (int) $batchSize));
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . $queueTable . '` WHERE `status` = \'pending\' ORDER BY `id_queue` ASC LIMIT ' . $batchSize
        );
        if (!$rows) {
            return ['done' => true, 'processed_in_batch' => 0, 'items' => []];
        }

        $items = [];
        $finishedImages = [];
        foreach ($rows as $row) {
            $ok = self::processRow($row);
            $status = $ok === true ? 'done' : 'error';
            Db::getInstance()->update(
                JosraRegenImages::TABLE_QUEUE,
                ['status' => $status, 'error_message' => $ok === true ? null : pSQL(Tools::substr((string) $ok, 0, 255))],
                '`id_queue` = ' . (int) $row['id_queue'],
                0,
                true
            );
            if ($row['entity'] === 'product') {
                $finishedImages[(int) $row['id_image']] = (int) $row['id_entity'];
            }
            $items[] = [
                'entity' => self::entityLabel($row),
                'id_entity' => (int) $row['id_entity'],
                'image_type_name' => $row['image_type_name'],
                'status' => $status,
                'message' => $status === 'done' ? 'Regenerada' : $ok,
            ];
        }

        // marca de agua: una vez por imagen, cuando ya no le queda ningún tamaño pendiente
        if ($watermark && $finishedImages && self::watermarkModules()) {
            foreach ($finishedImages as $idImage => $idProduct) {
                $left = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . $queueTable . '`
                    WHERE `entity` = \'product\' AND `id_image` = ' . (int) $idImage . ' AND `status` = \'pending\'');
                if (!$left) {
                    Hook::exec('actionWatermark', ['id_image' => $idImage, 'id_product' => $idProduct, 'image_type' => self::getGenerationFormats()]);
                }
            }
        }

        return ['done' => false, 'processed_in_batch' => count($rows), 'items' => $items];
    }

    protected static function entityLabel(array $row)
    {
        $entity = $row['entity'];
        $all = self::entities();
        if (strpos($entity, 'noimage_') === 0) {
            $e = substr($entity, 8);

            return 'Sin foto ' . (isset($all[$e]) ? Tools::strtolower($all[$e]['label']) : $e) . ' (' . Language::getIsoById((int) $row['id_entity']) . ')';
        }
        $label = isset($all[$entity]) ? $all[$entity]['label'] : $entity;
        if ($entity === 'category' && (int) $row['id_image'] === 1) {
            $label .= ' (miniatura)';
        }

        return $label;
    }

    /** Módulos activos enganchados a actionWatermark */
    public static function watermarkModules()
    {
        if (self::$watermark === null) {
            self::$watermark = (array) Db::getInstance()->executeS('SELECT m.`name` FROM `' . _DB_PREFIX_ . 'hook` h
                INNER JOIN `' . _DB_PREFIX_ . 'hook_module` hm ON (hm.`id_hook` = h.`id_hook`)
                INNER JOIN `' . _DB_PREFIX_ . 'module` m ON (m.`id_module` = hm.`id_module`)
                WHERE h.`name` = \'actionWatermark\' AND m.`active` = 1');
        }

        return self::$watermark;
    }

    /**
     * Regenera una miniatura en todos los formatos configurados.
     *
     * @return true|string
     */
    protected static function processRow(array $row)
    {
        try {
            $paths = self::resolvePaths($row);
            if (!$paths) {
                return 'Entrada de la cola no válida';
            }
            list($sourcePath, $destBase) = $paths;
            if (!file_exists($sourcePath)) {
                return 'Imagen original no encontrada: ' . $sourcePath;
            }
            $dir = dirname($destBase);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $type = self::getType($row['image_type_name']);
            $width = $type ? (int) $type['width'] : (int) $row['image_type_width'];
            $height = $type ? (int) $type['height'] : (int) $row['image_type_height'];
            $fitment = self::getFitment($row['image_type_name']);

            // generador del módulo (mejor calidad); si está desactivado, el de PrestaShop
            if ($renderer = self::renderer()) {
                $ok = $renderer->render($sourcePath, $destBase, $width, $height, $fitment, self::getGenerationFormats());

                return $ok === true ? true : 'No se pudo generar ' . basename($destBase) . ': ' . $ok;
            }

            foreach (self::getGenerationFormats() as $format) {
                $destPath = $destBase . '.' . $format;
                // temporal + renombrar: nunca hay un instante sin imagen ni una imagen a medio escribir
                $tmpPath = $destBase . '.tmp' . uniqid() . '.' . $format;
                $error = 0;
                $tw = $th = $sw = $sh = null;
                // el 13.º parámetro (encaje) solo existe desde PrestaShop 9.1; en versiones anteriores se ignora
                $result = ImageManager::resize($sourcePath, $tmpPath, $width, $height, $format, false, $error, $tw, $th, 5, $sw, $sh, $fitment ?: self::FIT);

                if (!$result) {
                    @unlink($tmpPath);
                    $why = [
                        1 => 'el archivo original no existe o está vacío',
                        2 => 'el archivo original no es una imagen válida (p. ej. una página HTML guardada como .jpg)',
                        3 => 'no hay memoria suficiente para una imagen tan grande',
                    ];

                    return 'No se pudo generar ' . basename($destPath) . ': ' . (isset($why[(int) $error]) ? $why[(int) $error] : 'error ' . (int) $error);
                }
                if (!@rename($tmpPath, $destPath)) {
                    @unlink($tmpPath);

                    return 'No se pudo renombrar el archivo temporal a ' . $destPath;
                }
            }

            return true;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array|false [ruta original, ruta destino SIN extensión]
     */
    protected static function resolvePaths(array $row)
    {
        $entities = self::entities();
        $entity = $row['entity'];
        $type = $row['image_type_name'];

        if ($entity === 'product') {
            $idImage = (int) $row['id_image'];
            $base = self::productBase((int) $row['id_entity'], $idImage);
            if ($base === null) {
                $base = self::productDir() . Image::getImgFolderStatic($idImage) . $idImage;
            }

            return [$base . '.jpg', $base . '-' . $type];
        }
        if (strpos($entity, 'noimage_') === 0) {
            $e = substr($entity, 8);
            if (!isset($entities[$e])) {
                return false;
            }
            $iso = Language::getIsoById((int) $row['id_entity']);
            if (!$iso) {
                return false;
            }
            $source = self::noImageSource($entities[$e]['dir'], $iso);

            return [$source ?: $entities[$e]['dir'] . $iso . '.jpg', $entities[$e]['dir'] . $iso . '-default-' . $type];
        }
        if (!isset($entities[$entity])) {
            return false;
        }
        $base = $entities[$entity]['dir'] . (int) $row['id_entity'] . ($entity === 'category' && (int) $row['id_image'] === 1 ? '_thumb' : '');

        return [$base . '.jpg', $base . '-' . $type];
    }

    /** Vuelve a poner en cola lo que dio error */
    public static function retryErrors()
    {
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . JosraRegenImages::TABLE_QUEUE . '`
            SET `status` = \'pending\', `error_message` = NULL WHERE `status` = \'error\'');

        return (int) Db::getInstance()->Affected_Rows();
    }

    /** Borra las miniaturas en caché del back office (img/tmp/product_mini_*.jpg, etc.) */
    public static function clearAdminThumbCache()
    {
        $n = 0;
        $files = @scandir(_PS_TMP_IMG_DIR_);
        foreach ((array) $files as $f) {
            if (preg_match('/^(product|category|manufacturer|supplier|store)_mini_[0-9_]+\.(jpg|png|webp|avif)$/', (string) $f)
                && @unlink(_PS_TMP_IMG_DIR_ . $f)) {
                ++$n;
            }
        }

        return $n;
    }

    public static function getProgress()
    {
        $queueTable = _DB_PREFIX_ . JosraRegenImages::TABLE_QUEUE;
        $db = Db::getInstance();
        $total = (int) $db->getValue('SELECT COUNT(*) FROM `' . $queueTable . '`');
        $done = (int) $db->getValue('SELECT COUNT(*) FROM `' . $queueTable . '` WHERE `status` = \'done\'');
        $errors = (int) $db->getValue('SELECT COUNT(*) FROM `' . $queueTable . '` WHERE `status` = \'error\'');
        $sample = (array) $db->executeS('SELECT `entity`, `id_entity`, `id_image`, `image_type_name`, `error_message` FROM `' . $queueTable . '`
             WHERE `status` = \'error\' ORDER BY `id_queue` DESC LIMIT 20');
        foreach ($sample as &$row) {
            $row['entity'] = self::entityLabel($row);
        }
        unset($row);

        return [
            'total' => $total,
            'done' => $done,
            'errors' => $errors,
            'pending' => max(0, $total - $done - $errors),
            'error_sample' => $sample,
        ];
    }
}

/**
 * Inserta en la cola por bloques de 500 filas.
 */
class JosraRegenImagesQueueWriter
{
    public $count = 0;
    protected $table;
    protected $idShop;
    protected $values = [];

    public function __construct($table, $idShop)
    {
        $this->table = $table;
        $this->idShop = (int) $idShop;
    }

    public function add($entity, $idEntity, $idImage, array $type)
    {
        $this->values[] = sprintf(
            "(%d, '%s', %d, %d, '%s', %d, %d, 0, 'pending', NOW())",
            $this->idShop,
            pSQL($entity),
            (int) $idEntity,
            (int) $idImage,
            pSQL($type['name']),
            (int) $type['width'],
            (int) $type['height']
        );
        ++$this->count;
        if (count($this->values) >= 500) {
            $this->flush();
        }
    }

    public function flush()
    {
        if (!$this->values) {
            return;
        }
        Db::getInstance()->execute('INSERT INTO `' . $this->table . '`
            (`id_shop`, `entity`, `id_entity`, `id_image`, `image_type_name`, `image_type_width`, `image_type_height`, `generate_webp`, `status`, `date_add`)
            VALUES ' . implode(',', $this->values));
        $this->values = [];
    }
}
