# История изменений — OneCatalog Import (PrestaShop)

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/),
нумерация версий — по [семантическому версионированию](https://semver.org/lang/ru/).

Соответствие стандарту интеграции: **v1.2** (см.
[onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard)).
Целевая платформа: **PrestaShop 8.x** (совм. 1.7.8+).

## [Не выпущено] — бэклог

### Реализовано на `dev` — §13 синхронизация цен и остатков (B2B) (версия 0.6.0)
- **`OneCatalogB2bApi`** + **`OneCatalogPriceStock`** — переиспользованы из OpenCart
  (клиент B2B-фида + чистые резолверы). Резолверы покрыты офлайн-тестом
  (`tests/pricestock-test.php`).
- **`OneCatalogB2bSync`** — **scan-and-diff** (§13.4): префетч `public_id→id_product`
  (`onecatalog_map`) и сигнатур (`onecatalog_meta`) одним запросом; пишутся только
  изменившиеся. Цена → `Product.price` (+`product_shop`), скидка → **`SpecificPrice`**
  (наша запись, перезапись), остаток → **`StockAvailable::setQuantity`**. Сигнатуры/коды
  → `onecatalog_meta`.
- **Страница «Цены и остатки»** (`AdminOnecatalogB2b`): настройки B2B (HelperForm) +
  браузерный степпер (`b2b-sync.js`, прогресс + сводка изменено/без изменений/нет в
  каталоге). Вкладка меню, EN. Дефолты B2B-настроек при установке.
- ✅ Оба сценария стандарта (импорт каталога §1–§12 + цены/остатки §13) — на месте.


### Реализовано на `dev` — справочные сущности (версия 0.5.0)
- **Бренд → нативный `Manufacturer`** (find-or-create по имени → `Product.id_manufacturer`).
- **Теги → нативные `Tag`** (`Tag::addTags` на все языки, перезапись).
- **Страна → feature «Country»**; **коллекции → feature «Collection» ИЛИ категории**
  (выбор цели в настройках). find-by-name переиспользует существующие.
- Все справочные сущности **по умолчанию выключены** (§3/§7 v1.2), «нативное прежде
  своего». Тумблеры + выбор цели коллекций на странице настроек (HelperForm), EN.


### Реализовано на `dev` — пикер + AJAX-степпер импорта + UX (версия 0.4.0)
- **Пикер** (`views/js/picker-loader.js`, §2.4 v1.2): iframe виджета; `parentOrigin =
  window.location.origin` (клиент); доверие по `event.source === iframe`; разбор
  JSON-строки; своя × + Esc. Импорт по `productPublicIds`. (Порт из OpenCart.)
- **AJAX-степпер** (`views/js/admin-import.js`, §6 v1.2): порции по «шагу» → admin-
  контроллер (`ajax=1&action=importBatch`); лоадер, сводка (создано/обновлено/ошибок),
  отмена, persist `localStorage`; пикер и поле ввода независимы, блок повторного запуска.
- **`AdminOnecatalogImportController`** (`ModuleAdminController`): страница «Импорт»
  (Smarty-шаблон) + `ajaxProcessImportBatch` (импорт порции через `OneCatalogImporter`,
  запись в `onecatalog_log`, JSON {results, log}).
- **Вкладки админ-меню**: родитель «OneCatalog» + дочерняя «Import» (устанавливаются в
  `install()`, удаляются в `uninstall()`). EN-строки.
- ✅ Импорт **кликается end-to-end** в админке PrestaShop.


### Реализовано на `dev` — медиа: обложка/галерея, качество, дедуп (версия 0.3.0)
- **`OneCatalogMedia`** (`classes/`) — чистые хелперы из OpenCart-порта (выбор размера,
  контент-ключ `sha1(path#size)`, MIME→расширение). Покрыто офлайн-тестом
  (`tests/media-test.php`).
- **`OneCatalogMediaStore`** — обложка + галерея → нативные `Image` (cover + позиции,
  миниатюры по типам `ImageType`); URL без расширения → ручное скачивание + MIME по
  содержимому (§2.3). **Дедуп скачивания** по контент-ключу (таблица `onecatalog_media`,
  кэш исходника), **трекинг качества** через сигнатуру набора (имена+размеры) в
  `onecatalog_meta`: не изменилось/не лучше → пропуск, иначе пересборка. Подключено в
  `Importer` после features. Одна битая картинка не валит импорт (§5.5).


### Реализовано на `dev` — ядро импорта одного товара (версия 0.2.0)
- **`OneCatalogApi`/`OneCatalogUnits`** (`classes/`) — переиспользованы из OpenCart-порта
  (чистая логика без зависимостей платформы). Units покрыт офлайн-тестом
  (`tests/units-test.php`).
- **`OneCatalogImporter`** — импорт одного товара: идемпотентность по `onecatalog_map`
  (не по `reference`, §5.1); `Product` (ObjectModel), `reference←article`, название/описание
  на все языки магазина; категории-дерево find-or-create (`Category`); характеристики →
  нативные `Feature`/`FeatureValue` (find-by-name, boolean→Yes/No, §5.4); габариты через
  Units → единицы магазина (`PS_WEIGHT_UNIT`/`PS_DIMENSION_UNIT`). **Цена и статус — только
  при создании** (§5.6), цена не синтезируется (0); очистка недопустимых символов имени.


### Реализовано на `dev` — каркас модуля (версия 0.1.0)
- **Главный класс** `OneCatalogImport extends Module` (PrestaShop 8.x / 1.7.8+):
  install/uninstall, версия, совместимость, `getContent()` — страница настроек.
- **Установка**: служебные таблицы (§5.1 — служебное вне формы товара) —
  `onecatalog_map` (идемпотентность public_id ↔ id_product), `onecatalog_meta`
  (сигнатуры/коды), `onecatalog_media` (дедуп), `onecatalog_b2b_staging` (отстойник),
  `onecatalog_log`. При удалении таблицы/настройки НЕ стираются (защита от задвоения).
- **Настройки** (`Configuration` + `HelperForm`): база/токен Wiki API, язык, шаг
  импорта (≥10), статус новых товаров, origin пикера. Исходные строки EN (`trans`).
- ⚠️ Следующий инкремент 0.2.0 — ядро импорта одного товара (§5).


### Дизайн (до кода)
- `docs/integration-plan.md` — маппинг сущностей и реализация под PrestaShop 8.x.
- `docs/integration-answers.md` — решения по вопросам (до старта кода).

### План инкрементов (на `dev`)
- 0.1.0 — каркас модуля (install/hooks/Tab) + служебные таблицы + настройки.
- 0.2.0 — ядро импорта одного товара (§5).
- 0.3.0 — медиа (качество + дедуп, §5.3).
- 0.4.0 — пикер (§2.4 v1.2) + AJAX-степпер + UX (§6 v1.2).
- 0.5.0 — справочные сущности + выбор цели (§3/§7).
- 0.6.0 — §13 B2B (цены/остатки, scan-and-diff).
- 0.7.0+ — §13.5 отстойник/ненайденные, §13.6 расписание, журнал импорта, полировка.
