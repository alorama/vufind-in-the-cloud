#!/bin/sh

# Make sure VUFIND_HOME is set:
if [ -z "$VUFIND_HOME" ]
then
  echo "Please set the VUFIND_HOME environment variable."
  exit 1
fi

# Make sure command line parameter was included:
if [ -z "$2" ]
then
  echo "This script processes a batch of harvested XML records using the specified XSL"
  echo "import configuration file."
  echo ""
  echo "Usage: `basename $0` [$VUFIND_HOME/harvest subdirectory] [properties file]"
  echo ""
  echo "Note: Unless an absolute path is used, [properties file] is treated as being"
  echo "      relative to $VUFIND_HOME/import.";
  echo ""
  echo "Example: `basename $0` oai_source ojs.properties"
  exit 1
fi

# Check if the path is valid:
BASEPATH="$VUFIND_HOME/harvest/$1"
if [ ! -d $BASEPATH ]
then
  echo "Directory $BASEPATH does not exist!"
  exit 1
fi

# Create log/processed directories as needed:
if [ ! -d $BASEPATH/processed ]
then
  mkdir $BASEPATH/processed
fi

# Flag -- do we need to perform an optimize?
OPTIMIZE=0

# Process all the files in the target directory:
cd $VUFIND_HOME/import
for file in $BASEPATH/*.xml
do
  if [ -f $file ]
  then
    echo "Processing $file ..."
    php import-xsl.php $file $2
    # Only move the file into the "processed" folder if processing was successful:
    if [ "$?" -eq "0" ]
    then
      mv $file $BASEPATH/processed/`basename $file`
      # We processed a file, so we need to optimize later on:
      OPTIMIZE=1
    fi
  fi
done

# Optimize the index now that we are done (if necessary):
if [ "$OPTIMIZE" -eq "1" ]
then
  cd $VUFIND_HOME/util
  echo "Optimizing index..."
  php optimize.php
fi
