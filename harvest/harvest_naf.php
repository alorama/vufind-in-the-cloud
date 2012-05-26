<?php
/**
 * Tool to harvest Library of Congress Name Authority File from OCLC.
 *
 * PHP version 5
 *
 * Copyright (c) Demian Katz 2010.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind
 * @package  Harvest_Tools
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/authority_control Wiki
 */
require_once '../util/util.inc.php';    // set up util environment
require_once 'sys/SRU.php';
require_once 'sys/ConnectionManager.php';

// Read Config file
$configArray = readConfig();

// Perform the harvest -- note that first command line parameter may be used to
// start at a particular date.
$harvest = new HarvestNAF();
if (isset($argv[1])) {
    $harvest->setStartDate($argv[1]);
}
$harvest->launch();

/**
 * HarvestNAF Class
 *
 * This class harvests OCLC's Name Authority File to MARC-XML documents on the
 * local disk.
 *
 * @category VuFind
 * @package  Harvest_Tools
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/authority_control Wiki
 */
class HarvestNAF
{
    private $_sru;               // SRU connection
    private $_basePath;          // Directory for storing harvested files
    private $_lastHarvestFile;   // File for tracking last harvest date

    // Start scanning at an arbitrary date known to be earlier than the
    // oldest possible document.
    private $_startDate = '1900-01-01';

    /**
     * Constructor.
     *
     * @access public
     */
    public function __construct()
    {
        global $configArray;

        // Don't time out during harvest!!
        set_time_limit(0);

        // Set up base directory for harvested files:
        $home = getenv('VUFIND_HOME');
        if (empty($home)) {
            die("Please set the VUFIND_HOME environment variable.\n");
        }
        $this->_basePath = $home . '/harvest/lcnaf/';
        if (!is_dir($this->_basePath)) {
            if (!mkdir($this->_basePath)) {
                die("Problem creating directory {$this->_basePath}.\n");
            }
        }

        // Check if there is a file containing a start date:
        $this->_lastHarvestFile = $this->_basePath . 'last_harvest.txt';
        $this->_loadLastHarvestedDate();

        // Set up SRU connection:
        $this->_sru = new SRU('http://alcme.oclc.org/srw/search/lcnaf');
    }

    /**
     * Set a start date for the harvest (only harvest records AFTER this date).
     *
     * @param string $date Start date (YYYY-MM-DD format).
     *
     * @return void
     * @access public
     */
    public function setStartDate($date)
    {
        $this->_startDate = $date;
    }

    /**
     * Harvest all available documents.
     *
     * @return void
     * @access public
     */
    public function launch()
    {
        $this->_scanDates($this->_startDate);
        $this->_detectDeletes();
    }

    /**
     * Harvest LCCNs from OCLC to a file.
     *
     * @return string Filename of harvested data.
     * @access private
     */
    private function _harvestOCLCIds()
    {
        // Harvest all LCCNs to a file:
        $lccnListFile = dirname(__FILE__) . '/lcnaf/lccn-list-' . time() . '.tmp';
        $lccnList = fopen($lccnListFile, 'w');
        if (!$lccnList) {
            die('Problem opening file: ' . $lccnListFile . ".\n");
        }
        $lccn = '';
        do {
            $lccn = $this->_scanLCCNs($lccnList, $lccn);
        } while ($lccn);
        fclose($lccnList);
        return $lccnListFile;
    }

    /**
     * Harvest IDs from local Solr index to a file.
     *
     * @return string Filename of harvested data.
     * @access private
     */
    private function _harvestLocalIds()
    {
        // Harvest all local IDs to a file:
        $localListFile = dirname(__FILE__) . '/lcnaf/id-list-' . time() . '.tmp';
        $localList = fopen($localListFile, 'w');
        if (!$localList) {
            die('Problem opening file: ' . $localListFile . ".\n");
        }
        $id = '';
        $solr = ConnectionManager::connectToIndex('SolrAuth');
        do {
            echo "Reading IDs starting with '{$id}'...\n";
            $list = $solr->getTerms('id', $id, 10000);
            if (isset($list['terms']['id']) && !empty($list['terms']['id'])) {
                foreach ($list['terms']['id'] as $id => $count) {
                    fwrite($localList, $id . "\n");
                }
            } else {
                $id = false;
            }
        } while ($id);
        fclose($localList);
        return $localListFile;
    }

