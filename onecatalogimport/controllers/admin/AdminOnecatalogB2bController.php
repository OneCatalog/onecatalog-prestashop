<?php
/**
 * OneCatalog — страница «Цены и остатки» (B2B-синк, PrestaShop 8.x, §13).
 * Настройки B2B + браузерный степпер (scan-and-diff).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminOnecatalogB2bController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addJS(_MODULE_DIR_ . 'onecatalogimport/views/js/b2b-sync.js');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitB2b') && $this->access('edit')) {
            Configuration::updateValue('ONECATALOG_B2B_BASE', rtrim(trim((string) Tools::getValue('ONECATALOG_B2B_BASE')), '/'));
            Configuration::updateValue('ONECATALOG_B2B_URL_KEY', trim((string) Tools::getValue('ONECATALOG_B2B_URL_KEY')));
            Configuration::updateValue('ONECATALOG_B2B_PRIVATE_KEY', trim((string) Tools::getValue('ONECATALOG_B2B_PRIVATE_KEY')));
            $strat = (string) Tools::getValue('ONECATALOG_B2B_STRATEGY');
            Configuration::updateValue('ONECATALOG_B2B_STRATEGY', in_array($strat, ['min', 'priority', 'supplier'], true) ? $strat : 'min');
            Configuration::updateValue('ONECATALOG_B2B_REGION_PRIORITY', trim((string) Tools::getValue('ONECATALOG_B2B_REGION_PRIORITY')));
            Configuration::updateValue('ONECATALOG_B2B_SUPPLIER_PRIORITY', trim((string) Tools::getValue('ONECATALOG_B2B_SUPPLIER_PRIORITY')));
            Configuration::updateValue('ONECATALOG_B2B_SUPPLIER_FIXED', (int) Tools::getValue('ONECATALOG_B2B_SUPPLIER_FIXED'));
            Configuration::updateValue('ONECATALOG_B2B_PROMO_AS_SALE', (int) Tools::getValue('ONECATALOG_B2B_PROMO_AS_SALE'));
            Configuration::updateValue('ONECATALOG_B2B_MANAGE_STOCK', (int) Tools::getValue('ONECATALOG_B2B_MANAGE_STOCK'));
            $this->confirmations[] = $this->l('Settings saved.');
        }
        parent::postProcess();
    }

    public function initContent()
    {
        $this->content = $this->renderSettings() . $this->renderRun();
        parent::initContent();
        $this->context->smarty->assign('content', $this->content);
    }

    private function renderSettings()
    {
        $sw = function ($name, $label) {
            return [
                'type' => 'switch', 'label' => $label, 'name' => $name, 'is_bool' => true,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Enabled')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('Disabled')],
                ],
            ];
        };
        $fields_form = ['form' => [
            'legend' => ['title' => $this->l('B2B — prices & stock'), 'icon' => 'icon-cogs'],
            'input' => [
                ['type' => 'text', 'label' => $this->l('B2B API base URL'), 'name' => 'ONECATALOG_B2B_BASE', 'size' => 60],
                ['type' => 'text', 'label' => $this->l('Retailer key (url_key)'), 'name' => 'ONECATALOG_B2B_URL_KEY', 'size' => 60],
                ['type' => 'text', 'label' => $this->l('Private key'), 'name' => 'ONECATALOG_B2B_PRIVATE_KEY', 'size' => 60],
                ['type' => 'select', 'label' => $this->l('Price strategy'), 'name' => 'ONECATALOG_B2B_STRATEGY', 'options' => ['query' => [
                    ['id' => 'min', 'name' => $this->l('Minimum price')],
                    ['id' => 'priority', 'name' => $this->l('By supplier priority')],
                    ['id' => 'supplier', 'name' => $this->l('Fixed supplier')],
                ], 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'label' => $this->l('Region priority (ids, comma)'), 'name' => 'ONECATALOG_B2B_REGION_PRIORITY', 'size' => 30],
                ['type' => 'text', 'label' => $this->l('Supplier priority (ids, comma)'), 'name' => 'ONECATALOG_B2B_SUPPLIER_PRIORITY', 'size' => 30],
                ['type' => 'text', 'label' => $this->l('Fixed supplier id'), 'name' => 'ONECATALOG_B2B_SUPPLIER_FIXED', 'size' => 8],
                $sw('ONECATALOG_B2B_PROMO_AS_SALE', $this->l('Promo as sale (SpecificPrice)')),
                $sw('ONECATALOG_B2B_MANAGE_STOCK', $this->l('Manage stock (StockAvailable)')),
            ],
            'submit' => ['title' => $this->l('Save')],
        ]];

        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitB2b';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminOnecatalogB2b', false);
        $helper->token = $this->token;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach (['ONECATALOG_B2B_BASE', 'ONECATALOG_B2B_URL_KEY', 'ONECATALOG_B2B_PRIVATE_KEY', 'ONECATALOG_B2B_STRATEGY', 'ONECATALOG_B2B_REGION_PRIORITY', 'ONECATALOG_B2B_SUPPLIER_PRIORITY', 'ONECATALOG_B2B_SUPPLIER_FIXED', 'ONECATALOG_B2B_PROMO_AS_SALE', 'ONECATALOG_B2B_MANAGE_STOCK'] as $k) {
            $helper->fields_value[$k] = Configuration::get($k);
        }
        return $helper->generateForm([$fields_form]);
    }

    private function renderRun()
    {
        $cfg = [
            'syncUrl' => $this->context->link->getAdminLink('AdminOnecatalogB2b'),
            'limit' => 200,
            'messages' => [
                'running' => $this->l('Syncing…'),
                'done' => $this->l('Done:'),
                'error' => $this->l('Error'),
                'changed' => $this->l('Changed'),
                'unchanged' => $this->l('Unchanged'),
                'missing' => $this->l('Not in catalog'),
            ],
        ];
        $configured = ((string) Configuration::get('ONECATALOG_B2B_URL_KEY') !== '' && (string) Configuration::get('ONECATALOG_B2B_PRIVATE_KEY') !== '');
        $this->context->smarty->assign([
            'oc_b2b_cfg_json' => json_encode($cfg),
            'oc_b2b_configured' => $configured,
            'oc_b2b_l' => [
                'run_title' => $this->l('Run synchronization'),
                'run' => $this->l('Synchronize now'),
                'hint' => $this->l('Runs page-by-page in the browser (no cron needed). Only changed products are written (scan-and-diff).'),
                'not_configured' => $this->l('B2B keys are not set — fill url_key and private_key, then save.'),
            ],
        ]);
        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'onecatalogimport/views/templates/admin/b2b.tpl');
    }

    public function ajaxProcessSyncPage()
    {
        $json = [];
        if (!$this->access('edit')) {
            $json['error'] = $this->l('Access denied');
        } else {
            $start = (int) Tools::getValue('start', 0);
            $limit = max(1, (int) Tools::getValue('limit', 200));
            require_once _PS_MODULE_DIR_ . 'onecatalogimport/classes/B2bSync.php';
            $json = (new OneCatalogB2bSync())->processPage($start, $limit);
        }
        header('Content-Type: application/json');
        die(json_encode($json));
    }
}
