FROM php:8.2-apache

# PostgreSQL + MySQL PDO drivers (libpq-dev needed for pdo_pgsql)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# Startup: nuke extra MPMs, init DB schema, then start Apache
RUN printf '#!/bin/sh\nrm -f /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_worker.conf /etc/apache2/mods-enabled/mpm_worker.load\na2enmod mpm_prefork\nphp /var/www/html/resume_generator/api/bootstrap_db.php || true\nexec apache2-foreground\n' \
    > /start.sh && chmod +x /start.sh

RUN printf '<VirtualHost *:80>\n  DocumentRoot /var/www/html\n  <Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n    Options FollowSymLinks\n  </Directory>\n</VirtualHost>\n' \
    > /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/resume_generator/
RUN rm -f /var/www/html/resume_generator/api/config.local.php

# Root redirect so the bare URL lands in the app
RUN printf '<?php header("Location: /resume_generator/frontendreact/login.html"); exit;\n' > /var/www/html/index.php

RUN mkdir -p \
      /var/www/html/resume_generator/assets/generated_designs \
      /var/www/html/resume_generator/assets/uploaded_images \
      /var/www/html/resume_generator/assets/user_photos \
    && chown -R www-data:www-data /var/www/html/resume_generator \
    && chmod 777 \
        /var/www/html/resume_generator/assets/generated_designs \
        /var/www/html/resume_generator/assets/uploaded_images \
        /var/www/html/resume_generator/assets/user_photos

CMD ["/start.sh"]
