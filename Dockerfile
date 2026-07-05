# Railway deployment — PHP 8.2 + Apache
# App served at /resume_generator/ so all existing paths work unchanged
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN a2enmod rewrite headers

RUN printf '<VirtualHost *:80>\n  DocumentRoot /var/www/html\n  <Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n    Options FollowSymLinks\n  </Directory>\n</VirtualHost>\n' \
    > /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/resume_generator/

# Remove dev secrets — Railway env vars used instead
RUN rm -f /var/www/html/resume_generator/api/config.local.php \
          /var/www/html/resume_generator/api/config.local.php.example

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
