<?php
/**
 * OneCatalog — журнал импорта (PrestaShop 8.x). Последние результаты из onecatalog_log.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminOnecatalogLogController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('oc_clear_log') && $this->access('edit')) {
            Db::getInstance()->execute('TRUNCATE `' . _DB_PREFIX_ . 'onecatalog_log`');
            $this->confirmations[] = $this->l('Import log cleared.');
        }
        parent::postProcess();
    }

    public function initContent()
    {
        $rows = Db::getInstance()->executeS('SELECT public_id, status, message, date_add FROM `' . _DB_PREFIX_ . 'onecatalog_log` ORDER BY id_log DESC LIMIT 200');
        $rows = is_array($rows) ? $rows : [];

        $clearUrl = $this->context->link->getAdminLink('AdminOnecatalogLog') . '&oc_clear_log=1';

        $html = '<div class="panel"><h3><i class="icon-list"></i> ' . $this->l('OneCatalog — import log') . '</h3>';
        $html .= '<p><a class="btn btn-default" href="' . htmlspecialchars($clearUrl) . '">' . $this->l('Clear log') . '</a></p>';
        $html .= '<p class="text-muted">' . $this->l('Last 200 catalog import results (created / updated / error).') . '</p>';
        $html .= '<table class="table"><thead><tr><th>' . $this->l('Time') . '</th><th>public_id</th><th>'
            . $this->l('Status') . '</th><th>' . $this->l('Message') . '</th></tr></thead><tbody>';
        if ($rows) {
            foreach ($rows as $r) {
                $html .= '<tr><td>' . htmlspecialchars((string) $r['date_add']) . '</td><td>'
                    . htmlspecialchars((string) $r['public_id']) . '</td><td>'
                    . htmlspecialchars((string) $r['status']) . '</td><td>'
                    . htmlspecialchars((string) $r['message']) . '</td></tr>';
            }
        } else {
            $html .= '<tr><td colspan="4" class="text-center">' . $this->l('No entries yet.') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        $this->content = $html;
        parent::initContent();
        $this->context->smarty->assign('content', $this->content);
    }
}
