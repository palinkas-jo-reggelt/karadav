{{Trash} assign="title"}
{include file="_header.tpl" current="trash"}

{if $form_error}
	<p class="error">{$form_error}</p>
{/if}

{if $notice}
	<p><strong>{$notice}</strong></p>
{/if}

<h2>{{Trash}}</h2>

{if $open !== ''}
	<p>
		<a href="{$www_url}trash.php">&larr; {{Back to the trash}}</a>
		&middot;
		{foreach from=$crumbs item="c"}/ <a href="{$www_url}{$c.url}">{$c.name}</a> {/foreach}
	</p>
	<p class="help">{{This folder is in the trash. Go back to the trash and restore the whole folder to get everything in it back.}}</p>

	{if empty($items)}
		<p>{{This folder is empty.}}</p>
	{else}
	<table>
		<thead><tr><td>{{Name}}</td><td>{{Size}}</td><td>{{Modified}}</td></tr></thead>
		<tbody>
		{foreach from=$items item="i"}
			<tr>
				<td>{if $i.is_dir}&#128193; <a href="{$www_url}{$i.url}">{$i.name}</a>{else}&#128196; {$i.name}{/if}</td>
				<td>{$i.size|hsize}</td>
				<td>{$i.date}</td>
			</tr>
		{/foreach}
		</tbody>
	</table>
	{/if}
{else}

{if empty($items)}
	<p>{{The trash is empty.}}</p>
{else}
	<p class="help">{{Deleted files and folders stay here until you delete them permanently. Restoring a folder brings back everything in it, to the place it was deleted from.}} {$count_items} {{items}}, {$total_size|hsize}.</p>

	{if $has_shared}
	<p class="help">{{Items marked "shared by" were deleted from a folder that someone shared with you. You can restore them, but only their owner can delete them permanently.}}</p>
	{/if}

	<form method="post" action="">
		{form_csrf}
		<p>
			<input type="submit" name="restore" value="{{Restore selected}}" class="btn sm" />
			<input type="submit" name="purge" value="{{Delete selected permanently}}" class="btn sm" onclick="return confirm('Delete the selected items permanently? This cannot be undone.')" />
		</p>
		<table>
			<thead>
				<tr>
					<td><input type="checkbox" id="all_items" title="{{Select all}}" /></td>
					<td>{{Name}}</td><td>{{Deleted from}}</td><td>{{Deleted}}</td><td>{{Deleted by}}</td><td>{{Size}}</td>
				</tr>
			</thead>
			<tbody>
			{foreach from=$items item="i"}
				<tr>
					<td><input type="checkbox" name="items[]" value="{$i.value}" /></td>
					<td>{if $i.is_dir}&#128193; <a href="{$www_url}{$i.url}">{$i.name}</a> <span class="help">({$i.count} {{files}})</span>{else}&#128196; {$i.name}{/if}</td>
					<td>/{$i.path}{if $i.shared} <span class="help">({{shared by}} {$i.owner})</span>{/if}</td>
					<td>{$i.date}</td>
					<td>{$i.by}</td>
					<td>{$i.size|hsize}</td>
				</tr>
			{/foreach}
			</tbody>
		</table>
	</form>
	{if $has_own}<form method="post" action="" class="inline">{form_csrf}<input type="submit" name="empty_trash" value="{{Empty trash now}}" class="btn sm" onclick="return confirm('Empty the whole trash permanently?');" /></form>{/if}
{/if}

{/if}

{literal}<script>
var a=document.getElementById("all_items");
if(a){a.onclick=function(){var c=a.checked;[].forEach.call(document.querySelectorAll('input[name="items[]"]'),function(e){e.checked=c})}}
</script>{/literal}

{include file="_footer.tpl"}