    /**
     * Given sorted ID lists, determine which have been deleted and which are
     * missing from the index.
     *
     * @param string $sortedOclcFile  File containing list of remote OCLC IDs.
     * @param string $sortedLocalFile File containing list of local IDs.
     * @param string $deletedFile     Filename to write deleted list to.
     *
     * @return void
     * @access private
     */
    private function _performDeleteComparison($sortedOclcFile, $sortedLocalFile,
        $deletedFile
    ) {
        $oclcIn = fopen($sortedOclcFile, 'r');
        if (!$oclcIn) {
            die("Can't open {$sortedOclcFile}\n");
        }
        $localIn = fopen($sortedLocalFile, 'r');
        if (!$localIn) {
            die("Can't open {$sortedLocalFile}\n");
        }
        $deleted = fopen($deletedFile, 'w');
        if (!$deleted) {
            die("Can't open {$deletedFile}\n");
        }

        // Flags to control which file(s) we read from:
        $readOclc = $readLocal = true;

        // Loop until we reach the ends of both files:
        do {
            // Read the next line from each file if necessary:
            if ($readOclc) {
                $oclcCurrent = fgets($oclcIn);
            }
            if ($readLocal) {
                $localCurrent = fgets($localIn);
            }

            if (!$localCurrent || strcmp($oclcCurrent, $localCurrent) < 0) {
                // If OCLC is less than local (or we've reached the end of the
                // local file), we've found a record that hasn't been indexed yet;
                // no action is needed -- just skip it and read the next OCLC line.
                $readOclc = true;
                $readLocal = false;
            } else if (!$oclcCurrent || strcmp($oclcCurrent, $localCurrent) > 0) {
                // If OCLC is greater than local (or we've reached the end of the
                // OCLC file), we've found a deleted record; write it to file and
                // read the next local value.
                fputs($deleted, $localCurrent);
                $readOclc = false;
                $readLocal = true;
            } else {
                // If current lines match, just read another pair of lines:
                $readOclc = $readLocal = true;
            }
        } while ($oclcCurrent || $localCurrent);

        fclose($oclcIn);
        fclose($localIn);
        fclose($deleted);
    }

    /**
     * Scan the index for deleted records.
     *
     * @return void
     * @access private
     */
    private function _detectDeletes()
    {
        // Harvest IDs from local and OCLC indexes:
        $oclcFile = $this->_harvestOCLCIds();
        $localFile = $this->_harvestLocalIds();

        // Sort the two lists consistently:
        $sortedOclcFile = dirname(__FILE__) . '/lcnaf/lccn-sorted.txt';
        $sortedLocalFile = dirname(__FILE__) . '/lcnaf/id-sorted.txt';

        exec("sort < {$oclcFile} > {$sortedOclcFile}");
        exec("sort < {$localFile} > {$sortedLocalFile}");

        // Delete unsorted data files:
        unlink($oclcFile);
        unlink($localFile);

        // Diff the files in order to generate a .delete file so we can remove
        // obsolete records from the Solr index:
        $deletedFile = dirname(__FILE__) . '/lcnaf/' . time() . '.delete';
        $this->_performDeleteComparison(
            $sortedOclcFile, $sortedLocalFile, $deletedFile
        );

        // Deleted sorted data files now that we are done with them:
        unlink($sortedOclcFile);
        unlink($sortedLocalFile);
    }

