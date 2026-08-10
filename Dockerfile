FROM php:8.2-apache
# Enable necessary PHP extensions if needed (cURL is usually enabled by default)
RUN apt-get update && apt-get install -y libcurl4-openssl-dev pkg-config libssl-dev
# Copy your PHP script to the web root
COPY index.php /var/www/html/
# Expose port 80
EXPOSE 80
