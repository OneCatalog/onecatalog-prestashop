<?php
/**
 * OneCatalog Import — модуль PrestaShop 8.x (совм. 1.7.8+).
 *
 * 0.1.0: каркас — установка/удаление, служебные таблицы, страница настроек.
 * Импорт каталога (§1–§12) и B2B (§13) — следующие инкременты. Стандарт v1.2.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class OneCatalogImport extends Module
{
    public function __construct()
    {
        $this->name = 'onecatalogimport';
        $this->tab = 'administration';
        $this->version = '0.4.0';
        $this->author = 'OneCatalog';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('OneCatalog Import', [], 'Modules.Onecatalogimport.Admin');
        $this->description = $this->trans('Import catalog from OneCatalog (Wiki API) and sync B2B prices/stock.', [], 'Modules.Onecatalogimport.Admin');
        $this->confirmUninstall = $this->trans('Uninstall OneCatalog Import? Service tables (public_id map) are kept to avoid duplicates.', [], 'Modules.Onecatalogimport.Admin');
    }

    public function install()
    {
        return parent::install()
            && $this->installSql()
            && $this->initConfig()
            && $this->installTabs();
    }

    public function uninstall()
    {
        $this->uninstallTabs();
        // Служебные таблицы и Configuration НЕ удаляем — иначе при переустановке
        // теряется идемпотентность (public_id ↔ id_product) и товары задвоятся.
        return parent::uninstall();
    }

    /** Вкладки админ-меню: родитель «OneCatalog» + дети (страницы). */
    private function installTabs()
    {
        return $this->addTab('AdminOnecatalogParent', 'OneCatalog', 'AdminCatalog')
            && $this->addTab('AdminOnecatalogImport', 'Import', 'AdminOnecatalogParent');
    }

    private function addTab($className, $name, $parentClassName)
    {
        if (Tab::getIdFromClassName($className)) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = $className;
        $tab->module = $this->name;
        $tab->active = 1;
        $tab->id_parent = $parentClassName ? (int) Tab::getIdFromClassName($parentClassName) : 0;
        foreach (Language::getLanguages(false) as $l) {
            $tab->name[(int) $l['id_lang']] = $name;
        }
        return (bool) $tab->add();
    }

    private function uninstallTabs()
    {
        foreach (['AdminOnecatalogImport', 'AdminOnecatalogParent'] as $cn) {
            $id = (int) Tab::getIdFromClassName($cn);
            if ($id) {
                $tab = new Tab($id);
                $tab->delete();
            }
        }
        return true;
    }

    /** Служебные таблицы (§5.1 — служебное вне формы товара). */
    private function installSql()
    {
        $p = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;
        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}onecatalog_map` (
            `id_product` INT UNSIGNED NOT NULL,
            `public_id` VARCHAR(64) NOT NULL,
            PRIMARY KEY (`id_product`),
            UNIQUE KEY `uq_oc_public_id` (`public_id`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}onecatalog_meta` (
            `id_meta` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT UNSIGNED NOT NULL,
            `meta_key` VARCHAR(50) NOT NULL,
            `value` LONGTEXT NULL,
            PRIMARY KEY (`id_meta`),
            UNIQUE KEY `uq_oc_meta` (`id_product`, `meta_key`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}onecatalog_media` (
            `id_media` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `content_key` VARCHAR(64) NOT NULL,
            `size` VARCHAR(8) NOT NULL DEFAULT 'min',
            `file` VARCHAR(255) NOT NULL,
            `shared` TINYINT(1) NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_media`),
            UNIQUE KEY `uq_oc_media_key` (`content_key`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}onecatalog_b2b_staging` (
            `id_staging` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `supplier_id` INT NULL,
            `code` VARCHAR(128) NOT NULL,
            `name` VARCHAR(512) NULL,
            `data` LONGTEXT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'new',
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NULL,
            PRIMARY KEY (`id_staging`),
            UNIQUE KEY `uq_oc_staging` (`supplier_id`, `code`),
            KEY `ix_oc_staging_status` (`status`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}onecatalog_log` (
            `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_id` VARCHAR(64) NOT NULL,
            `status` VARCHAR(16) NOT NULL,
            `message` TEXT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_log`),
            KEY `ix_oc_log_date` (`date_add`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

        foreach ($sql as $q) {
            if (!Db::getInstance()->execute($q)) {
                return false;
            }
        }
        return true;
    }

    private function initConfig()
    {
        Configuration::updateValue('ONECATALOG_API_BASE', 'https://api.onecatalog.net/wiki/v1');
        Configuration::updateValue('ONECATALOG_API_TOKEN', '');
        Configuration::updateValue('ONECATALOG_LANG', 'en');
        Configuration::updateValue('ONECATALOG_STEP', 10);
        Configuration::updateValue('ONECATALOG_NEW_ACTIVE', 1);
        Configuration::updateValue('ONECATALOG_PICKER_BASE', 'https://tools.onecatalog.net');
        return true;
    }

    /** Страница настроек модуля (Модули → OneCatalog Import → Настроить). */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitOnecatalog')) {
            Configuration::updateValue('ONECATALOG_API_BASE', rtrim(trim((string) Tools::getValue('ONECATALOG_API_BASE')), '/'));
            Configuration::updateValue('ONECATALOG_API_TOKEN', trim((string) Tools::getValue('ONECATALOG_API_TOKEN')));
            Configuration::updateValue('ONECATALOG_LANG', trim((string) Tools::getValue('ONECATALOG_LANG')) ?: 'en');
            Configuration::updateValue('ONECATALOG_STEP', max(10, (int) Tools::getValue('ONECATALOG_STEP')));
            Configuration::updateValue('ONECATALOG_NEW_ACTIVE', (int) Tools::getValue('ONECATALOG_NEW_ACTIVE'));
            Configuration::updateValue('ONECATALOG_PICKER_BASE', rtrim(trim((string) Tools::getValue('ONECATALOG_PICKER_BASE')), '/'));
            $output .= $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Onecatalogimport.Admin'));
        }

        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->trans('OneCatalog settings', [], 'Modules.Onecatalogimport.Admin'), 'icon' => 'icon-cogs'],
                'input' => [
                    ['type' => 'text', 'label' => $this->trans('Wiki API base URL', [], 'Modules.Onecatalogimport.Admin'), 'name' => 'ONECATALOG_API_BASE', 'size' => 60],
                    ['type' => 'text', 'label' => $this->trans('API token', [], 'Modules.Onecatalogimport.Admin'), 'name' => 'ONECATALOG_API_TOKEN', 'size' => 60, 'desc' => $this->trans('X-API-Key for Wiki API and the picker widget.', [], 'Modules.Onecatalogimport.Admin')],
                    ['type' => 'text', 'label' => $this->trans('Product language', [], 'Modules.Onecatalogimport.Admin'), 'name' => 'ONECATALOG_LANG', 'size' => 8, 'desc' => $this->trans('Language code from the API (e.g. en, ru).', [], 'Modules.Onecatalogimport.Admin')],
                    ['type' => 'text', 'label' => $this->trans('Import step (batch size)', [], 'Modules.Onecatalogimport.Admin'), 'name' => 'ONECATALOG_STEP', 'size' => 6, 'desc' => $this->trans('Minimum 10.', [], 'Modules.Onecatalogimport.Admin')],
                    [
                        'type' => 'switch', 'label' => $this->trans('New product status (active)', [], 'Modules.Onecatalogimport.Admin'), 'name' => 'ONECATALOG_NEW_ACTIVE',
                        'is_bool' => true, 'desc' => $this->trans('Set on creation only; not overwritten on re-import.', [], 'Modules.Onecatalogimport.Admin'),
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->trans('Enabled', [], 'Modules.Onecatalogimport.Admin')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->trans('Disabled', [], 'Modules.Onecatalogimport.Admin')],
                        ],
                    ],
                    ['type' => 'text', 'label' => $this->trans('Picker base URL', [], 'Modules.Onecatalogimport.Admin'), 'name' => 'ONECATALOG_PICKER_BASE', 'size' => 60, 'desc' => $this->trans('Origin of the product picker widget.', [], 'Modules.Onecatalogimport.Admin')],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Modules.Onecatalogimport.Admin')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitOnecatalog';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            'ONECATALOG_API_BASE' => Configuration::get('ONECATALOG_API_BASE'),
            'ONECATALOG_API_TOKEN' => Configuration::get('ONECATALOG_API_TOKEN'),
            'ONECATALOG_LANG' => Configuration::get('ONECATALOG_LANG'),
            'ONECATALOG_STEP' => Configuration::get('ONECATALOG_STEP'),
            'ONECATALOG_NEW_ACTIVE' => Configuration::get('ONECATALOG_NEW_ACTIVE'),
            'ONECATALOG_PICKER_BASE' => Configuration::get('ONECATALOG_PICKER_BASE'),
        ];

        return $helper->generateForm([$fields_form]);
    }
}
