# История изменений — OneCatalog Import (PrestaShop)

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/),
нумерация версий — по [семантическому версионированию](https://semver.org/lang/ru/).

Соответствие стандарту интеграции: **v1.2** (см.
[onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard)).
Целевая платформа: **PrestaShop 8.x** (совм. 1.7.8+).

## [Не выпущено] — бэклог

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
