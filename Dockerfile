FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

# Hard-reset MPM: remove all loaded MPM modules, enable only prefork
RUN rm -f /etc/apache2/mods-enabled/mpm_*.conf \
          /etc/apache2/mods-enabled/mpm_*.load \
    && a2enmod mpm_prefork \
    && a2enmod rewrite headers

RUN printf '<VirtualHost *:80>\n  DocumentRoot /var/www/html\n  <Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n    Options FollowSymLinks\n  </Directory>\n</VirtualHost>\n' \
    > /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/resume_generator/

RUN rm -f /var/www/html/resume_generator/api/config.local.php

RUN mkdir -p \
      /var/www/html/resume_generator/assets/generated_designs \
      /var/www/html/resume_generator/assets/uploaded_images \
      /var/www/html/resume_generator/assets/user_photos \
    && chown -R www-data:www-data /var/www/html/resume_generator \
    && chmod 777 \
        /var/www/html/resume_generator/assets/generated_designs \
        /var/www/html/resume_generator/assets/uploaded_images \
        /var/www/html/resume_generator/assets/user_photos

EXPOSE 80
