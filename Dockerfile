FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite headers

# Install GD for image resizing
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/*

# Copy project files
COPY . /var/www/html/

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Create writable data directory for file-based storage fallback
RUN mkdir -p /var/www/html/data \
    && echo "Deny from all" > /var/www/html/data/.htaccess

# Set permissions
RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/ \
    && chmod 775 /var/www/html/data

ENV PORT=80
EXPOSE 80

CMD ["apache2-foreground"]
