FROM php:8.2-apache
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*
RUN mkdir -p /var/www/html/data && chmod 777 /var/www/html/data
COPY . /var/www/html/
RUN a2enmod rewrite
ENV PORT=80
EXPOSE 80
CMD ["apache2-foreground"]
