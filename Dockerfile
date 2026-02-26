FROM php:8.2-cli
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli curl
WORKDIR /app
COPY . .
EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "-t", "."]
