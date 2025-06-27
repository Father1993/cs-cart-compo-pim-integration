{* Шаблон управления синхронизацией PIM *}
{capture name="mainbox"}

{if $running_sync}
<div class="alert alert-warning">
    <h4>{__("pim_sync.sync_in_progress")}</h4>
    <p>{__("pim_sync.sync_type")}: {__("pim_sync.sync_type_`$running_sync.sync_type`")}</p>
    <p>{__("pim_sync.started_at")}: {$running_sync.started_at|date_format:"%d.%m.%Y %H:%M"}</p>
</div>
{/if}

<div id="content_general">
    <div class="row-fluid">
        <div class="span6">
            <div class="well">
                <h4>{__("pim_sync.statistics")}</h4>
                <table class="table table-striped">
                    <tr>
                        <td>{__("pim_sync.total_categories")}:</td>
                        <td><strong>{$sync_stats.total_categories|default:0}</strong></td>
                    </tr>
                    <tr>
                        <td>{__("pim_sync.total_products")}:</td>
                        <td><strong>{$sync_stats.total_products|default:0}</strong></td>
                    </tr>
                    <tr>
                        <td>{__("pim_sync.pending_sync")}:</td>
                        <td><strong>{$sync_stats.pending_sync|default:0}</strong></td>
                    </tr>
                    <tr>
                        <td>{__("pim_sync.sync_errors")}:</td>
                        <td><strong class="{if $sync_stats.sync_errors > 0}text-error{/if}">{$sync_stats.sync_errors|default:0}</strong></td>
                    </tr>
                    <tr>
                        <td>{__("pim_sync.last_sync")}:</td>
                        <td>
                            {if $sync_stats.last_sync}
                                <strong>{$sync_stats.last_sync|date_format:"%d.%m.%Y %H:%M"}</strong>
                            {else}
                                <strong>{__("pim_sync.never")}</strong>
                            {/if}
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="span6">
            <div class="well">
                <h4>{__("actions")}</h4>
                
                <form action="{"pim_sync.test_connection"|fn_url}" method="post" class="form-horizontal">
                    <input type="hidden" name="result_ids" value="content_general,content_logs" />
                    <div class="control-group">
                        <button type="submit" class="btn btn-info">{__("pim_sync.test_connection")}</button>
                    </div>
                </form>
                
                <form action="{"pim_sync.sync_full"|fn_url}" method="post" class="form-horizontal">
                    <input type="hidden" name="result_ids" value="content_general,content_logs" />
                    <div class="control-group">
                        <button type="submit" class="btn btn-primary" {if $running_sync}disabled{/if}>{__("pim_sync.run_full_sync")}</button>
                        <p class="help-block">{__("pim_sync.full_sync_hint")}</p>
                    </div>
                </form>
                
                <form action="{"pim_sync.sync_delta"|fn_url}" method="post" class="form-horizontal form-inline">
                    <input type="hidden" name="result_ids" value="content_general,content_logs" />
                    <div class="control-group">
                        <label>{__("pim_sync.days_hint")}:</label>
                        <input type="number" name="days" value="1" min="1" max="30" class="input-mini" />
                        <button type="submit" class="btn" {if $running_sync}disabled{/if}>{__("pim_sync.run_delta_sync")}</button>
                        <p class="help-block">{__("pim_sync.delta_sync_hint")}</p>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="content_logs">
    <div class="table-responsive-wrapper">
        <table class="table table-striped table-middle">
            <thead>
                <tr>
                    <th width="5%">{__("pim_sync.log_id")}</th>
                    <th width="10%">{__("pim_sync.sync_type")}</th>
                    <th width="15%">{__("pim_sync.started_at")}</th>
                    <th width="15%">{__("pim_sync.completed_at")}</th>
                    <th width="10%">{__("pim_sync.status")}</th>
                    <th width="10%">{__("pim_sync.affected_categories")}</th>
                    <th width="10%">{__("pim_sync.affected_products")}</th>
                    <th width="10%">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$log_entries item="log"}
                <tr>
                    <td>{$log.log_id}</td>
                    <td>{__("pim_sync.sync_type_`$log.sync_type`")}</td>
                    <td>{$log.started_at|date_format:"%d.%m.%Y %H:%M"}</td>
                    <td>
                        {if $log.completed_at}
                            {$log.completed_at|date_format:"%d.%m.%Y %H:%M"}
                        {else}
                            -
                        {/if}
                    </td>
                    <td>
                        {if $log.status == 'running'}
                            <span class="label label-warning">{__("pim_sync.status_running")}</span>
                        {elseif $log.status == 'completed'}
                            <span class="label label-success">{__("pim_sync.status_completed")}</span>
                        {else}
                            <span class="label label-important">{__("pim_sync.status_failed")}</span>
                        {/if}
                    </td>
                    <td>{$log.affected_categories|default:0}</td>
                    <td>{$log.affected_products|default:0}</td>
                    <td class="nowrap">
                        {if $log.error_details}
                            <a href="{"pim_sync.log_details?log_id=`$log.log_id`"|fn_url}" class="btn btn-mini">{__("pim_sync.view_details")}</a>
                        {/if}
                    </td>
                </tr>
                {foreachelse}
                <tr class="no-items">
                    <td colspan="8"><p>{__("no_data")}</p></td>
                </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    
    {if $log_entries}
    <div class="buttons-container">
        <form action="{"pim_sync.clear_logs"|fn_url}" method="post" class="form-inline pull-right">
            <label>{__("keep_records")}:</label>
            <input type="number" name="days_to_keep" value="30" min="1" max="365" class="input-mini" />
            <button type="submit" class="btn">{__("pim_sync.clear_logs")}</button>
        </form>
    </div>
    {/if}
</div>

<div id="content_settings">
    <div class="form-horizontal">
        <div class="control-group">
            <label class="control-label">{__("pim_sync.api_url")}:</label>
            <div class="controls">
                <p class="form-control-static">{$pim_settings.api_url}</p>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label">{__("pim_sync.catalog_uid")}:</label>
            <div class="controls">
                <p class="form-control-static">{$pim_settings.catalog_uid}</p>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label">{__("pim_sync.sync_enabled")}:</label>
            <div class="controls">
                <p class="form-control-static">
                    {if $pim_settings.sync_enabled}
                        <span class="label label-success">{__("yes")}</span>
                    {else}
                        <span class="label">{__("no")}</span>
                    {/if}
                </p>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label">{__("pim_sync.sync_interval")}:</label>
            <div class="controls">
                <p class="form-control-static">{$pim_settings.sync_interval} {__("minutes")}</p>
            </div>
        </div>
    </div>
    
    <div class="buttons-container">
        <a href="{"addons.update&addon=pim_sync"|fn_url}" class="btn btn-primary">{__("edit")}</a>
    </div>
</div>

{/capture}

{include file="common/tabsbox.tpl" content=$smarty.capture.mainbox active_tab=$smarty.request.selected_section}

{capture name="title"}{__("pim_sync.manage")}{/capture}

{capture name="buttons"}
    {capture name="tools_list"}
        <li>{btn type="list" text=__("settings") href="addons.update&addon=pim_sync"}</li>
    {/capture}
    {dropdown content=$smarty.capture.tools_list}
{/capture} 