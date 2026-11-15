#!/bin/bash
set -e

mkdir -p /run/mysqld
chown -R mysql:mysql /run/mysqld /var/lib/mysql

if [ ! -d /var/lib/mysql/mysql ]; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql >/dev/null
fi

mysqld_safe --datadir=/var/lib/mysql --skip-networking=0 --bind-address=127.0.0.1 &
for i in {1..60}; do
  if mysqladmin ping -h 127.0.0.1 --silent; then break; fi
  sleep 1
done

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$MYSQL_DATABASE\`;
CREATE USER IF NOT EXISTS '$MYSQL_USER'@'localhost' IDENTIFIED BY '$MYSQL_PASSWORD';
GRANT ALL PRIVILEGES ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'localhost';
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASSWORD';
FLUSH PRIVILEGES;
SQL

export WORDPRESS_DB_HOST=127.0.0.1:3306
export WORDPRESS_DB_NAME=$MYSQL_DATABASE
export WORDPRESS_DB_USER=$MYSQL_USER
export WORDPRESS_DB_PASSWORD=$MYSQL_PASSWORD

exec apache2-foreground
