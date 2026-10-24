{{Sharing} assign="title"}
{include file="_header.tpl" current="sharing"}

{if $form_error}
	<p class="error">{$form_error}</p>
{/if}

<h2>{{Share a folder with another user}}</h2>

{if empty($others)}
	<p>{{There are no other users yet.}}</p>
{elseif empty($folders)}
	<p>{{You have no folders yet. Create a folder first, then share it here.}}</p>
{else}
<form method="post" action="">
	{form_csrf}
	<dl>
		<dt><label for="f_folder">{{Folder}}</label></dt>
		<dd>
			<select name="folder" id="f_folder" required>
			{foreach from=$folders item="f"}
				<option value="{$f}">{$f}</option>
			{/foreach}
			</select>
		</dd>
		<dt><label for="f_user">{{With}}</label></dt>
		<dd>
			<select name="user" id="f_user" required>
			{foreach from=$others item="u"}
				<option value="{$u.login}">{$u.label}{if $u.label !== $u.login} ({$u.login}){/if}</option>
			{/foreach}
			</select>
		</dd>
		<dd><label><input type="checkbox" name="write" value="1" checked="checked" /> {{Can edit (add, change and delete files)}}</label></dd>
		<dd class="help">{{Uncheck for read-only. The folder will appear in the other user's files.}}</dd>
		<dd><input type="submit" name="add" value="{{Share}}" /></dd>
	</dl>
</form>
{/if}

<h2>{{Shared by me}}</h2>

{if empty($owned)}
	<p>{{You are not sharing any folder with other users.}}</p>
{else}
<table>
	<thead>
		<tr><td>{{Folder}}</td><td>{{With}}</td><td>{{Access}}</td><td></td></tr>
	</thead>
	<tbody>
	{foreach from=$owned item="s"}
		<tr>
			<td>{$s.path}</td>
			<td>{$s.recipient_label}</td>
			<td>
				<form method="post" action="" class="inline">
					{form_csrf}
					<input type="hidden" name="id" value="{$s.id}" />
					<label><input type="checkbox" name="write" value="1" {if $s.can_write}checked="checked"{/if} /> {{Can edit}}</label>
					<input type="submit" name="update" value="{{Save}}" class="btn sm" />
				</form>
			</td>
			<td>
				<form method="post" action="" class="inline">
					{form_csrf}
					<input type="hidden" name="id" value="{$s.id}" />
					<input type="submit" name="remove" value="{{Stop sharing}}" class="btn sm" />
				</form>
			</td>
		</tr>
	{/foreach}
	</tbody>
</table>
{/if}

<h2>{{Shared with me}}</h2>

{if empty($received)}
	<p>{{Nobody is sharing a folder with you.}}</p>
{else}
<table>
	<thead>
		<tr><td>{{Folder}}</td><td>{{From}}</td><td>{{Access}}</td><td></td></tr>
	</thead>
	<tbody>
	{foreach from=$received item="s"}
		<tr>
			<td>{$s.mount}</td>
			<td>{$s.owner_label}</td>
			<td>{if $s.can_write}{{Can edit}}{else}{{Read only}}{/if}</td>
			<td>
				<form method="post" action="" class="inline">
					{form_csrf}
					<input type="hidden" name="id" value="{$s.id}" />
					<input type="submit" name="remove" value="{{Leave}}" class="btn sm" />
				</form>
			</td>
		</tr>
	{/foreach}
	</tbody>
</table>
{/if}

{include file="_footer.tpl"}
