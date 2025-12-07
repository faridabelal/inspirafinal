FROM php:8.2-apache

# enable apache mod rewrite
RUN a2enmod rewrite

# install necessary php extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# copy project files into apache directory
COPY . /var/www/html/

# set correct permissions
RUN chown -R www-data:www-data /var/www/html

# expose port
EXPOSE 80
