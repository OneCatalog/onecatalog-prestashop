{*
 * OneCatalog — панель запуска B2B-синка (PrestaShop admin).
 *}
<style>
  #oc-b2b-app .oc-spinner { display:none; width:14px; height:14px; margin-right:7px; border:2px solid #cfd8e3; border-top-color:#2067b0; border-radius:50%; vertical-align:middle; animation:oc-spin .7s linear infinite; }
  @keyframes oc-spin { to { transform: rotate(360deg); } }
  #oc-b2b-app .oc-bar-wrap { height:6px; background:#eee; border-radius:3px; margin-top:8px; overflow:hidden; max-width:760px; }
  #oc-b2b-app .oc-bar { height:100%; width:0; background:#2067b0; transition:width .2s ease; }
  #oc-b2b-app .oc-bar.oc-ok { background:#3aa76d; }
  #oc-b2b-app .oc-bar.oc-err { background:#d9534f; }
</style>

<div class="panel" id="oc-b2b-app">
  <h3><i class="icon-refresh"></i> {$oc_b2b_l.run_title|escape:'html':'UTF-8'}</h3>
  {if !$oc_b2b_configured}
    <div class="alert alert-warning">{$oc_b2b_l.not_configured|escape:'html':'UTF-8'}</div>
  {/if}
  <button type="button" class="btn btn-primary" id="oc-b2b-run"><i class="icon-refresh"></i> {$oc_b2b_l.run|escape:'html':'UTF-8'}</button>
  <div id="oc-b2b-status" style="margin:12px 0;display:none">
    <span class="oc-spinner" id="oc-b2b-spinner"></span>
    <span id="oc-b2b-progress" style="font-weight:bold"></span>
    <div class="oc-bar-wrap"><div class="oc-bar" id="oc-b2b-bar"></div></div>
    <div id="oc-b2b-summary" style="margin-top:8px;color:#333"></div>
  </div>
  <p class="text-muted" style="margin-top:10px">{$oc_b2b_l.hint|escape:'html':'UTF-8'}</p>
</div>

<script>window.OneCatalogB2bCfg = {$oc_b2b_cfg_json nofilter};</script>
