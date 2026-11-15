FROM wordpress:php8.2-apache

ENV MYSQL_DATABASE=wordpress
ENV MYSQL_USER=wordpress
ENV MYSQL_PASSWORD=test-password
ENV MYSQL_ROOT_PASSWORD=root-password

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl unzip mariadb-server \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o /tmp/woocommerce.zip \
    && unzip -q /tmp/woocommerce.zip -d /usr/src/wordpress/wp-content/plugins/ \
    && rm /tmp/woocommerce.zip

RUN curl -fsSL https://github.com/kakavn87/woocommerce-order-integrity/archive/refs/heads/main.zip -o /tmp/order-integrity.zip \
    && unzip -q /tmp/order-integrity.zip -d /tmp/order-integrity \
    && cp -R /tmp/order-integrity/woocommerce-order-integrity-main/order-integrity /usr/src/wordpress/wp-content/plugins/ \
    && rm -rf /tmp/order-integrity /tmp/order-integrity.zip

RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp

RUN rm -rf /var/www/html/* \
    && cp -a /usr/src/wordpress/. /var/www/html/

RUN curl -fsSL https://raw.githubusercontent.com/kakavn87/woocommerce-order-integrity/main/test-shop/entrypoint.sh \
    -o /usr/local/bin/oi-test-shop-entrypoint \
    && chmod +x /usr/local/bin/oi-test-shop-entrypoint

CMD ["oi-test-shop-entrypoint"]
