<?php
/**
 * josraregenimages - generador de miniaturas de calidad
 *
 * Hace lo mismo que ImageManager::resize de PrestaShop (mismos encajes, mismos formatos y calidades de
 * Diseño > Ajustes de imágenes), pero:
 *  - amplía siempre con suavizado (PrestaShop, si la original es más pequeña que la miniatura, usa un
 *    redimensionado sin suavizado y la foto sale pixelada, con escalones en los bordes);
 *  - enfoca un poco al reducir, que es lo que hace que una miniatura se vea nítida;
 *  - "recorte suave": en los tamaños con "recortar" se puede limitar cuánto se corta y rellenar el resto
 *    con el color del borde de la propia foto en vez de blanco;
 *  - calcula la imagen una sola vez y la guarda en todos los formatos.
 *
 * Solo usa GD, como PrestaShop.
 *
 * @author José Ramón Guzmán Sobrino
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraRegenImagesRenderer
{
    /** @var array opciones: maxcrop (0-100), fill (edge|white), sharpen (bool), upscale (bool) */
    protected $opt;

    public function __construct(array $options)
    {
        $this->opt = array_merge(['maxcrop' => 100, 'fill' => 'edge', 'sharpen' => true, 'upscale' => true], $options);
    }

    public static function available()
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
    }

    /**
     * @param string $source original
     * @param string $destBase destino sin extensión
     * @param int $w
     * @param int $h
     * @param string|null $fitment fit | crop | bound (null = fit)
     * @param array $formats jpg, webp, avif, png
     *
     * @return true|string
     */
    public function render($source, $destBase, $w, $h, $fitment, array $formats)
    {
        if (!file_exists($source) || !filesize($source)) {
            return 'el archivo original no existe o está vacío';
        }
        $info = @getimagesize($source);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return 'el archivo original no es una imagen válida (p. ej. una página HTML guardada como .jpg)';
        }
        if (!ImageManager::checkImageMemoryLimit($source)) {
            return 'no hay memoria suficiente para una imagen tan grande';
        }
        $src = $this->load($source, (int) $info[2]);
        if (!$src) {
            return 'no se pudo abrir el original (formato no soportado por GD)';
        }
        $src = $this->orient($src, $source);
        $sw = imagesx($src);
        $sh = imagesy($src);
        $w = (int) $w ?: $sw;
        $h = (int) $h ?: $sh;
        $hasAlpha = $this->hasAlpha($src, (int) $info[2]);

        // ---- escala y lienzo según el encaje
        $fitScale = min($w / $sw, $h / $sh);
        $cropScale = max($w / $sw, $h / $sh);
        if ($fitment === 'bound') {
            $s = min(1, $fitScale);
            $cw = max(1, (int) round($sw * $s));
            $ch = max(1, (int) round($sh * $s));
        } elseif ($fitment === 'crop') {
            $c = max(0, min(100, (int) $this->opt['maxcrop'])) / 100;
            $s = $c >= 1 ? $cropScale : min($cropScale, $fitScale / (1 - $c));
            $cw = $w;
            $ch = $h;
        } else {
            $s = $fitScale;
            if ($s > 1 && !$this->opt['upscale']) {
                $s = 1;
            }
            $cw = $w;
            $ch = $h;
        }

        // ---- zona del original que entra en el lienzo
        $dw = $sw * $s;
        $dh = $sh * $s;
        list($srcX, $srcW, $dstX, $dstW) = $this->axis($sw, $dw, $cw, $s);
        list($srcY, $srcH, $dstY, $dstH) = $this->axis($sh, $dh, $ch, $s);

        $needsFill = $dstX > 0 || $dstY > 0 || $dstW < $cw || $dstH < $ch;

        $files = [];
        foreach ($formats as $format) {
            $out = $this->outputType($format, (int) $info[2]);
            $alphaOut = in_array($out, ['png', 'webp', 'avif'], true);
            if (!$this->canWrite($out)) {
                return 'el servidor no puede guardar en formato ' . $out;
            }
            // un lienzo por tipo de fondo (transparente o color), reutilizado entre formatos iguales
            $key = $alphaOut && ($hasAlpha || $this->opt['fill'] === 'transparent') ? 'alpha' : 'solid';
            if (!isset($files[$key])) {
                $canvas = imagecreatetruecolor($cw, $ch);
                if ($key === 'alpha') {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    imagefilledrectangle($canvas, 0, 0, $cw, $ch, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
                } else {
                    list($r, $g, $b) = $needsFill && $this->opt['fill'] === 'edge' && !$hasAlpha ? $this->edgeColor($src) : [255, 255, 255];
                    imagefilledrectangle($canvas, 0, 0, $cw, $ch, imagecolorallocate($canvas, $r, $g, $b));
                    imagealphablending($canvas, true);
                }
                imagecopyresampled($canvas, $src, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);
                if ($this->opt['sharpen'] && $s < 0.95) {
                    $this->sharpen($canvas, $s);
                }
                $files[$key] = $canvas;
            }
            $tmp = $destBase . '.tmp' . uniqid() . '.' . $format;
            if (!$this->write($out, $files[$key], $tmp)) {
                @unlink($tmp);

                return 'no se pudo guardar ' . basename($destBase . '.' . $format);
            }
            // temporal + renombrar: nunca hay una imagen a medio escribir
            if (!@rename($tmp, $destBase . '.' . $format)) {
                @unlink($tmp);

                return 'no se pudo renombrar el archivo temporal a ' . $destBase . '.' . $format;
            }
            @chmod($destBase . '.' . $format, 0664);
            Hook::exec('actionOnImageResizeAfter', ['dst_file' => $destBase . '.' . $format, 'file_type' => $out]);
        }

        return true;
    }

    /** Recorte/centrado en un eje: [src_inicio, src_largo, dst_inicio, dst_largo] */
    protected function axis($srcLen, $scaledLen, $canvasLen, $s)
    {
        if ($scaledLen > $canvasLen) {
            $visible = $canvasLen / $s;

            return [(int) round(($srcLen - $visible) / 2), max(1, (int) round($visible)), 0, $canvasLen];
        }
        $len = max(1, (int) round($scaledLen));

        return [0, $srcLen, (int) floor(($canvasLen - $len) / 2), $len];
    }

    /** Como PrestaShop: con "PNG para todo" (o PNG si la original no es JPG) el .jpg lleva PNG dentro */
    protected function outputType($format, $srcType)
    {
        if ($format === 'jpg') {
            $q = Configuration::get('PS_IMAGE_QUALITY');
            if ($q === 'png_all' || ($q === 'png' && $srcType !== IMAGETYPE_JPEG)) {
                return 'png';
            }
        }

        return $format;
    }

    protected function canWrite($type)
    {
        $fn = ['jpg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp', 'avif' => 'imageavif', 'gif' => 'imagegif'];

        return isset($fn[$type]) && function_exists($fn[$type]);
    }

    /** Guarda con las calidades de Diseño > Ajustes de imágenes (como ImageManager::write, sin destruir la imagen) */
    protected function write($type, $img, $file)
    {
        switch ($type) {
            case 'png':
                $q = Configuration::get('PS_PNG_QUALITY');

                return imagepng($img, $file, $q === false ? 7 : (int) $q);
            case 'webp':
                $q = Configuration::get('PS_WEBP_QUALITY');

                return imagewebp($img, $file, $q === false ? 80 : (int) $q);
            case 'avif':
                $q = Configuration::get('PS_AVIF_QUALITY');

                return imageavif($img, $file, $q === false ? 80 : (int) $q);
            case 'gif':
                return imagegif($img, $file);
            default:
                $q = Configuration::get('PS_JPEG_QUALITY');
                imageinterlace($img, true);

                return imagejpeg($img, $file, $q === false ? 90 : (int) $q);
        }
    }

    protected function load($file, $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return @imagecreatefromjpeg($file);
            case IMAGETYPE_PNG:
                return @imagecreatefrompng($file);
            case IMAGETYPE_GIF:
                return @imagecreatefromgif($file);
        }
        if (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($file);
        }
        if (defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF && function_exists('imagecreatefromavif')) {
            return @imagecreatefromavif($file);
        }

        return false;
    }

    /** Orientación EXIF (fotos hechas con el móvil), como PrestaShop */
    protected function orient($img, $file)
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($file);
        $angles = [3 => 180, 6 => -90, 8 => 90];
        if ($exif && isset($exif['Orientation'], $angles[(int) $exif['Orientation']])) {
            $rotated = imagerotate($img, $angles[(int) $exif['Orientation']], 0);
            if ($rotated) {
                return $rotated;
            }
        }

        return $img;
    }

    protected function hasAlpha($img, $type)
    {
        if ($type === IMAGETYPE_JPEG) {
            return false;
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        // muestra rápida del borde: si algún píxel es transparente, se trata como imagen con transparencia
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(min($w, $h) / 40));
        for ($i = 0; $i < $w; $i += $step) {
            foreach ([0, $h - 1] as $y) {
                if (((imagecolorat($img, $i, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        for ($j = 0; $j < $h; $j += $step) {
            foreach ([0, $w - 1] as $x) {
                if (((imagecolorat($img, $x, $j) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Color medio del borde de la foto. Si es casi blanco se usa blanco puro (fondos de estudio), así no
     * aparece una banda "casi blanca" que se distinga del resto.
     */
    protected function edgeColor($img)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(min($w, $h) / 60));
        $r = $g = $b = $n = 0;
        $add = function ($x, $y) use ($img, &$r, &$g, &$b, &$n) {
            $c = imagecolorat($img, $x, $y);
            $r += ($c >> 16) & 0xFF;
            $g += ($c >> 8) & 0xFF;
            $b += $c & 0xFF;
            ++$n;
        };
        for ($i = 0; $i < $w; $i += $step) {
            $add($i, 0);
            $add($i, $h - 1);
        }
        for ($j = 0; $j < $h; $j += $step) {
            $add(0, $j);
            $add($w - 1, $j);
        }
        if (!$n) {
            return [255, 255, 255];
        }
        $rgb = [(int) round($r / $n), (int) round($g / $n), (int) round($b / $n)];
        if (min($rgb) >= 236) {
            return [255, 255, 255];
        }

        return $rgb;
    }

    /** Enfoque suave, más intenso cuanto más se ha reducido la foto */
    protected function sharpen($img, $scale)
    {
        if (!function_exists('imageconvolution')) {
            return;
        }
        // centro alto = enfoque ligero (24 suave, 16 más marcado)
        $center = $scale < 0.35 ? 16 : ($scale < 0.6 ? 20 : 28);
        $matrix = [[-1, -1, -1], [-1, $center, -1], [-1, -1, -1]];
        @imageconvolution($img, $matrix, $center - 8, 0);
    }
}
