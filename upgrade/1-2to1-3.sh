#!/bin/bash

# Set up versions for convenience:
OLD=1.2
NEW=1.3

# Make sure the user is really ready to run this process
echo "VuFind $OLD to $NEW Upgrade Script"
echo ""
echo "Before you run this script, make sure you have done these things:"
echo ""
echo "1) Take VuFind offline to prevent new data being created during"
echo "   the upgrade process."
echo "2) Move your $OLD directory to a new location, and unpack $NEW into"
echo "   the old $OLD location.  DO NOT UNPACK $NEW ON TOP OF $OLD.  It is"
echo "   very important that you maintain separate directories.  Your $OLD"
echo "   directory will not be modified by this process, so you can revert"
echo "   fairly easily if you need to by simply moving directories around."
echo "3) Back up your MySQL database.  This script makes only minor, harmless"
echo "   changes to the database, but you should make your own backup just in" 
echo "   case something goes wrong."
echo ""
read -p "Are you ready to begin? [y/N] " GETSTARTED
echo ""
if [ "$GETSTARTED" != "Y" -a "$GETSTARTED" != "y" ]; then
   echo "Upgrade aborted."
   exit
fi

# default setting for VuFind root dir in different config files
PATTERN='/usr/local/vufind'

# find out where we are in the file system
upgrade_script_dir="$(dirname "$(readlink -f ${BASH_SOURCE[0]})")"

# cd to VuFind root dir as working directory 
# all further operation depend on that
# there is a hard coded assumption here that the upgrade script
# is living in a subdir of the VuFind root dir!!!
cd $upgrade_script_dir/..
VUFIND_PATH=`pwd`

# first adjust some paths
read -p "VuFind $NEW is installed in $VUFIND_PATH, correct? [Y/n] " YN
if [ "$YN" != "Y" -a "$YN" != "y" -a "$YN" != "" ];then
   read -p "Please enter the correct path: " VUFIND_PATH
   cd $VUFIND_PATH
fi

# check if there is a vufind.sh in VUFIND_PATH, if not ask for direction
while [ ! -e $VUFIND_PATH/vufind.sh ]; do
  echo "There is no VuFind installation in $VUFIND_PATH"
  read -p "Please enter the correct path: " VUFIND_PATH
  cd $VUFIND_PATH
done

echo "Using $VUFIND_PATH as installation path"

echo ""

echo "Where is your old VuFind $OLD installed?"
read -p "Please enter the path to the installation directory: " OLD_VUFIND_PATH
while [ ! -e $OLD_VUFIND_PATH/vufind.sh ]; do
  echo "There is no VuFind installation in $OLD_VUFIND_PATH"
  read -p "Please enter the correct path: " OLD_VUFIND_PATH
done

# now upgrade the database

echo ""
echo ""
echo "1) Upgrading MySQL Database"
echo "We need the credentials of an MySQL admin user to upgrade the database schema"

read -p "MySQL Root User [root]: " MYSQLADMUSER
MYSQLADMPASS=""
while [ "$MYSQLADMPASS" == "" ]; do
    read -p "MySQL Root Password: " -s MYSQLADMPASS
    if [ "$MYSQLADMPASS" == "" ]; then
        echo ""
        echo "Please enter a non-blank root password."
    fi
done
if [ -z $MYSQLADMUSER ]; then
    MYSQLADMUSER=root
fi

php upgrade/db_1-2to1-3.php $MYSQLADMUSER $MYSQLADMPASS $OLD_VUFIND_PATH

read -p "Hit ENTER to proceed";

echo ""
echo "2) configuring vufind.sh, httpd-vufind.conf and web/conf/config.ini"

sed -e "s!${PATTERN}!${VUFIND_PATH}!" vufind.sh > vufind.new
mv vufind.new vufind.sh
sed -e "s!${PATTERN}!${VUFIND_PATH}!" httpd-vufind.conf > httpd-vufind.conf.new
mv httpd-vufind.conf.new httpd-vufind.conf

# update paths in config.ini and then merge in settings from old version:
sed -e "s!${PATTERN}!${VUFIND_PATH}!" web/conf/config.ini > web/conf/config.ini.tmp
php upgrade/config_1-2to1-3.php $OLD_VUFIND_PATH web/conf/config.ini.tmp

# delete temporary intermediate ini file:
rm web/conf/config.ini.tmp

read -p "Hit ENTER to proceed";

# display post-upgrade notes
echo ""
echo "--------------------------------------------------------------"
echo "Upgrade finished.  You still need to do some things manually:"
echo ""
echo "1.) Take a look at *.ini.new in $VUFIND_PATH/web/conf/"
echo "and change settings where necessary. If you are happy with them,"
echo "rename the files to have plain .ini extensions (for example,"
echo "rename config.ini.new to config.ini)."
echo ""
echo "2.) Please check the contents of the file"
echo "$VUFIND_PATH/httpd-vufind.conf"
echo "and add it to your Apache configuration."
echo ""
echo "3.) Check the SolrMarc configuration in the import directory"
echo "and reindex all of your records."
echo ""
echo "4.) Obviously, if you have customized code, templates or index"
echo "fields in your previous installation, you will need to merge"
echo "your changes with the new code.  Feel free to ask questions on"
echo "the vufind-tech mailing list if you need help!"
echo ""
echo "For the latest notes on upgrading, see the online documentation"
echo "at http://www.vufind.org/wiki/migration_notes"