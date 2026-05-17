FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite headers

# Copy project files
COPY . /var/www/html/

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/

ENV PORT=80
EXPOSE 80

CMD ["apache2-foreground"]
