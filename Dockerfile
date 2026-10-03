FROM php:8.3-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends unzip git libonig-dev \
	&& docker-php-ext-install mbstring \
	&& a2enmod rewrite headers \
	&& sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
	&& printf '%s\n' \
		'upload_max_filesize = 64M' \
		'post_max_size = 64M' \
		'memory_limit = 256M' \
		> /usr/local/etc/php/conf.d/pukiwikimd.ini \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
