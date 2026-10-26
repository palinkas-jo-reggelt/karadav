# Installing KaraDAV

0. Setup your server with PHP 8.0+, and don't forget `php-sqlite3` and `php-simplexml` extensions :)
1. Just download or clone this repo
2. Optional:
  * Copy `config.dist.php` to `config.local.php`
  * Edit `config.local.php` and change constants to match your configuration
4. Create a virtual host (nginx, Apache, etc.) pointing to the `www` folder
5. Redirect all requests to `www/_router.php`
6. Go to your new virtual host, a default admin user is created the first time you access the UX, with the login `demo` and the password `karadavdemo`, please change it.

If you want to enable image thumbnails, installing `php-imagick` or `php-gd` will do the trick. Note that this will add a significant workload on your server, and will create a lot of files as well.

If you are on a 32-bits system, the `curl` extension is required (`apt install php-curl`) for reading files that have a size larger than 2 GB.

## Example Apache vhost

```
<VirtualHost *:80>
	ServerName karadav.localhost
	DocumentRoot /home/user/git/karadav/www
</VirtualHost>

<Directory /home/user/git/karadav/www>
	Options -Indexes -Multiviews
	AllowOverride None
	DirectoryIndex index.php

	RewriteEngine On
	RewriteBase /
	RewriteCond %{REQUEST_FILENAME} !-d
	RewriteCond %{REQUEST_FILENAME} !-f
	RewriteRule ^.*$ /_router.php [L]
</Directory>
```

## Example Apache vhost with HTTPS and mod_fcgid (Windows / XAMPP)

This is a complete setup where Apache is the first server reached from the internet, serves PHP through `mod_fcgid` and handles the certificate. Replace the host name, e-mail address and paths. The modules `rewrite`, `headers`, `ssl` and `fcgid` must be loaded.

```
<VirtualHost *:80>
	ServerAdmin admin@example.com
	ServerName files.example.com
	RewriteEngine On
	RewriteCond %{HTTPS} off
	# Use a fixed host name: %{HTTP_HOST} comes from the client and would allow open redirects
	RewriteRule ^(.*)$ https://files.example.com$1 [R=301,L]
	ErrorLog "logs/files.example.com-error.log"
</VirtualHost>

<VirtualHost *:443>
	ServerAdmin admin@example.com
	ServerName files.example.com
	DocumentRoot "C:/xampp/www/karadav/www"
	ErrorLog "logs/files.example.com-error.log"

	SSLEngine on
	SSLCertificateFile "C:/xampp/certificates/files.example.com-crt.pem"
	SSLCertificateKeyFile "C:/xampp/certificates/files.example.com-key.pem"
	SSLCertificateChainFile "C:/xampp/certificates/files.example.com-chain.pem"

	# Required for Basic authentication (WebDAV and Nextcloud clients)
	FcgidPassHeader Authorization
	# Uploads and downloads: mod_fcgid refuses requests over 128 KB and stops them after 5 minutes by default
	FcgidMaxRequestLen 1073741824
	FcgidIOTimeout 3600
	FcgidBusyTimeout 3600

	Header always set Strict-Transport-Security "max-age=31536000"
	Header always set X-Frame-Options "SAMEORIGIN"

	<Directory "C:/xampp/www/karadav/www">
		# Keep the options your global configuration needs to run PHP with mod_fcgid (often +ExecCGI)
		Options -Indexes -MultiViews
		AllowOverride None
		Require all granted
		DirectoryIndex index.php

		RewriteEngine On
		RewriteBase /
		RewriteCond %{REQUEST_FILENAME} !-d
		RewriteCond %{REQUEST_FILENAME} !-f
		RewriteRule ^.*$ /_router.php [L]
	</Directory>
</VirtualHost>
```

Notes:

* Do not add a `<Limit>` or `<LimitExcept>` block: WebDAV needs `PROPFIND`, `MKCOL`, `MOVE`, `COPY` and `LOCK`.
* Set the complete address in `config.local.php`, so that cookies are marked `Secure` and links use `https`:

  ```php
  const WWW_URL = 'https://files.example.com/';
  ```
