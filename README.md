# onecatalog-prestashop

Модуль **импорта каталога OneCatalog** и **синхронизации цен/остатков (B2B)** для
**PrestaShop 8.x** (совместимо с 1.7.8+).

> ⚠️ **Статус: в разработке.** Production-релиза пока нет.
> Текущая работа — в ветке `dev`. В `main` попадают только подтверждённые
> production-версии (с тегом `vX.Y.Z`).

- Стандарт интеграции (канон): https://github.com/OneCatalog/onecatalog-standard — соответствует стандарту **v1.2**
- Эталонная реализация: https://github.com/OneCatalog/onecatalog-woocommerce
- Целевая платформа: **PrestaShop 8.x** (ObjectModel; `Manufacturer`/`Feature`/`Category` нативно)

## Возможности

- **Импорт каталога** из Wiki API: товары, категории-дерево, характеристики
  (нативные `Feature`/`FeatureValue`), габариты (конверсия единиц), изображения (обложка
  + галерея с трекингом качества и дедупом). Идемпотентность по `public_id`, цена
  импортом не задаётся, статус/артикул — только при создании.
- **Справочные сущности** (по умолчанию выкл, нативное прежде своего): бренд →
  `Manufacturer`, теги → нативные `Tag`, страна → feature, коллекции → feature/категория.
- **Виджет выбора** (пикер) + **AJAX-степпер** импорта: прогресс, сводка, отмена,
  сохранение результата.
- **Синхронизация цен/остатков (B2B)** по принципу **scan-and-diff**: пишутся только
  изменившиеся товары; цена → `Product.price`, скидка → `SpecificPrice`, остаток →
  `StockAvailable`. Стратегии цены × приоритет регионов.
- **Журнал импорта**; **хуки** для сайтового слоя (см. `docs/EVENTS.md`).

## Установка

1. Упаковать каталог `onecatalogimport/` в zip и загрузить через
   **Модули → Загрузить модуль**, либо положить в `modules/` и установить.
2. **Модули → OneCatalog Import → Настроить**: внести API-токен и язык.
3. Меню **OneCatalog** в админке: **Import**, **Prices & stock**, **Import log**.

## Использование

- **OneCatalog → Import**: «Выбрать товары» (пикер) или вставить список `public_id` →
  импорт с прогрессом.
- **OneCatalog → Prices & stock**: внести `url_key` + `private_key`, выбрать стратегию,
  «Synchronize now».
- **OneCatalog → Import log**: последние результаты.

## Структура

```
onecatalogimport/onecatalogimport.php         главный класс модуля (install/hooks/config)
onecatalogimport/classes/                     ядро: Api, Units, Media(+Store), B2bApi, PriceStock, Importer, B2bSync
onecatalogimport/controllers/admin/           AdminOnecatalog{Import,B2b,Log} (страницы + AJAX)
onecatalogimport/views/js|templates/admin/    пикер/степперы + шаблоны
docs/                                         план, ответы, хуки (EVENTS.md)
tests/                                         офлайн-тесты чистой логики
```

## Разработка

- Офлайн-тесты чистой логики (без PrestaShop): `php tests/units-test.php`,
  `php tests/media-test.php`, `php tests/pricestock-test.php`.
- gitflow: работа в `dev`; релиз — merge `dev`→`main` + тег `vX.Y.Z` после проверки.
