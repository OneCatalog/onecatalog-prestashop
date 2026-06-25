# Хуки расширения (§8) — точки расширения для сайтового слоя

Модуль публикует собственные хуки PrestaShop, через которые сайт/тема дополняет логику
**без форка** модуля. Поля payload, не входящие в маппинг ядра (текстура, площадь
упаковки, рейтинг, раскладка цен по регионам / остатков по складам), дозаполняются в
обработчике хука.

## Доступные хуки

| Хук | Когда | Параметры |
|---|---|---|
| `actionOnecatalogProductImported` | после импорта одного товара | `id_product`, `public_id`, `status` ('created'\|'updated'), `payload` |
| `actionOnecatalogPriceStockUpdated` | после записи цены/остатка (B2B, §13.7) | `id_product`, `record`, `offers` — **сырые офферы** фида (все регионы/склады) |

`record` = `['regular','sale','purchasing','manage','qty','stock_raw','status','sig']`.

## Как подписаться

В своём модуле реализуйте метод-обработчик и зарегистрируйтесь на хук:

```php
public function install()
{
    return parent::install()
        && $this->registerHook('actionOnecatalogPriceStockUpdated');
}

public function hookActionOnecatalogPriceStockUpdated(array $params)
{
    $idProduct = (int) $params['id_product'];
    foreach ($params['offers'] as $offer) {
        // разложить цены по регионам / остатки по складам в свои поля/таблицы
    }
}
```

## Change-detection и хуки (важно, §13.4)

Синхронизация цен/остатков пишет товар и шлёт `actionOnecatalogPriceStockUpdated`
**только если изменилась сигнатура** результата (цена/скидка/остаток/статус). Если в
обработчике вы раскладываете весь payload (все регионы/склады), изменения в неосновных
регионах/складах **не вызовут** обновление — scan-and-diff их пропустит. В этом случае
расширьте сигнатуру (в будущих версиях — через фильтр) или примите, что обновление
триггерится изменением основного региона/итогового остатка.
