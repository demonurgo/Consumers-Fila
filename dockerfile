FROM php:8.1-cli

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
    && docker-php-ext-enable amqp
    #&& apt-get clean && rm -rf /var/lib/apt/lists/*

# Definir o diretório de trabalho
WORKDIR /app

# Instalar o Composer
#RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copiar o composer.json e o composer.lock
COPY composer.json composer.lock ./

# Instalar as dependências (incluindo o phpdotenv)
RUN composer install --no-dev --optimize-autoloader

# Copiar o restante dos arquivos da aplicação
COPY src/ ./src/

# Se houver arquivos adicionais necessários no diretório raiz, como o arquivo .env, copie-os também
#COPY .env ./

# Otimizar o autoloader do Composer
RUN composer dump-autoload --optimize

# Definir o comando padrão ao executar o container (opcional)
# CMD ["php", "./src/seu_script_principal.php"]