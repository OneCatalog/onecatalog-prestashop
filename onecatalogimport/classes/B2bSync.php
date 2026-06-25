<?php
/**
 * OneCatalog — синхронизация цен/остатков из B2B-фида (§13, PrestaShop 8.x).
 *
 * scan-and-diff (§13.4): префетч public_id→id_product (onecatalog_map) и сигнатур
 * (onecatalog_meta) одним запросом; резолв чистыми резолверами; пишем только
 * изменившиеся. Цена → Product.price; скидка → SpecificPrice; остаток →
 * StockAvailable. Сигнатуры/коды — onecatalog_meta.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/B2bApi.php';
require_once __DIR__ . '/PriceStock.php';

class OneCatalogB2bSync
{
    public function processPage($start = 0, $limit = 200)
    {
        $api = new OneCatalogB2bApi(
            (string) (Configuration::get('ONECATALOG_B2B_BASE') ?: 'https://api.onecatalog.net/b2b/v1'),
            (string) Configuration::get('ONECATALOG_B2B_URL_KEY'),
            (string) Configuration::get('ONECATALOG_B2B_PRIVATE_KEY')
        );
        if (!$api->configured()) {
            return ['error' => 'b2b not configured'];
        }
        $data = $api->fetchPage($start, $limit);
        if (!is_array($data)) {
            return ['error' => 'feed fetch failed', 'next' => $start, 'more' => false];
        }

        $total = (int) ($data['meta']['counts'] ?? $data['meta']['total'] ?? 0);
        $known = (array) ($data['products']['known'] ?? []);
        $cfg = $this->cfg();

        $map = $this->mapPublicIds(array_keys($known));
        $sigs = $this->sigPrefetch(array_values($map));

        $scanned = 0;
        $changed = 0;
        $unchanged = 0;
        $missing = 0;

        foreach ($known as $publicId => $offers) {
            $publicId = (string) $publicId;
            $offers = (array) $offers;
            $scanned++;
            if (!isset($map[$publicId])) {
                $missing++;
                continue;
            }
            $idProduct = (int) $map[$publicId];
            $rec = OneCatalogPriceStock::resolveRecord($offers, $cfg);
            if (isset($sigs[$idProduct]) && $sigs[$idProduct] === $rec['sig']) {
                $unchanged++;
                continue;
            }
            $this->applyResolved($idProduct, $rec, $offers);
            $changed++;
        }

        $next = $start + $limit;
        $more = ($scanned > 0) && ($total > 0 ? $next < $total : $scanned >= $limit);

        return [
            'scanned' => $scanned, 'changed' => $changed, 'unchanged' => $unchanged,
            'missing' => $missing, 'total' => $total, 'next' => $next, 'more' => $more,
        ];
    }

    private function applyResolved($idProduct, array $rec, array $offers)
    {
        $db = Db::getInstance();

        // Цена → product + product_shop (живой фид перетирает синкаемые товары).
        if ($rec['regular'] !== null) {
            $price = (float) $rec['regular'];
            $db->execute('UPDATE `' . _DB_PREFIX_ . 'product` SET price = ' . $price . ', date_upd = NOW() WHERE id_product = ' . (int) $idProduct);
            $db->execute('UPDATE `' . _DB_PREFIX_ . 'product_shop` SET price = ' . $price . ' WHERE id_product = ' . (int) $idProduct);
        }

        // Скидка → SpecificPrice (наша запись: все группы/валюты/страны, без дат).
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_product = ' . (int) $idProduct
            . ' AND id_cart = 0 AND id_specific_price_rule = 0 AND from_quantity = 1 AND id_shop = 0 AND id_group = 0 AND id_customer = 0');
        if ($rec['sale'] !== null) {
            $sp = new SpecificPrice();
            $sp->id_product = (int) $idProduct;
            $sp->id_shop = 0;
            $sp->id_currency = 0;
            $sp->id_country = 0;
            $sp->id_group = 0;
            $sp->id_customer = 0;
            $sp->id_product_attribute = 0;
            $sp->price = (float) $rec['sale']; // абсолютная спец-цена
            $sp->from_quantity = 1;
            $sp->reduction = 0;
            $sp->reduction_type = 'amount';
            $sp->from = '0000-00-00 00:00:00';
            $sp->to = '0000-00-00 00:00:00';
            try {
                $sp->add();
            } catch (\Throwable $e) {
                // не валим синк из-за одной скидки
            }
        }

        // Остаток → StockAvailable (суммарный по складам фида).
        if (!empty($rec['manage']) && class_exists('StockAvailable')) {
            StockAvailable::setQuantity((int) $idProduct, 0, (int) round((float) $rec['qty']));
        }

        // Служебное в meta (не поля товара, §5.1).
        $this->metaSet($idProduct, 'pricestock_sig', (string) $rec['sig']);
        $codes = OneCatalogPriceStock::extractCodes($offers);
        $flat = [];
        foreach ($codes as $c) {
            $flat[] = $c['supplier_id'] . ':' . $c['code'];
        }
        $this->metaSet($idProduct, 'supplier_code', implode(',', $flat));
    }

    private function cfg()
    {
        return [
            'region_prio' => $this->csvInts(Configuration::get('ONECATALOG_B2B_REGION_PRIORITY')),
            'supplier_prio' => $this->csvInts(Configuration::get('ONECATALOG_B2B_SUPPLIER_PRIORITY')),
            'strategy' => (string) (Configuration::get('ONECATALOG_B2B_STRATEGY') ?: 'min'),
            'supplier_fix' => (int) Configuration::get('ONECATALOG_B2B_SUPPLIER_FIXED'),
            'promo_as_sale' => (string) Configuration::get('ONECATALOG_B2B_PROMO_AS_SALE') !== '0',
            'manage_stock' => (int) Configuration::get('ONECATALOG_B2B_MANAGE_STOCK') === 1,
            'decimal_stock' => false,
        ];
    }

    private function csvInts($s)
    {
        $out = [];
        foreach (explode(',', (string) $s) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $out[] = (int) $part;
            }
        }
        return $out;
    }

    private function mapPublicIds(array $publicIds)
    {
        $publicIds = array_values(array_filter(array_map('strval', $publicIds)));
        if (!$publicIds) {
            return [];
        }
        $in = [];
        foreach ($publicIds as $pid) {
            $in[] = "'" . pSQL($pid) . "'";
        }
        $rows = Db::getInstance()->executeS('SELECT id_product, public_id FROM `' . _DB_PREFIX_ . 'onecatalog_map` WHERE public_id IN (' . implode(',', $in) . ')');
        $map = [];
        foreach ((array) $rows as $r) {
            $map[(string) $r['public_id']] = (int) $r['id_product'];
        }
        return $map;
    }

    private function sigPrefetch(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = Db::getInstance()->executeS('SELECT id_product, `value` FROM `' . _DB_PREFIX_ . "onecatalog_meta` WHERE meta_key = 'pricestock_sig' AND id_product IN (" . implode(',', $ids) . ')');
        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r['id_product']] = (string) $r['value'];
        }
        return $out;
    }

    private function metaSet($idProduct, $key, $value)
    {
        Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . 'onecatalog_meta` (id_product, meta_key, `value`) VALUES ('
            . (int) $idProduct . ", '" . pSQL($key) . "', '" . pSQL($value) . "')");
    }
}