* The web server user must be able to write to the KaraDAV folder (for `error.log`) and to the `data` folder.

### Client IP addresses and failed logins

KaraDAV slows down password guessing by counting failed logins per IP address (see the `THROTTLE_*` settings in `config.dist.php`), so it has to know the real address of each visitor.

* **Apache is the first server reached by visitors** (as above): nothing to do, the address of the connection is the visitor's.
* **Another proxy is in front of Apache** (Cloudflare, nginx, a proxy manager…): all visitors would share the address of the proxy and block each other. Let Apache restore the real address, for requests coming from your proxy only:

  ```
  LoadModule remoteip_module modules/mod_remoteip.so
  RemoteIPHeader X-Forwarded-For
  RemoteIPInternalProxy 192.168.1.10
  ```

  Replace the address by the one of your proxy, which must set that header itself and replace any value sent by the client. If you cannot use `mod_remoteip`, set `CLIENT_IP_HEADER` in `config.local.php` instead (eg. `const CLIENT_IP_HEADER = 'HTTP_X_FORWARDED_FOR';`), but only if the web server cannot be reached without going through the proxy: anyone could otherwise choose their own address.

## Using a sub-directory for install

Let's say you want KaraDAV to be available at `http://me.localhost/dav/`

Set up an alias like this:

```
<VirtualHost *:80>
	ServerName me.localhost
	Alias /dav /home/bohwaz/git/karadav/www
</VirtualHost>

<Directory /home/user/git/karadav/www>
	Options -Indexes -Multiviews
	AllowOverride None
	DirectoryIndex index.php

	RewriteEngine On
	RewriteBase /dav/
	RewriteCond %{REQUEST_FILENAME} !-d
	RewriteCond %{REQUEST_FILENAME} !-f
	RewriteRule ^.*$ /dav/_router.php [L]
</Directory>
```

If you want to use a different sub-directory, you'll need to change `/dav/` to the correct name.

Then create or edit the `config.local.php` file at the root of KaraDAV and make sure it contains the correct URL:

```
const WWW_URL = 'http://me.localhost/dav/';
```

Or this won't work.

## Example Nginx with php-fpm

```
server {
    listen 80;
    listen [::]:80;

    server_name karadav.localhost;

    root /home/user/git/karadav/www;

    index index.php;

    # Serve files
    location / {
        try_files $uri $uri/ /_router.php?$query_string;
    }

    location ~* \.php$ {
    	try_files $uri /_router.php?$query_string;
        fastcgi_pass unix:/run/php-fpm/www.sock;
        include fastcgi_params;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## Security issues

* Do not expose the `data` directory on your webserver, or your app database might be leaked, as well as your users data.
* Do not set the virtual host document root to the root of KaraDAV instead of the `www` directory. Please use a dedicated virtual host, or an `Alias`.

## Using per-user local UID/GID for user data

This would be useful if you want to have a different UNIX user for each of your users data directory, to keep them separate.

You'll need to install `apache2-mpm-itk` ([official website](http://mpm-itk.sesse.net)) and set up your virtualhost like that:

```
<VirtualHost *:80>
	ServerName karadav.localhost

	SetEnvIf Request_URI (.+) ITKUID=www-data ITKGID=www-data
	SetEnvIf Request_URI ^/files/([a-z]+)/ ITKUID=$1 ITKGID=$1

	# Do not allow root to be used as the ITK UID/GID
	SetEnvIf ITKUID ^root$ ITKUID=www-data
	SetEnvIf ITKGID ^root$ ITKGID=www-data

	AssignUserIDExpr %{reqenv:ITKUID}
	AssignGroupIDExpr %{reqenv:ITKGID}

	DocumentRoot /home/bohwaz/git/karadav/www
</VirtualHost>
```

# Using Docker

Docker distribution is handled by the community, see <https://hub.docker.com/search?q=karadav>

Please don't file issues related to Docker on this repository, it is dedicated to software development, not distribution issues.

# Using LDAP

Make sure you have the PHP LDAP extension (`apt install php-ldap`), then see the appropriate configuration constants in `config.local.php`.
