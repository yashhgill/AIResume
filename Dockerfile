# Dockerfile for deploying the api/ backend on Render (or any Docker host).
# Render's free tier has no native PHP runtime detection, but does support
# deploying any Dockerfile-based web service - this is that Dockerfile.
#
# Render auto-detects this file at the repo root when you create a new
# "Web Service" and point it at this repo - no extra config needed beyond
# what's in DEPLOYMENT.md.

FROM php:8.2-apache

# pdo_pgsql: PHP's PostgreSQL driver, needed for Render's managed Postgres
# (libpq-dev is its build dependency, removed after to keep the image small)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# Let .htaccess (api/.htaccess) actually take effect - the base image's
# default Apache config has AllowOverride disabled for the docroot.
RUN { \
        echo '<Directory /var/www/html>'; \
        echo '    AllowOverride All'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/zz-allow-override.conf \
    && a2enconf zz-allow-override

# Backend source, placed at /resume_generator/api/ so the existing
# "/resume_generator/api/..." URL convention used throughout the codebase
# (and the FRONTEND_BASE_URL / backend_base_url() cross-domain helpers)
# keeps working unchanged.
COPY api/ /var/www/html/resume_generator/api/

# Writable runtime asset folders - PHP creates files here at runtime
# (generated resume HTML, uploaded images/photos). They start empty; the
# .gitkeep files just let git track the otherwise-empty directories.
RUN mkdir -p /var/www/html/resume_generator/assets/generated_designs \
             /var/www/html/resume_generator/assets/uploaded_images \
             /var/www/html/resume_generator/assets/user_photos \
    && chown -R www-data:www-data /var/www/html/resume_generator/assets

# Render injects the actual port to listen on via $PORT at container
# start (not build time, hence this is in the entrypoint, not a RUN step).
# Defaulting to 10000 (Render's common default) for local `docker run`
# testing without Render's environment.
ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/:80/:${PORT}/\" /etc/apache2/sites-enabled/000-default.conf && apache2-foreground"]
