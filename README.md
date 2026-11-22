# BMLT Minutes by Month

A companion plugin for [BMLT Minutes](https://wordpress.org/plugins/bmlt-minutes/). The `[bmlt_minutes_by_month]` shortcode lists minutes by committee, then by year (a collapsible section) and month. The attributes are documented in [readme.txt](bmlt-minutes-by-month/readme.txt).

## Development

```sh
composer install
bin/install-bmlt-minutes.sh                     # BMLT Minutes 1.1.0 from wordpress.org, checksum-pinned
bin/install-wp-tests.sh wordpress_test user pass 127.0.0.1   # empties that database on every run
WP_TESTS_DIR=/tmp/wordpress-tests/tests-lib vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist
WP_MULTISITE=1 WP_TESTS_DIR=… vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist
vendor/bin/phpcs && vendor/bin/phpstan analyse --memory-limit=2G
bin/build.sh                                    # dist/bmlt-minutes-by-month-<version>.zip from the last commit
```
