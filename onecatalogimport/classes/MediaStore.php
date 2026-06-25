<?php
/**
 * OneCatalog Import — медиа для PrestaShop (§2.3, §5.3).
 *
 * Обложка + галерея → нативные `Image` (cover + позиции). URL без расширения →
 * ручное скачивание, MIME по содержимому. Дедуп скачивания по контент-ключу
 * (таблица onecatalog_media: один и тот же исходник качается один раз и кэшируется).
 * Идемпотентность набора — сигнатура (имена+размеры) в onecatalog_meta: не изменилось
 * и качество не лучше → пропуск; иначе пересборка изображений товара. Трекинг качества
 * (min/middle/max) через размер в сигнатуре.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/Media.php';
require_once __DIR__ . '/Api.php';

class OneCatalogMediaStore
{
    public function applyMedia($idProduct, array $p)
    {
        $idProduct = (int) $idProduct;
        $hasToken = (string) Configuration::get('ONECATALOG_API_TOKEN') !== '';

        // Обложка.
        $items = [];
        $coverUrls = is_array($p['images_urls'] ?? null) ? $p['images_urls'] : [];
        if ($coverUrls) {
            $pick = OneCatalogMedia::pickSizeInfo($coverUrls, $hasToken);
            if ($pick['url'] !== '') {
                $items[] = ['url' => $pick['url'], 'size' => $pick['size'], 'key' => 'cover', 'cover' => true];
            }
        }
        // Галерея.
        foreach (($p['files'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $cat = (string) ($f['category'] ?? '');
            if ($cat !== '' && $cat !== 'images') {
                continue;
            }
            $urls = is_array($f['urls'] ?? null) ? $f['urls'] : [];
            $pick = OneCatalogMedia::pickSizeInfo($urls, $hasToken);
            if ($pick['url'] === '') {
                continue;
            }
            $name = (string) ($f['name'] ?? OneCatalogMedia::fileKey($pick['url']) ?? $pick['url']);
            $items[] = ['url' => $pick['url'], 'size' => $pick['size'], 'key' => $name, 'cover' => false];
        }

        if (!$items) {
            return;
        }

        // Сигнатура набора (имя/ключ + размер) — трекинг качества и идемпотентность.
        $sigParts = [];
        foreach ($items as $it) {
            $sigParts[] = $it['key'] . ':' . $it['size'];
        }
        $sig = sha1(implode('|', $sigParts));
        if ($this->metaGet($idProduct, 'media_sig') === $sig) {
            return; // не изменилось и качество не лучше
        }

        // Пересборка: импорт владеет галереей товара.
        $this->deleteProductImages($idProduct);

        foreach ($items as $it) {
            $src = $this->sideloadSource($it['url'], $it['size']);
            if ($src !== '') {
                $this->createProductImage($idProduct, $src, (bool) $it['cover']);
            }
        }
        $this->metaSet($idProduct, 'media_sig', $sig);
    }

    /** Скачать исходник с дедупом по контент-ключу → путь к локальному файлу или ''. */
    private function sideloadSource($url, $size)
    {
        $key = OneCatalogMedia::fileKey($url);
        if ($key === '') {
            $key = sha1($url);
        }

        $db = Db::getInstance();
        $cached = (string) $db->getValue('SELECT `file` FROM `' . _DB_PREFIX_ . "onecatalog_media` WHERE content_key = '" . pSQL($key) . "' LIMIT 1");
        if ($cached !== '' && is_file($cached)) {
            return $cached;
        }
        if ($cached !== '') {
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . "onecatalog_media` WHERE content_key = '" . pSQL($key) . "'");
        }

        $api = new OneCatalogApi(
            (string) Configuration::get('ONECATALOG_API_BASE'),
            (string) Configuration::get('ONECATALOG_API_TOKEN'),
            (string) (Configuration::get('ONECATALOG_LANG') ?: 'en')
        );
        $bin = $api->getBinary($url);
        if ($bin === null) {
            return '';
        }
        $ext = OneCatalogMedia::mimeToExt($bin['content_type']);
        if ($ext === null && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $ext = OneCatalogMedia::mimeToExt(finfo_buffer($fi, $bin['body']));
            finfo_close($fi);
        }
        if ($ext === null) {
            return '';
        }

        $dir = rtrim(sys_get_temp_dir(), '/') . '/onecatalog';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        $file = $dir . '/' . $key . '.' . $ext;
        if (false === @file_put_contents($file, $bin['body'])) {
            return '';
        }

        $db->execute('INSERT INTO `' . _DB_PREFIX_ . 'onecatalog_media` (content_key, size, file, shared, date_add) VALUES ('
            . "'" . pSQL($key) . "', '" . pSQL($size) . "', '" . pSQL($file) . "', 0, NOW())");
        return $file;
    }

    /** Создать Image товара из локального исходника (+ миниатюры по типам). */
    private function createProductImage($idProduct, $srcFile, $isCover)
    {
        try {
            $ext = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION)) ?: 'jpg';
            $image = new Image();
            $image->id_product = (int) $idProduct;
            $image->position = Image::getHighestPosition((int) $idProduct) + 1;
            $image->cover = $isCover ? 1 : 0;
            $image->image_format = ($ext === 'jpeg') ? 'jpg' : $ext;
            if (!$image->add()) {
                return;
            }
            if (method_exists($image, 'associateTo')) {
                $image->associateTo(Shop::getContextListShopID());
            }

            $newPath = $image->getPathForCreation();
            $dest = $newPath . '.' . $image->image_format;
            if (!ImageManager::resize($srcFile, $dest)) {
                @copy($srcFile, $dest);
            }
            foreach (ImageType::getImagesTypes('products') as $it) {
                ImageManager::resize(
                    $srcFile,
                    $newPath . '-' . stripslashes($it['name']) . '.' . $image->image_format,
                    (int) $it['width'],
                    (int) $it['height'],
                    $image->image_format
                );
            }
        } catch (\Throwable $e) {
            // одна битая картинка не валит импорт (§5.5)
        }
    }

    private function deleteProductImages($idProduct)
    {
        foreach (Image::getImages((int) Configuration::get('PS_LANG_DEFAULT'), (int) $idProduct) as $row) {
            try {
                $img = new Image((int) $row['id_image']);
                $img->delete();
            } catch (\Throwable $e) {
            }
        }
    }

    private function metaGet($idProduct, $key)
    {
        return (string) Db::getInstance()->getValue(
            'SELECT `value` FROM `' . _DB_PREFIX_ . 'onecatalog_meta` WHERE id_product = ' . (int) $idProduct
            . " AND meta_key = '" . pSQL($key) . "' LIMIT 1"
        );
    }

    private function metaSet($idProduct, $key, $value)
    {
        Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . 'onecatalog_meta` (id_product, meta_key, `value`) VALUES ('
            . (int) $idProduct . ", '" . pSQL($key) . "', '" . pSQL($value) . "')");
    }
}
