# Settings for the throwaway test WordPress. Test values only: the site runs in a
# container bound to 127.0.0.1 on the test host and is removed after the run.
IPK_HOST="${IPK_HOST:-root@80.241.220.76}"
IPK_PORT="${IPK_PORT:-8099}"
IPK_URL="http://127.0.0.1:${IPK_PORT}"
IPK_PREFIX="ipkwp"
IPK_WP_IMAGE="${IPK_WP_IMAGE:-wordpress:php8.3-apache}"
IPK_CLI_IMAGE="${IPK_CLI_IMAGE:-wordpress:cli-php8.3}"
IPK_DB_IMAGE="${IPK_DB_IMAGE:-mariadb:11}"
# Test-only admin and customer accounts in the container.
IPK_ADMIN_USER="ipkadmin"
IPK_ADMIN_PASS="Ds_BYH8a6mvlLtqX"
IPK_USER="ipktest"
IPK_USER_PASS="Ds_BYH8a6mvlLtqX-user"
# Key for the test helper's endpoints (?ipk_test=...), see mu-plugin/ipk-test-helper.php.
IPK_TEST_KEY="73a41f2389fab82b9ab5887f"
