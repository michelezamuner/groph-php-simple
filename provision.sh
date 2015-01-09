#!/usr/bin/env bash
apt-get update
apt-get -y install apache2 libapache2-mod-php5 php5 php5-sqlite sqlite3 php5-xdebug

sed -i 's/User ${APACHE_RUN_USER}/User vagrant/g' /etc/apache2/apache2.conf
sed -i 's/Group ${APACHE_RUN_GROUP}/Group vagrant/g' /etc/apache2/apache2.conf
sed -i 's/DocumentRoot \/var\/www/DocumentRoot \/vagrant/g' /etc/apache2/sites-available/default
sed -i 's/<Directory \/var\/www\/>/<Directory \/vagrant\/>/g' /etc/apache2/sites-available/default
sed -i 's/short_open_tag = On/short_open_tag = Off/g' /etc/php5/apache2/php.ini
sed -i 's/error_reporting = E_ALL & ~E_DEPRECATED/error_reporting = E_ALL | E_STRICT/g' /etc/php5/apache2/php.ini
sed -i 's/display_errors = Off/display_errors = On/g' /etc/php5/apache2/php.ini
sed -i 's/display_startup_errors = Off/display_startup_errors = On/g' /etc/php5/apache2/php.ini
sed -i 's/html_errors = Off/html_errors = On/g' /etc/php5/apache2/php.ini
sed -i 's/disable_functions = */disable_functions = system, show_source, exec, shell_exec, proc_open, passthru, set_time_limit, ini_restore, mysql_list_dbs, ini_alter, dl, pfsockopen, openlog, syslog, symlink, link, chgrp, leak, popen, escapeshellcmd, apache_child_terminate, apache_get_modules, apache_get_version, apache_getenv, apache_note, apache_setenv, virtual, mb_send_mail/g' /etc/php5/apache2/php.ini
sed -i 's/;open_basedir = */open_basedir = \/vagrant:\/usr\/lib\/php5:\/tmp/g' /etc/php5/apache2/php.ini

a2dissite default && a2ensite default && service apache2 restart
