# Интеграционный план: PrestaShop 8.x

Порт OneCatalog Import под **PrestaShop 8.x** (совместимо с 1.7.8+) по стандарту
интеграции **v1.2**. Эталон логики — onecatalog-woocommerce; ядро (Api/Units/Media/
PriceStock-резолверы) переносим из OpenCart-порта почти как есть.

Два сценария стандарта внедряем по отдельности:
1. **Импорт каталога** из Wiki API (§1–§12). Цену импорт НЕ задаёт (§5.6).
2. **Синхронизация цен/остатков** из B2B-фида (§13) — change-detection.

---

## Платформа и упаковка

- **Модуль PrestaShop**: каталог `onecatalogimport/` с главным классом
  `OneCatalogImport extends Module` (файл `onecatalogimport.php`).
  - `install()` — регистрация хуков + создание служебных таблиц + добавление вкладок
    (Tab) админ-меню; `uninstall()` — обратимо (карта public_id не стирается).
  - **getContent()** — страница настроек (HelperForm).
  - **Admin-контроллеры** (`controllers/admin/…`, `ModuleAdminController`): операционные
    страницы Импорт / Цены и остатки / Журнал, AJAX-обработчики степперов.
- **Структура:**
  ```
  onecatalogimport.php                         главный класс модуля (install/hooks/config)
  classes/Api.php Units.php Media.php           ядро (порт из OpenCart, namespace OneCatalog\PrestaShop)
  classes/B2bApi.php PriceStock.php
  classes/Importer.php B2bSync.php Staging.php  логика (ObjectModel + Db)
  controllers/admin/AdminOnecatalog*.php        страницы + AJAX
  views/js/ views/templates/admin/             пикер-лоадер, степперы, шаблоны
  translations/                                 i18n (trans, домен Modules.Onecatalogimport.*)
  sql/install.php                               служебные таблицы
  ```

---

## Маппинг сущностей → PrestaShop

| OneCatalog | PrestaShop 8.x | Примечание |
|---|---|---|
| product | `Product` (ObjectModel) | цена по умолчанию `0` (не синтезируем, §5.6) |
| **public_id** | **своя таблица `onecatalog_map`** (id_product ↔ public_id) | у товара нет произвольных custom-полей; идемпотентность по таблице, не по `reference` |
| article | нативный **`Product.reference`** | §5.2; пусто — не заполняем |
| options[] | **`Feature` + `FeatureValue`** (поиск по имени) | boolean→Да/Нет, numeric→текст |
| categories[] | `Category` (дерево, `id_parent`) | + `category_product`, мультиязычно |
| **brand** | нативный **`Manufacturer`** (find-by-name) | §3 «нативное прежде своего» |
| country | feature / выбор цели | по умолчанию выкл (§7) |
| collections[] | feature / категория (выбор цели) | по умолчанию выкл; поля → хук (§8) |
| tags[] | нативные **`Tag`** (по языку) | родные теги |
| images_urls/files | `Image` + `ImageManager` (обложка cover + галерея) | ручное скачивание, MIME по содержимому (§2.3) |
| sizes/weight | `Product.weight` (кг), `width/height/depth` (ед. магазина) | конверсия г→кг, мм→ед. (§5.6) |
| прочее | хук `onecatalogProductImported` (сайтовый слой) | §8 |

---

## Обязательные инварианты на PrestaShop

- **§5.1 идемпотентность**: поиск по `public_id` (таблица `onecatalog_map`) → update/insert.
  Справочники (категории/features/manufacturer/tags) — find-or-create по имени.
- **§5.1 служебное вне формы**: сигнатуры идемпотентности (медиа, цены/остатки), коды
  поставщиков — таблица `onecatalog_meta` (key-value по id_product), НЕ поля товара.
- **§5.2 артикул**: `reference` ← `article`; коллизию не перетираем.
- **§5.3 медиа**: трекинг качества (min/middle/max) + дедуп по контент-ключу
  (`onecatalog_media`), общие файлы не удаляем; галерея идемпотентна по имени файла.
- **§5.6**: цена не задаётся (PrestaShop требует поле — оставляем `0`); статус
  (`active`) и `reference` — только при создании; единицы конвертируем (Units).
- **§6 очередь**: браузерный AJAX-степпер (cron-таск опционально); UX v1.2 — лоадер,
  блокировка повторного запуска, сводка, отмена, persist.
- **§2.4 пикер (v1.2)**: `parentOrigin = window.location.origin` (клиент); origin
  виджета — настройкой; доверие по `event.source === iframe`; JSON-строка; × + Esc.
- **§8 хуки**: `Hook::exec('onecatalogProductImported', …)`,
  `onecatalogPriceStockUpdated` (сырые офферы, §13.7) — сайт расширяет без форка.
- **§7 настройки** (`Configuration`): токен/база Wiki, язык, шаг, статус новых, origin
  пикера, B2B (url_key/private_key/стратегия/приоритеты/промо/расписание). Справочные
  сущности по умолчанию **выкл**, нативное прежде своего.
- **§9 i18n**: система переводов PrestaShop (`$this->trans()`, домен модуля), EN исходный.
- **§13**: B2bApi + PriceStock-резолверы (порт), scan-and-diff (префетч
  public_id→id_product + сигнатуры одним запросом); цена→`Product.price`, скидка→
  **`SpecificPrice`**, остаток→**`StockAvailable`**; §13.5 ненайденные/отстойник, §13.6
  расписание (cron-таск/`AdminController` + планировщик PS).

---

## ❓ Вопросы (ответить ДО старта) — см. integration-answers.md
1. public_id: своя таблица (рекомендуется) vs кастом-поле? 🟢
2. Мультиязычность: писать контент на все языки магазина или один? 🟡
3. Единицы: конвертировать в единицы магазина (кг/см) — подтвердить дефолты. 🟡
4. Промо → `SpecificPrice` (с датами?) — ок? 🟡
5. Остаток → `StockAvailable` (мультимагазин/комбинации?). 🟡
6. Расписание §13.6: cron PrestaShop или внешний cron? 🟡
7. Версия: 8.x (и 1.7.8+); helper-формы + хуки (не Symfony-контроллеры). 🟢

## 🚫 Специфика PrestaShop
- Нет произвольных product-custom-полей → служебные данные своими таблицами.
- Features — пары имя/значение по языку (как у OpenCart-атрибутов): find-by-name.
- Цена/остаток — отдельные сущности (`SpecificPrice`/`StockAvailable`), не поля товара.
