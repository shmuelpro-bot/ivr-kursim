FROM php:8.2-apache
COPY . /var/www/html/
RUN a2enmod rewrite
ENV PORT=80
EXPOSE 80
CMD ["apache2-foreground"]
