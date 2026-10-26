{{Public links} assign="title"}
{include file="_header.tpl" current="links"}

{if $form_error}
	<p class="error">{$form_error}</p>
{/if}

<h2>{{Public links}}</h2>

<p class="help">{{Anyone with one of these links can open the file or folder, without an account. Revoke a link to stop it working immediately.}}</p>

{if empty($links)}
	<p>{{There are no public links.}}</p>
{else}
<table>
	<thead>
		<tr><td>{{Item}}</td><td>{{Link}}</td><td>{{Created by}}</td><td>{{Created}}</td><td>{{Protection}}</td><td></td></tr>
	</thead>
	<tbody>
	{foreach from=$links item="l"}
		<tr>
			<td>
				{if $l.is_dir}&#128193;{else}&#128196;{/if} /{$l.path}
				{if $l.owner_other} <span class="help">({{shared by}} {$l.owner})</span>{/if}
				{if $l.label} <span class="help">&ldquo;{$l.label}&rdquo;</span>{/if}
				{if $l.missing} <span class="error">({{item no longer exists}})</span>{/if}
			</td>
			<td><a href="{$l.url}" target="_blank" rel="noopener noreferrer"><tt>{$l.url}</tt></a></td>
			<td>{if $l.by_me}{{Me}}{else}{$l.by}{/if}</td>
			<td>{$l.created}</td>
			<td>
				{if $l.protected}{{Password}}{else}{{None}}{/if}
				{if $l.expiry}<br />{if $l.expired}<span class="error">{{Expired}} {$l.expiry}</span>{else}{{Expires}} {$l.expiry}{/if}{/if}
			</td>
			<td>
				<form method="post" action="" class="inline">
					{form_csrf}
					<input type="hidden" name="id" value="{$l.id}" />
					<input type="submit" name="revoke" value="{{Revoke}}" class="btn sm" onclick="return confirm('Revoke this link? Anyone using it will lose access.')" />
				</form>
			</td>
		</tr>
	{/foreach}
	</tbody>
</table>
{/if}

{include file="_footer.tpl"}
