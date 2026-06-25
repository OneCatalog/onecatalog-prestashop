<?php
/**
 * OneCatalog Import — ядро импорта одного товара (PrestaShop 8.x, §5).
 *
 * Идемпотентность по public_id через onecatalog_map (не по reference, §5.1).
 * Товар/категории/features — нативные ObjectModel; карта/связи — прямой Db.
 * §5.6: цена и статус — только при создании; цена не синтезируется (0). Габариты —
 * Units → единицы магазина.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Units.php';

class OneCatalogImporter
{
    private $langs = null;

    public function importByPublicId($publicId)
    {
        $payload = $this->api()->getProduct($publicId);
        if ($payload === null) {
            return ['status' => 'error', 'public_id' => $publicId, 'message' => 'product not found in API'];
        }
        return $this->importPayload($payload, $publicId);
    }

    public function importPayload(array $p, $publicId = null)
    {
        $publicId = (string) ($publicId !== null ? $publicId : ($p['public_id'] ?? ''));
        if ($publicId === '') {
            return ['status' => 'error', 'public_id' => '', 'message' => 'empty public_id'];
        }

        $name = trim((string) ($p['name'] ?? $p['title'] ?? $p['menutitle'] ?? ''));
        $description = (string) ($p['description_text'] ?? $p['description'] ?? '');
        $article = trim((string) ($p['article'] ?? ''));
        $dim = $this->resolveDimensions($p);

        $existingId = $this->mapGet($publicId);

        try {
            $catIds = $this->resolveCategories($p);
            $defaultCat = $catIds ? (int) $catIds[0] : (int) Configuration::get('PS_HOME_CATEGORY');

            if ($existingId && Product::existsInDatabase((int) $existingId, 'product')) {
                $product = new Product((int) $existingId);
                $status = 'updated';
            } else {
                $product = new Product();
                $existingId = 0;
                $status = 'created';
            }

            foreach ($this->langIds() as $idLang) {
                $product->name[$idLang] = $this->cleanName($name);
                $product->description[$idLang] = $description;
                if (!$existingId) {
                    $product->link_rewrite[$idLang] = Tools::str2url($name) ?: ('product-' . $publicId);
                }
            }

            $product->weight = (float) $dim['weight'];
            $product->width = (float) $dim['width'];
            $product->height = (float) $dim['height'];
            $product->depth = (float) $dim['depth'];

            if (!$existingId) {
                // §5.6 — только при создании: артикул, цена (0, не синтезируем), статус.
                $product->reference = $article;
                $product->price = 0;
                $product->active = ((int) Configuration::get('ONECATALOG_NEW_ACTIVE') === 0) ? 0 : 1;
                $product->id_category_default = $defaultCat;
            }

            if (!$product->save()) {
                return ['status' => 'error', 'public_id' => $publicId, 'message' => 'product save failed'];
            }
            $id = (int) $product->id;

            if (!$existingId) {
                $this->mapSet($id, $publicId);
            }

            // Категории (импорт владеет привязкой) + features.
            if ($catIds) {
                $product->updateCategories(array_map('strval', $catIds));
                if ((int) $product->id_category_default <= 0 || !in_array((int) $product->id_category_default, $catIds, true)) {
                    $product->id_category_default = (int) $catIds[0];
                    $product->save();
                }
            }
            $this->assignFeatures($id, $this->resolveFeatures($p));

            // Медиа: обложка + галерея (дедуп + трекинг качества, §5.3).
            require_once __DIR__ . '/MediaStore.php';
            (new OneCatalogMediaStore())->applyMedia($id, $p);

            return ['status' => $status, 'public_id' => $publicId, 'id_product' => $id];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'public_id' => $publicId, 'message' => $e->getMessage()];
        }
    }

    // --- категории (find-or-create по имени в рамках родителя) ----------------

    private function resolveCategories(array $p)
    {
        $cats = $p['categories'] ?? null;
        if (!is_array($cats) || !$cats) {
            return [];
        }
        $home = (int) Configuration::get('PS_HOME_CATEGORY');
        $leaves = [];
        foreach ($cats as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $chain = $this->categoryChain($cat);
            $parentId = $home;
            $leafId = 0;
            foreach ($chain as $node) {
                $title = trim((string) ($node['menutitle'] ?? $node['name'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $leafId = $this->ensureCategory($title, $parentId);
                if (!$leafId) {
                    break;
                }
                $parentId = $leafId;
            }
            if ($leafId) {
                $leaves[$leafId] = $leafId;
            }
        }
        return array_values($leaves);
    }

    private function categoryChain(array $start)
    {
        $chain = [];
        $seen = [];
        $node = $start;
        while (is_array($node)) {
            $id = (int) ($node['id'] ?? 0);
            if ($id !== 0) {
                if (isset($seen[$id])) {
                    break;
                }
                $seen[$id] = true;
            }
            array_unshift($chain, $node);
            $parent = $node['parent_category'] ?? null;
            $node = is_array($parent) ? $parent : null;
        }
        return $chain;
    }

    private function ensureCategory($name, $parentId)
    {
        $db = Db::getInstance();
        $existing = (int) $db->getValue(
            'SELECT c.id_category FROM `' . _DB_PREFIX_ . 'category` c
             JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON c.id_category = cl.id_category
             WHERE c.id_parent = ' . (int) $parentId . " AND cl.name = '" . pSQL($name) . "' LIMIT 1"
        );
        if ($existing) {
            return $existing;
        }
        $category = new Category();
        foreach ($this->langIds() as $idLang) {
            $category->name[$idLang] = $name;
            $category->link_rewrite[$idLang] = Tools::str2url($name) ?: ('cat-' . uniqid());
        }
        $category->id_parent = (int) $parentId;
        $category->active = 1;
        if (!$category->add()) {
            return 0;
        }
        return (int) $category->id;
    }

    // --- характеристики → features (find-by-name) ----------------------------

    private function resolveFeatures(array $p)
    {
        $options = $p['options'] ?? null;
        if (!is_array($options) || !$options) {
            return [];
        }
        $out = [];
        foreach ($options as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $label = trim((string) ($opt['specification_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = (string) ($opt['specification_type'] ?? 'text');
            if ($type === 'numeric') {
                $num = $opt['numeric_option'] ?? null;
                $val = ($num === null || $num === '') ? '' : (string) $num;
            } elseif ($type === 'boolean') {
                $val = (($opt['bool_option'] ?? null) === true) ? 'Yes' : 'No';
            } else {
                $val = trim((string) ($opt['specification_option_name'] ?? ''));
            }
            if ($val === '') {
                continue;
            }
            $featureId = $this->ensureFeature($label);
            $valueId = $featureId ? $this->ensureFeatureValue($featureId, $val) : 0;
            if ($featureId && $valueId) {
                $out[] = ['id_feature' => $featureId, 'id_feature_value' => $valueId];
            }
        }
        return $out;
    }

    private function ensureFeature($name)
    {
        $db = Db::getInstance();
        $id = (int) $db->getValue(
            'SELECT f.id_feature FROM `' . _DB_PREFIX_ . 'feature` f
             JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON f.id_feature = fl.id_feature
             WHERE fl.name = \'' . pSQL($name) . "' LIMIT 1"
        );
        if ($id) {
            return $id;
        }
        $feature = new Feature();
        foreach ($this->langIds() as $idLang) {
            $feature->name[$idLang] = $name;
        }
        return $feature->add() ? (int) $feature->id : 0;
    }

    private function ensureFeatureValue($featureId, $value)
    {
        $db = Db::getInstance();
        $id = (int) $db->getValue(
            'SELECT fv.id_feature_value FROM `' . _DB_PREFIX_ . 'feature_value` fv
             JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON fv.id_feature_value = fvl.id_feature_value
             WHERE fv.id_feature = ' . (int) $featureId . " AND fvl.value = '" . pSQL($value) . "' LIMIT 1"
        );
        if ($id) {
            return $id;
        }
        $fv = new FeatureValue();
        $fv->id_feature = (int) $featureId;
        $fv->custom = 0;
        foreach ($this->langIds() as $idLang) {
            $fv->value[$idLang] = $value;
        }
        return $fv->add() ? (int) $fv->id : 0;
    }

    private function assignFeatures($productId, array $features)
    {
        $db = Db::getInstance();
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'feature_product` WHERE id_product = ' . (int) $productId);
        foreach ($features as $f) {
            $db->insert('feature_product', [
                'id_feature' => (int) $f['id_feature'],
                'id_product' => (int) $productId,
                'id_feature_value' => (int) $f['id_feature_value'],
            ]);
        }
    }

    // --- габариты (Units → единицы магазина, §5.6) ---------------------------

    private function resolveDimensions(array $p)
    {
        $wUnit = (string) (Configuration::get('PS_WEIGHT_UNIT') ?: 'kg');
        $lUnit = (string) (Configuration::get('PS_DIMENSION_UNIT') ?: 'cm');
        $sizes = is_array($p['sizes'] ?? null) ? $p['sizes'] : [];

        $weightGrams = $this->baseValue($sizes, ['weight'], 'weight_unit', 'weight');
        $lMm = $this->baseValue($sizes, ['length'], 'length_unit', 'length');
        $wMm = $this->baseValue($sizes, ['width'], 'length_unit', 'length');
        $hMm = $this->baseValue($sizes, ['height', 'thickness'], 'length_unit', 'length');

        return [
            'weight' => $weightGrams === null ? 0.0 : OneCatalogUnits::weight($weightGrams, $wUnit),
            'width' => $wMm === null ? 0.0 : OneCatalogUnits::length($wMm, $lUnit),
            'height' => $hMm === null ? 0.0 : OneCatalogUnits::length($hMm, $lUnit),
            'depth' => $lMm === null ? 0.0 : OneCatalogUnits::length($lMm, $lUnit),
        ];
    }

    private function baseValue(array $sizes, array $keys, $unitKey, $kind)
    {
        foreach ($keys as $k) {
            if (isset($sizes[$k]) && $sizes[$k] !== '' && is_numeric($sizes[$k])) {
                $val = (float) $sizes[$k];
                $unit = (string) ($sizes[$unitKey] ?? '');
                if ($unit !== '') {
                    return $kind === 'weight' ? OneCatalogUnits::toBaseWeight($val, $unit) : OneCatalogUnits::toBaseLength($val, $unit);
                }
                return $val;
            }
        }
        return null;
    }

    // --- инфраструктура ------------------------------------------------------

    private function cleanName($name)
    {
        // PrestaShop запрещает в названии символы <>;=#{} — подчистим.
        return trim(preg_replace('/[<>;=#{}]/u', ' ', (string) $name)) ?: 'OneCatalog product';
    }

    private function mapGet($publicId)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . "onecatalog_map` WHERE public_id = '" . pSQL($publicId) . "' LIMIT 1"
        );
        return $id ?: null;
    }

    private function mapSet($productId, $publicId)
    {
        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'onecatalog_map` (id_product, public_id) VALUES ('
            . (int) $productId . ", '" . pSQL($publicId) . "')"
        );
    }

    private function langIds()
    {
        if ($this->langs === null) {
            $this->langs = [];
            foreach (Language::getLanguages(false) as $l) {
                $this->langs[] = (int) $l['id_lang'];
            }
            if (!$this->langs) {
                $this->langs[] = (int) Configuration::get('PS_LANG_DEFAULT');
            }
        }
        return $this->langs;
    }

    private function api()
    {
        return new OneCatalogApi(
            (string) Configuration::get('ONECATALOG_API_BASE'),
            (string) Configuration::get('ONECATALOG_API_TOKEN'),
            (string) (Configuration::get('ONECATALOG_LANG') ?: 'en')
        );
    }
}
