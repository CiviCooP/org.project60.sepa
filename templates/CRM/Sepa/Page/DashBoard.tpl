<div class="action-link">
  <div class="crm-submit-buttons">
    <a class="button" href="{crmURL p='civicrm/sepa/createnext'}"><span>{ts}Generate Recurring Payments{/ts}</span></a>
  </div>
</div>

{foreach from=$groups key=creditor_id item=creditor}
<div class='crm-accordion-wrapper'>
  <div class='crm-accordion-header'>{ts}Creditor{/ts} {$creditor_id}</div>
  <div class="crm-accordion-body">

    <div class="action-link">
      <div class="crm-submit-buttons">
        <a class="button" href="{crmURL p='civicrm/sepa/batchingaction' q="_action=batch_for_submit&creditor_id=$creditor_id"}"><span>{ts}Prepare Submit{/ts}</span></a>
      </div>
    </div>

    <div class='crm-accordion-wrapper'>
      <div class='crm-accordion-header'>{ts}Pending{/ts}</div>
      <div class='crm-accordion-body'>

        <table>
          <tr>
            <th>Type</th>
            <th>Receive Date</th>
            <th># Transactions</th>
            <th>Total</th>
          </tr>
{foreach from=$creditor.Pending item=group}
          <tr class="contribution_group status_Pending" data-creditor="{$creditor_id}" data-date="{$group.collection_date}" data-instrument="{$group.payment_instrument_id}" data-type="{$group.type}">
            <td>{$group.type}</td>
            <td>{$group.collection_date}</td>
            <td>{$group.nb_contrib}</td>
            <td>{$group.total} &euro;</td>
          </tr>
{/foreach}
        </table>

      </div> <!-- crm-accordion-body -->
    </div> <!--crm-accordion-wrapper (Pending) -->

    <div class='crm-accordion-wrapper'>
      <div class='crm-accordion-header'>{ts}Batched{/ts}</div>
      <div class='crm-accordion-body'>

<table>
<tr>
<th>Reference</th>
<th>status</th>
<th>type</th>
<th>created</th>
<th>collection</th>
<th>file</th>
<th>transactions</th>
<th>total</th>
<th></th>
</tr>
{foreach from=$creditor.Batched item=group}
<tr class="contribution_group status_{$group.status}" data-id="{$group.id}" data-type="{$group.type}">
<td title="id {$group.id}">{$group.reference}</td>
<td>{$group.status_label}</td>
<td>{$group.type}</td>
<td>{$group.created_date}</td>
<td>{$group.collection_date}</td>
{assign var='file_id' value=$group.file_id}
<td class="file_{$group.file_id}">{$group.file_href}</td>
<td>{$group.nb_contrib}</td>
<td>{$group.total} &euro;</td>
<td>
{if $group.status == 'Batched'}
  <a class="button" href="{crmURL p='civicrm/sepa/batchingaction' q="_action=cancel_submit_file&file_id=$file_id"}">{ts}Cancel File{/ts}</a>
  <a class="button" href="{crmURL p='civicrm/sepa/batchingaction' q="_action=confirm_submit_file&file_id=$file_id"}">{ts}File Submitted{/ts}</a>
{elseif $group.status == 'In Progress'}
  {assign var='group_id' value=$group.id}
  <a class="button" href="{crmURL p='civicrm/sepa/batchingaction' q="_action=abort_group&txgroup_id=$group_id"}">{ts}Group Aborted{/ts}</a>
  <a class="button" href="{crmURL p='civicrm/sepa/batchingaction' q="_action=complete_group&txgroup_id=$group_id"}">{ts}Group Completed{/ts}</a>
  <a class="button" href="{crmURL p='civicrm/sepa/batchingaction' q="_action=abort_file&file_id=$file_id"}">{ts}File Aborted{/ts}</a>
  <a class="button" href="{crmURL p='civicrm/sepa/batchingaction' q="_action=complete_file&file_id=$file_id"}">{ts}File Completed{/ts}</a>
{/if}
</td>
</tr>
{/foreach}
</table>

      </div> <!-- crm-accordion-body -->
    </div> <!--crm-accordion-wrapper (Batched) -->

  </div> <!-- crm-accordion-body -->
</div> <!--crm-accordion-wrapper (Creditor) -->
{/foreach}

<script type="text/javascript">
  {literal}
    cj(function() {
      cj().crmAccordions();
    });
  {/literal}
</script>

{literal}
<script type="text/template" id="detail">
<tr class="detail">
<td></td>
<td colspan="7">
<table>
  <tr>
    <th>mandate</th>
    <th>amount</th>
    <th>contact</th>
    <th>contrib</th>
    <th>receive</th>
<% if(type != 'OOFF') { %>
    <th>recur</th>
    <th>next</th>
<% } %>
    <th>instrument</th>
  </tr>
<% _.each(values,function(item){ %>
  <tr>
    <td><a href="<%= CRM.url("civicrm/sepa/pdf",{"ref":item.reference}) %>"><%= item.reference %></a></td>
    <td><%= item.total_amount %></td>
    <td><a href="<%= CRM.url("civicrm/contact/view",{"cid":item.contact_id}) %>"><%= item.contact_id %></a></td>
    <td><a href="<%= CRM.url("civicrm/contact/view/contribution",{"id":item.contribution_id,"cid":item.contact_id,"action":"view"}) %>"><%= item.contribution_id %></a></td>
    <td><%= item.receive_date.substring(0,10) %></td>
<% if(type != 'OOFF') { %>
    <td><a href="<%= CRM.url("civicrm/contact/view/contributionrecur",{"id":item.recur_id,"cid":item.contact_id,"a  ction":"view"}) %>"><%= item.recur_id %></a></td>
    <td><%= item.next_sched_contribution_date ? item.next_sched_contribution_date.substring(0,10) : '' %></td>
<% } %>
    <td><%= item.payment_instrument_id %></td>
  </tr>
<%  }); %>
</table>
</td>
</tr>
</script>
<script>
cj(function($){
  $(".contribution_group").click(function(){
    var $tr=$(this);
    if ($tr.next().hasClass("detail")) {
     $tr.next().remove();
     return;
    }
    function show_contribs(data) {
      _.extend(data,$tr.data());
      $tr.after(_.template($("#detail").html(),data));
    }
    if ($tr.hasClass('status_Pending')) {
      CRM.api(
        'SepaContributionPending',
        'get',
        {
          'contribution_payment_instrument_id': $tr.data('instrument'),
          'receive_date': {'BETWEEN': [$tr.data('date'), $tr.data('date') + ' 23:59:59']},
          'return': ['receive_date', 'total_amount'],
          'mandate': {
            'creditor_id': $tr.data('creditor'),
            'return': ['reference'],
          },
          'recur': {
            'return': ['next_sched_contribution_date'],
          }
        },
        {'success':show_contribs}
      );
    } else {
      CRM.api("SepaContributionGroup","getdetail",{"id":$tr.data("id")},{"success":show_contribs});
    }
  });
});
</script>
<style>
  .contribution_group {cursor:pointer}
  .contribution_group:hover {text-decoration:underline;}
</style>
{/literal}

