FROM php:7.4-cli

# Instalar dependências e extensões PHP necessárias
RUN apt-get update && apt-get install -y \
    librabbitmq-dev \
    libssl-dev \
    zip \
    unzip \
    git \
    libzip-dev \
    libpq-dev \
    && docker-php-ext-install sockets zip pgsql pdo_pgsql \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copiar apenas o composer.json primeiro
COPY composer.json ./

# Instalar dependências
RUN composer install --no-dev --no-scripts --no-autoloader

# Copiar o resto dos arquivos da aplicação
COPY . .

# Otimizar o autoloader
RUN composer dump-autoload --optimize

CMD ["php", "consumer.php"]
