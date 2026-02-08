FROM php:8.2-cli

# 1. Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev

# 2. Install PHP extensions (Zip is crucial for your EPUB feature)
RUN docker-php-ext-install zip

# 3. Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Set working directory
WORKDIR /var/www

# 5. Copy your code
COPY . .

# 6. Install Laravel dependencies
RUN composer install --no-dev --optimize-autoloader

# 7. Start the server (Using Port 10000 for Render)
CMD php artisan serve --host=0.0.0.0 --port=10000