    /**
     * Normalize an LCCN to match an ID generated by the LCNAF SolrMarc import
     * process (see the various .bsh files in import/index_scripts).
     *
     * @param string $lccn Regular LCCN
     *
     * @return string      Normalized LCCN
     * @access private
     */
    private function _normalizeLCCN($lccn)
    {
        // Remove whitespace:
        $lccn = str_replace(" ", "", $lccn);

        // Chop off anything following a forward slash:
        $parts = explode('/', $lccn, 2);
        $lccn = $parts[0];

        // Normalize any characters following a hyphen to at least six digits:
        $parts = explode('-', $lccn, 2);
        if (count($parts) > 1) {
            $secondPart = $parts[1];
            while (strlen($secondPart) < 6) {
                $secondPart = "0" . $secondPart;
            }
            $lccn = $parts[0] . $secondPart;
        }

        // Send back normalized LCCN:
        return 'lcnaf-' . $lccn;
    }

    /**
     * Recursively obtain all of the LCCNs from the LCNAF index.
     *
     * @param resource $handle File handle to write normalized LCCNs to.
     * @param string   $start  Starting point in list to read from
     * @param int      $retry  Retry counter (in case of connection problems).
     *
     * @return string          Where to start the next scan to continue the
     * operation (boolean false when finished).
     * @access private
     */
    private function _scanLCCNs($handle, $start = '', $retry = 0)
    {
        echo "Scanning LCCNs after \"{$start}\"...\n";

        // Find all dates AFTER the specified start date
        $result = $this->_sru->scan('local.LCCN="' . $start . '"', 0, 250);
        if (!PEAR::isError($result)) {
            // Parse the response:
            $result = simplexml_load_string($result);
            if (!$result) {
                // We experienced a failure; let's retry three times before we
                // give up and report failure.
                if ($retry > 2) {
                    die("Problem loading XML: {$result}\n");
                } else {
                    echo "Problem loading XML; retrying...\n";
                    // Wait a few seconds in case that helps...
                    sleep(5);

                    return $this->_scanLCCNs($handle, $start, $retry + 1);
                }
            }

            // Extract terms from the response:
            $namespaces = $result->getDocNamespaces();
            $result->registerXPathNamespace('ns', $namespaces['']);
            $result = $result->xpath('ns:terms/ns:term');

            // No terms?  We've hit the end of the road!
            if (!is_array($result)) {
                return;
            }

            // Process all the dates in this batch:
            foreach ($result as $term) {
                $lccn = (string)$term->value;
                $count = (int)$term->numberOfRecords;
                fwrite($handle, $this->_normalizeLCCN($lccn) . "\n");
            }
        }

        // Continue scanning with results following the last date encountered
        // in the loop above:
        return isset($lccn) ? $lccn : false;
    }

    /**
     * Retrieve the date from the "last harvested" file and use it as our start
     * date if it is available.
     *
     * @return void
     * @access private
     */
    private function _loadLastHarvestedDate()
    {
        if (file_exists($this->_lastHarvestFile)) {
            $lines = file($this->_lastHarvestFile);
            if (is_array($lines)) {
                $date = trim($lines[0]);
                if (!empty($date)) {
                    $this->setStartDate(trim($date));
                }
            }
        }
    }

    /**
     * Save a date to the "last harvested" file.
     *
     * @param string $date Date to save.
     *
     * @return void
     * @access private
     */
    private function _saveLastHarvestedDate($date)
    {
        file_put_contents($this->_lastHarvestFile, $date);
    }

