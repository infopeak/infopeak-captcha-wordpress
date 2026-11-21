#!/usr/bin/env bash
# Throwaway WordPress for the integration tests, in Docker on a test host reached over SSH.
#
#   tests/integrations/wp/site.sh up                 containers, WordPress, form plugins, pages
#   tests/integrations/wp/site.sh deploy [git-ref]   put the plugin in: the working tree, or a commit
#   tests/integrations/wp/site.sh wp <args>          run wp-cli on the site
#   tests/integrations/wp/site.sh versions           WordPress, PHP and plugin versions
#   tests/integrations/wp/site.sh down               remove containers, volumes, network and images
#
# The site listens on 127.0.0.1:$IPK_PORT on the test host only. Open a tunnel to reach it:
#   ssh -N -L 8099:127.0.0.1:8099 root@<test host>
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"
# shellcheck source=config.sh
. "$here/config.sh"

P="$IPK_PREFIX"
DBENV="-e WORDPRESS_DB_HOST=$P-db:3306 -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp"
remote() { ssh -o BatchMode=yes "$IPK_HOST" "$@"; }
wpcli() { remote "docker run --rm -i --network $P --volumes-from $P-web --user 33:33 -e HOME=/tmp $DBENV $IPK_CLI_IMAGE wp --path=/var/www/html $(printf '%q ' "$@")"; }
# Copy a local directory's contents into a path in the web container (as www-data).
put_dir() {
	local src="$1" dest="$2"
	tar -C "$src" -cf - . | remote "docker exec -i $P-web sh -c 'rm -rf $dest && mkdir -p $dest && tar -xf - -C $dest && chown -R www-data:www-data $dest'"
}

case "${1:-}" in
up)
	remote "set -e
		docker network inspect $P >/dev/null 2>&1 || docker network create $P >/dev/null
		docker run -d --name $P-db --network $P -e MARIADB_RANDOM_ROOT_PASSWORD=1 -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp -v $P-db:/var/lib/mysql $IPK_DB_IMAGE >/dev/null
		docker run -d --name $P-web --network $P -p 127.0.0.1:$IPK_PORT:80 $DBENV -v $P-html:/var/www/html $IPK_WP_IMAGE >/dev/null
		docker pull -q $IPK_CLI_IMAGE >/dev/null
		for i in \$(seq 1 60); do docker exec $P-web test -f /var/www/html/wp-config.php && docker exec $P-db mariadb -uwp -pwp -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done"
	wpcli core install --url="$IPK_URL" --title="IPK test" --admin_user="$IPK_ADMIN_USER" --admin_password="$IPK_ADMIN_PASS" --admin_email=admin@example.test --skip-email
	wpcli config set IPK_TEST_KEY "$IPK_TEST_KEY"
	wpcli config set JETPACK_DEV_DEBUG true --raw
	# All form plugins at once need more than PHP's default 128M, in wp-cli too.
	wpcli config set WP_MEMORY_LIMIT 512M
	wpcli rewrite structure '/%postname%/' --hard
	wpcli option update users_can_register 1
	wpcli option update comment_moderation 0
	wpcli option update comment_previously_approved 0
	wpcli user create "$IPK_USER" ipktest@example.test --user_pass="$IPK_USER_PASS" --role=subscriber
	put_dir "$here/mu-plugin" /var/www/html/wp-content/mu-plugins
	wpcli plugin install contact-form-7 wpforms-lite jetpack woocommerce fluentform formidable forminator cache-enabler plugin-check --activate
	wpcli jetpack module activate contact-form || true
	# Formidable creates its tables on its own install step, which wp-cli activation skips.
	wpcli eval 'FrmAppController::install();'
	wpcli option update ipkcap_settings '{"sitekey":"ipk_test_sitekey","secret":"ipk_test_secret"}' --format=json
	remote "cat > /tmp/$P-setup-content.php" < "$here/setup-content.php"
	remote "docker run --rm --network $P --volumes-from $P-web --user 33:33 -e HOME=/tmp $DBENV -v /tmp/$P-setup-content.php:/setup-content.php:ro $IPK_CLI_IMAGE wp --path=/var/www/html eval-file /setup-content.php; rm -f /tmp/$P-setup-content.php"
	# Page cache for the /cached-* pages only, so the other tests see uncached pages.
	wpcli option patch update cache_enabler excluded_page_paths '/^\/(?!cached-)/'
	wpcli option patch update cache_enabler cache_expires 0
	wpcli eval 'if ( class_exists( "Cache_Enabler" ) ) { Cache_Enabler::update_backend(); Cache_Enabler_Disk::create_settings_file( Cache_Enabler::get_settings() ); } Cache_Enabler::clear_complete_cache();' || true
	echo "Site ready at $IPK_URL (through the tunnel)."
	;;
deploy)
	tmp="$(mktemp -d)"
	cd "$repo"
	if [ -n "${2:-}" ]; then
		git archive "$2" infopeak-captcha | tar -xf - -C "$tmp"
	else
		cp -r infopeak-captcha "$tmp/"
	fi
	put_dir "$tmp/infopeak-captcha" /var/www/html/wp-content/plugins/infopeak-captcha
	rm -rf "$tmp"
	put_dir "$here/mu-plugin" /var/www/html/wp-content/mu-plugins
	wpcli plugin activate infopeak-captcha >/dev/null
	wpcli eval 'if ( class_exists( "Cache_Enabler" ) ) Cache_Enabler::clear_complete_cache();' >/dev/null || true
	ref="${2:-HEAD}"
	note=""
	if [ -z "${2:-}" ] && { ! git diff --quiet HEAD -- infopeak-captcha || [ -n "$(git ls-files --others --exclude-standard infopeak-captcha)" ]; }; then note=" + uncommitted changes"; fi
	echo "Deployed ${2:-working tree} ($(git rev-parse --short "$ref")$note)"
	;;
wp)
	shift
	wpcli "$@"
	;;
versions)
	wpcli eval 'echo "WordPress " . get_bloginfo( "version" ) . ", PHP " . PHP_VERSION . "\n";'
	wpcli plugin list --fields=name,version,status --format=csv
	remote "docker exec $P-db mariadb --version"
	;;
down)
	remote "docker rm -f $P-web $P-db >/dev/null 2>&1; docker volume rm $P-db $P-html >/dev/null 2>&1; docker network rm $P >/dev/null 2>&1; docker rmi $IPK_WP_IMAGE $IPK_CLI_IMAGE $IPK_DB_IMAGE >/dev/null 2>&1; rm -f /tmp/$P-*; echo removed"
	;;
*)
	sed -n 2,11p "$0"
	exit 1
	;;
esac
