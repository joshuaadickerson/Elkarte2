#!/bin/bash
#
# Update the server package listing
# Install php Apache mod
# Configure and start Apache
# Install database tables as needed

set -evx

DB=$1
TRAVIS_PHP_VERSION=$2

# Apache webserver configuration
#sudo sed -i -e "/var/www" /etc/apache2/sites-available/default
#sudo a2enmod rewrite > /dev/null
#sudo a2enmod actions > /dev/null
#sudo a2enmod headers > /dev/null

# Restart Apache to take effect
#sudo /etc/init.d/apache2 restart

# Setup a database if we are installing

if [ "$DB" == "postgres" ]; then psql -c "DROP DATABASE IF EXISTS elkarte_test;" -U postgres; fi;
if [ "$DB" == "postgres" ]; then psql -c "create database elkarte_test;" -U postgres; fi;
if [ "$DB" == "mysqli" ]; then mysql -e "DROP DATABASE IF EXISTS elkarte_test;" -uroot; fi;
if [ "$DB" == "mysqli" ]; then mysql -e "create database IF NOT EXISTS elkarte_test;" -uroot; fi;