    /**
     * Retrieve records modified on the specified date.
     *
     * @param string $date  Date of modification for retrieved records
     * @param int    $count Number of records expected (double-check)
     *
     * @return void
     * @access private
     */
    private function _processDate($date, $count)
    {
        // Don't reload data we already have!
        $path = $this->_basePath . $date . '.xml';
        if (file_exists($path)) {
            return;
        }

        echo "Processing records for {$date}...\n";

        // Open the output file:
        $file = fopen($path, 'w');
        $startTag = '<mx:collection xmlns:mx="http://www.loc.gov/MARC21/slim">';
        if (!$file || !fwrite($file, $startTag)) {
            unlink($path);
            die("Unable to open {$path} for writing.\n");
        }

        // Pull down all the records:
        $start = 1;
        $limit = 250;
        $query = 'oai.datestamp="' . $date . '"';
        do {
            $numFound = $this->_getRecords($query, $start, $limit, $file);
            $start += $numFound;
        } while ($numFound == $limit);

        // Close the file:
        if (!fwrite($file, '</mx:collection>') || !fclose($file)) {
            unlink($path);
            die("Problem closing file.\n");
        }

        // Sanity check -- did we get as many records as we expected to?
        $finalCount = $start - 1;
        if ($finalCount != $count) {
            // Delete the problem file so we can rebuild it later:
            unlink($path);
            die(
                "Problem loading records for {$date} -- " .
                "expected {$count}, retrieved {$finalCount}.\n"
            );
        }

        // Update the "last harvested" file:
        $this->_saveLastHarvestedDate($date);
    }

    /**
     * Pull down records from LC NAF.
     *
     * @param string $query Search query for loading records
     * @param int    $start Index of first record to load
     * @param int    $limit Maximum number of records to load
     * @param int    $file  Open file handle to write records to
     *
     * @return int          Actual number of records loaded
     * @access private
     */
    private function _getRecords($query, $start, $limit, $file)
    {
        // Retrieve the records:
        $xml = $this->_sru->search(
            $query, $start, $limit, null, 'info:srw/schema/1/marcxml-v1.1', false
        );
        $result = simplexml_load_string($xml);
        if (!$result) {
            die("Problem loading XML: {$xml}\n");
        }

        // Extract the records from the response:
        $namespaces = $result->getDocNamespaces();
        $result->registerXPathNamespace('ns', $namespaces['']);
        $result->registerXPathNamespace('mx', 'http://www.loc.gov/MARC21/slim');
        $result = $result->xpath('ns:records/ns:record/ns:recordData/mx:record');

        // No records?  We've hit the end of the line!
        if (empty($result)) {
            return 0;
        }

        // Process records and return a bad value if we have trouble writing
        // (in order to ensure that we die and can retry later):
        foreach ($result as $current) {
            if (!fwrite($file, $current->asXML())) {
                return 0;
            }
        }

        // If we found less than the limit, we've hit the end of the list;
        // otherwise, we should return the index of the next record to load:
        return count($result);
    }

    /**
     * Recursively scan the remote index to find dates we can retrieve.
     *
     * @param string $start The date to use as the basis for scanning; this date
     * will NOT be included in results.
     *
     * @return void
     * @access private
     */
    private function _scanDates($start)
    {
        echo "Scanning dates after {$start}...\n";

        // Find all dates AFTER the specified start date
        $result = $this->_sru->scan('oai.datestamp="' . $start . '"', 0, 250);
        if (!PEAR::isError($result)) {
            // Parse the response:
            $result = simplexml_load_string($result);
            if (!$result) {
                die("Problem loading XML: {$result}\n");
            }

            // Extract terms from the response:
            $namespaces = $result->getDocNamespaces();
            $result->registerXPathNamespace('ns', $namespaces['']);
            $result = $result->xpath('ns:terms/ns:term');

            // No terms?  We've hit the end of the road!
            if (!is_array($result)) {
                return;
            }

            // Process all the dates in this batch:
            foreach ($result as $term) {
                $date = (string)$term->value;
                $count = (int)$term->numberOfRecords;
                $this->_processDate($date, $count);
            }
        }

        // Continue scanning with results following the last date encountered
        // in the loop above:
        if (isset($date)) {
            $this->_scanDates($date);
        }
    }
}


?>