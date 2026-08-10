FROM php:8.2-apache

# Enable Apache mod_rewrite and set AllowOverride All
RUN a2enmod rewrite && \
    sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Install any needed PHP extensions (cURL is usually already present)
RUN apt-get update && apt-get install -y libcurl4-openssl-dev pkg-config libssl-dev

# Copy both your PHP script and the .htaccess file
COPY index.php .htaccess /var/www/html/

# Expose port 80
EXPOSE 80
