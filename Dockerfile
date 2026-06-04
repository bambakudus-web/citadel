FROM php:8.2-cli
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli curl
WORKDIR /app
COPY . .
RUN chmod +x start.sh
EXPOSE 80
CMD ["bash", "start.sh"]
