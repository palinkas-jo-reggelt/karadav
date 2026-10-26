{{Connect an application} assign="title"}
{include file="_header.tpl"}

{if $state === 'ask'}
	<h2>{{Allow this application to access your files?}}</h2>

	<form method="post" action="">
		<dl>
			<dt>{{Account}}</dt>
			<dd>{$account}</dd>
			<dt>{{Application}}</dt>
			<dd>{if $client}<tt>{$client}</tt>{else}{{A Nextcloud or ownCloud application}}{/if}</dd>
			{if $flow_ip}
				<dt>{{Request sent from}}</dt>
				<dd>
					{$flow_ip}
					{if $flow_ip === $my_ip}
						<span class="help">({{same address as this browser}})</span>
					{else}
						<span class="error">({{not the address of this browser}}: {$my_ip})</span>
					{/if}
				</dd>
			{/if}
		</dl>

		<p class="info">
			{{It will be able to read, change and delete all the files of this account, until you remove its access.}}
			<strong>{{Only continue if you have just asked an application on one of your own devices to connect.}}</strong>
			{{If someone sent you this page, or you did not start anything, cancel.}}
		</p>

		<p class="submit">
			<input type="submit" name="grant" value="{{Grant access}}" class="btn" />
			<input type="submit" name="deny" value="{{Cancel}}" class="btn" />
		</p>
		{form_csrf}
	</form>
{elseif $state === 'done'}
	<p class="info">{{The application is now connected. You can close this window or tab and go back to it.}}</p>
{elseif $state === 'denied'}
	<p class="info">{{Access was not given. You can close this window or tab.}}</p>
{else}
	<p class="error">{{This link has expired or was already used. Start again from the application.}}</p>
{/if}

{include file="_footer.tpl"}
