#!/bin/bash
#
# Run PHPUnit tests,
# if its 5.4+mysql then generate test coverage as well

set -evx

DB=$1
TRAVIS_PHP_VERSION=$2
SHORT_PHP=${TRAVIS_PHP_VERSION:0:3}

# Run phpunit tests for the site
if [ "$SHORT_PHP" == "5.5" -a "$DB" == "mysqli" ]
then
    phpunit --configuration Build/tests/travis-ci/phpunit-with-coverage-$DB-travis.xml
    phpunit /var/www/tests/travis-ci/BootstrapRunTest.php
elif [ "$DB" == "none" ]
then
    phpunit --configuration Build/tests/travis-ci/phpunit-basic-travis.xml
else
    phpunit --configuration Build/tests/travis-ci/phpunit-$DB-travis.xml
    phpunit Build/tests/travis-ci/BootstrapRunTest.php
fi