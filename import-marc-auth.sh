#!/bin/bash
#
# Wrapper around import-marc.sh to allow import of authority records.
#

E_BADARGS=65

# No arguments?  Display syntax:
if [ $# -eq 0 ]
then
  echo "    Usage: `basename $0` ./path/to/marc.mrc [properties file]"
  exit $E_BADARGS
fi

# Always use the standard authority mappings; if the user specified an override
# file, add that to the setting.
MAPPINGS_FILE="$VUFIND_HOME/import/marc_auth.properties"
if [ $# -gt 1 ]
then
  MAPPINGS_FILE="$MAPPINGS_FILE,$VUFIND_HOME/import/$2"
fi

# Override some settings in the standard import script:
export PROPERTIES_FILE="$VUFIND_HOME/import/import_auth.properties"
export SOLRCORE="authority"
export EXTRA_SOLRMARC_SETTINGS="-Dsolr.indexer.properties=$MAPPINGS_FILE"

# Call the standard script:
$VUFIND_HOME/import-marc.sh $1