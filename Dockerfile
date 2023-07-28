# syntax=docker/dockerfile:1
FROM php:8.1-cli

RUN apt-get update -y && apt-get install -y --no-install-recommends \
    libzip-dev libbz2-dev && \
    docker-php-ext-install pcntl && \
    docker-php-ext-install bz2 && \
    docker-php-ext-install zip && \
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php -r "if (hash_file('sha384', 'composer-setup.php') === 'e21205b207c3ff031906575712edab6f13eb0b361f2085f1f1237b7126d785e826a450292b6cfd1d64d92e6563bbde02') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;" && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    php -r "unlink('composer-setup.php');" && \
    composer --global config process-timeout 1000

WORKDIR /usr/src/myapp
