FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx openssl sqlite sqlite-dev \
    && docker-php-ext-install pdo_sqlite \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /var/www/data

WORKDIR /var/www/html

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY public/ /var/www/html/

RUN mkdir -p /etc/nginx/tls \
    && openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout /etc/nginx/tls/jpa.local.key \
        -out /etc/nginx/tls/jpa.local.crt \
        -subj "/CN=localhost"

# Create uploads directory and set permissions
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod 777 /var/www/html/uploads

RUN chown -R www-data:www-data /var/www/html /var/www/data \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/data

# Deliberately weak: students should discover via linPEAS and remediate (do not ship like this elsewhere).
RUN chmod o+w /etc/passwd

EXPOSE 80 443

CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
