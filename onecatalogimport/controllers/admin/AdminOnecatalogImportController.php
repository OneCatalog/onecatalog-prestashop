<?php
/**
 * OneCatalog Import — операционная страница «Импорт» (PrestaShop 8.x).
 * Пикер + поле ввода + AJAX-степпер (§2.4 v1.2, §6 v1.2).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminOnecatalogImportController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $base = _MODULE_DIR_ . 'onecatalogimport/views/js/';
        $this->addJS($base . 'picker-loader.js');
        $this->addJS($base . 'admin-import.js');
    }

    public function initContent()
    {
        $this->content = $this->renderImportPage();
        parent::initContent();
        $this->context->smarty->assign('content', $this->content);
    }

    private function renderImportPage()
    {
        $cfg = [
            'ajaxUrl' => $this->context->link->getAdminLink('AdminOnecatalogImport'),
            'pickerBase' => (string) (Configuration::get('ONECATALOG_PICKER_BASE') ?: 'https://tools.onecatalog.net'),
            'token' => (string) Configuration::get('ONECATALOG_API_TOKEN'),
            'step' => max(10, (int) Configuration::get('ONECATALOG_STEP')),
            'messages' => [
                'empty' => $this->l('The identifier list is empty'),
                'importing' => $this->l('Importing…'),
                'done' => $this->l('Done:'),
                'error' => $this->l('Error'),
                'cancelled' => $this->l('Cancelled:'),
                'created' => $this->l('Created'),
                'updated' => $this->l('Updated'),
                'errors' => $this->l('Errors'),
                'last' => $this->l('Last result'),
            ],
        ];

        $this->context->smarty->assign([
            'oc_cfg_json' => json_encode($cfg),
            'oc_configured' => (string) Configuration::get('ONECATALOG_API_TOKEN') !== '',
            'oc_l' => [
                'title' => $this->l('OneCatalog — import products'),
                'pick' => $this->l('Select products (OneCatalog)'),
                'or_paste' => $this->l('or paste a list of identifiers (public_id), separated by comma/space/newline:'),
                'import' => $this->l('Import'),
                'cancel' => $this->l('Cancel'),
                'no_token' => $this->l('API token is not set. Open module settings and enter the token.'),
            ],
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'onecatalogimport/views/templates/admin/import.tpl');
    }

    /** AJAX: импорт порции public_id → JSON {results, log}. */
    public function ajaxProcessImportBatch()
    {
        $json = [];
        if (!$this->access('edit')) {
            $json['error'] = $this->l('Access denied');
        } elseif ((string) Configuration::get('ONECATALOG_API_TOKEN') === '') {
            $json['error'] = $this->l('API token is not set');
        } else {
            $ids = Tools::getValue('ids');
            $ids = is_array($ids) ? array_values(array_filter(array_map('trim', $ids))) : [];

            require_once _PS_MODULE_DIR_ . 'onecatalogimport/classes/Importer.php';
            $importer = new OneCatalogImporter();
            $results = [];
            foreach ($ids as $publicId) {
                try {
                    $r = $importer->importByPublicId($publicId);
                } catch (\Throwable $e) {
                    $r = ['status' => 'error', 'public_id' => $publicId, 'message' => $e->getMessage()];
                }
                $results[] = $r;
                $this->logResult($r);
            }
            $json['results'] = $results;
            $json['log'] = $this->recentLog();
        }
        header('Content-Type: application/json');
        die(json_encode($json));
    }

    private function logResult(array $r)
    {
        Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'onecatalog_log` (public_id, status, message, date_add) VALUES ('
            . "'" . pSQL((string) ($r['public_id'] ?? '')) . "', '" . pSQL((string) ($r['status'] ?? '')) . "', '"
            . pSQL((string) ($r['message'] ?? '')) . "', NOW())");
    }

    private function recentLog()
    {
        $rows = Db::getInstance()->executeS('SELECT public_id, status, message FROM `' . _DB_PREFIX_ . 'onecatalog_log` ORDER BY id_log DESC LIMIT 50');
        return array_reverse(is_array($rows) ? $rows : []);
    }
}
