FROM php:8.3-apache

ENV TZ=Asia/Taipei

RUN a2enmod rewrite

WORKDIR /var/www/html

RUN docker-php-ext-install pdo pdo_mysql zip \

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]