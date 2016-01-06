#!/bin/bash
#
# Install elkarte to /var/www and setup the database
# If this is a defined coverage run (php5.4+mysql) also
#    - calls the selenium install script
#    - updates php.ini so selenium coverage results are also noted

set -evx

DB=$1
TRAVIS_PHP_VERSION=$2
SHORT_PHP=${TRAVIS_PHP_VERSION:0:3}
ELKDIR=./Elkarte

# Rename ElkArte test files so they can be used by the install
mv ./Elkarte/Settings.sample.php ./Elkarte/Settings.php
mv ./Elkarte/Settings_bak.sample.php ./Elkarte/Settings_bak.php
mv ./Elkarte/db_last_error.sample.txt ./Elkarte/db_last_error.txt

# Move everything to the www directory so Apache can find it
#mv * /var/www/
#cd /var/www

# Yes but its a test run
#chmod -R 777 /var/www

# Install the right database for this run
if [ "$DB" == "mysqli" ]; then php ./Build/tests/travis-ci/setup_mysql.php; fi
if [ "$DB" == "postgres" ]; then php ./Build/tests/travis-ci/setup_pgsql.php; fi

# Remove the install dir
rm -rf /var/www/Elkarte/src/Admin/Install

# Load in phpunit and its dependencies via composer, note we have a lock file in place
# composer is updated in setup-server.sh
if [ "$DB" != "none" ]
then
    composer install --no-interaction --prefer-source --quiet

    # Update the added phpunit files
    #sudo chmod -R 777 /var/www/vendor

    # common php.ini updates (if any)
    phpenv config-add /var/www/Build/tests/travis-ci/travis_php.ini

    # If this is a code coverage run, we need to enable selenium and capture its coverage results
    if [ "$SHORT_PHP" == "5.5" -a "$DB" == "mysqli" ]; then phpenv config-add /var/www/Build/tests/travis-ci/travis_webtest_php.ini; fi;
	if [ "$SHORT_PHP" == "5.5" -a "$DB" == "mysqli" ]; then ./Build/tests/travis-ci/setup-selenium.sh; fi;
fi