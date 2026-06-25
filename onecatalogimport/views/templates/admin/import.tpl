{*
 * OneCatalog Import — страница импорта (PrestaShop admin, Smarty).
 *}
<style>
  #oc-import-app .oc-spinner { display:none; width:14px; height:14px; margin-right:7px; border:2px solid #cfd8e3; border-top-color:#2067b0; border-radius:50%; vertical-align:middle; animation:oc-spin .7s linear infinite; }
  @keyframes oc-spin { to { transform: rotate(360deg); } }
  #oc-import-app .oc-bar-wrap { height:6px; background:#eee; border-radius:3px; margin-top:8px; overflow:hidden; max-width:760px; }
  #oc-import-app .oc-bar { height:100%; width:0; background:#2067b0; transition:width .2s ease; }
  #oc-import-app .oc-bar.oc-ok { background:#3aa76d; }
  #oc-import-app .oc-bar.oc-err { background:#d9534f; }
  #oc-import-app button[disabled] { opacity:.55; cursor:default; }
  #oc-import-app textarea[disabled] { background:#f3f3f3; }
  #oc-log { max-height:340px; overflow:auto; background:#f7f7f7; border:1px solid #ddd; padding:8px; white-space:pre; max-width:760px; }
</style>

<div class="panel" id="oc-import-app">
  <h3><i class="icon-download"></i> {$oc_l.title|escape:'html':'UTF-8'}</h3>

  {if !$oc_configured}
    <div class="alert alert-warning">{$oc_l.no_token|escape:'html':'UTF-8'}</div>
  {/if}

  <p>
    <button type="button" class="btn btn-primary" id="oc-open-picker"><i class="icon-th"></i> {$oc_l.pick|escape:'html':'UTF-8'}</button>
  </p>
  <div class="form-group" style="max-width:760px">
    <label for="oc-ids">{$oc_l.or_paste|escape:'html':'UTF-8'}</label>
    <textarea id="oc-ids" rows="4" class="form-control" placeholder="OC.IND.1, OC.IND.2, …"></textarea>
  </div>
  <p>
    <button type="button" class="btn btn-default" id="oc-import-btn"><i class="icon-play"></i> {$oc_l.import|escape:'html':'UTF-8'}</button>
    <button type="button" class="btn btn-default" id="oc-cancel-btn" style="display:none"><i class="icon-stop"></i> {$oc_l.cancel|escape:'html':'UTF-8'}</button>
  </p>

  <div id="oc-status" style="margin:12px 0;display:none">
    <span class="oc-spinner" id="oc-spinner"></span>
    <span id="oc-progress" style="font-weight:bold"></span>
    <div class="oc-bar-wrap"><div class="oc-bar" id="oc-bar"></div></div>
    <div id="oc-summary" style="margin-top:8px;color:#333"></div>
  </div>
  <pre id="oc-log"></pre>
</div>

<script>window.OneCatalogCfg = {$oc_cfg_json nofilter};</script>
