<?php
/**
 * OAI-PMH Harvest Tool
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
 * @link     http://vufind.org/wiki/importing_records#oai-pmh_harvesting Wiki
 */
require_once '../util/util.inc.php';        // set up util environment
require_once 'sys/Proxy_Request.php';

// Read Config files
$configArray = readConfig();
$oaiSettings = @parse_ini_file('oai.ini', true);
if (empty($oaiSettings)) {
    die("Please add OAI-PMH settings to oai.ini.\n");
}
date_default_timezone_set($configArray['Site']['timezone']);

// If first command line parameter is set, see if we can limit to just the
// specified OAI harvester:
if (isset($argv[1])) {
    if (isset($oaiSettings[$argv[1]])) {
        $oaiSettings = array($argv[1] => $oaiSettings[$argv[1]]);
    } else {
        die("Could not load settings for {$argv[1]}.\n");
    }
}

// Loop through all the settings and perform harvests:
$processed = 0;
foreach ($oaiSettings as $target => $settings) {
    if (!empty($target) && !empty($settings)) {
        echo "Processing {$target}...\n";
        $harvest = new HarvestOAI($target, $settings);
        $harvest->launch();
        $processed++;
    }
}

// All done.
die("Completed without errors -- {$processed} source(s) processed.\n");

/**
 * HarvestOAI Class
 *
 * This class harvests records via OAI-PMH using settings from oai.ini.
 *
 * @category VuFind
 * @package  Harvest_Tools
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/importing_records#oai-pmh_harvesting Wiki
 */
class HarvestOAI
{
    private $_baseURL;               // URL to harvest from
    private $_set = null;            // Target set to harvest (null for all records)
    private $_metadata = 'oai_dc';   // Metadata type to harvest
    private $_idPrefix = '';         // OAI prefix to strip from ID values
    private $_idSearch = array();    // Regular expression searches
    private $_idReplace = array();   // Replacements for regular expression matches
    private $_basePath;              // Directory for storing harvested files
    private $_lastHarvestFile;       // File for tracking last harvest date
    private $_startDate = null;      // Harvest start date (null for all records)
    private $_granularity = 'auto';  // Date granularity
    private $_injectId = false;      // Tag to use for injecting IDs into XML
    private $_injectSetSpec = false; // Tag to use for injecting setSpecs
    private $_injectSetName = false; // Tag to use for injecting set names
    private $_injectDate = false;    // Tag to use for injecting datestamp
    private $_setNames = array();    // Associative array of setSpec => setName
    private $_harvestedIdLog = false;// Filename for logging harvested IDs.
    private $_verbose = false;       // Should we display debug output?

    // As we harvest records, we want to track the most recent date encountered
    // so we can set a start point for the next harvest.
    private $_endDate = 0;

    /**
     * Constructor.
     *
     * @param string $target   Target directory for harvest.
     * @param array  $settings OAI-PMH settings from oai.ini.
     *
     * @access public
     */
    public function __construct($target, $settings)
    {
        global $configArray;

        // Don't time out during harvest!!
        set_time_limit(0);

        // Set up base directory for harvested files:
        $this->_setBasePath($target);

        // Check if there is a file containing a start date:
        $this->_lastHarvestFile = $this->_basePath . 'last_harvest.txt';
        $this->_loadLastHarvestedDate();

        // Set up base URL:
        if (empty($settings['url'])) {
            die("Missing base URL for {$target}.\n");
        }
        $this->_baseURL = $settings['url'];
        if (isset($settings['set'])) {
            $this->_set = $settings['set'];
        }
        if (isset($settings['metadataPrefix'])) {
            $this->_metadata = $settings['metadataPrefix'];
        }
        if (isset($settings['idPrefix'])) {
            $this->_idPrefix = $settings['idPrefix'];
        }
        if (isset($settings['idSearch'])) {
            $this->_idSearch = $settings['idSearch'];
        }
        if (isset($settings['idReplace'])) {
            $this->_idReplace = $settings['idReplace'];
        }
        if (isset($settings['harvestedIdLog'])) {
            $this->_harvestedIdLog = $settings['harvestedIdLog'];
        }
        if (isset($settings['injectId'])) {
            $this->_injectId = $settings['injectId'];
        }
        if (isset($settings['injectSetSpec'])) {
            $this->_injectSetSpec = $settings['injectSetSpec'];
        }
        if (isset($settings['injectSetName'])) {
            $this->_injectSetName = $settings['injectSetName'];
            $this->_loadSetNames();
        }
        if (isset($settings['injectDate'])) {
            $this->_injectDate = $settings['injectDate'];
        }
        if (isset($settings['dateGranularity'])) {
            $this->_granularity = $settings['dateGranularity'];
        }
        if (isset($settings['verbose'])) {
            $this->_verbose = $settings['verbose'];
        }
        if ($this->_granularity == 'auto') {
            $this->_loadGranularity();
        }
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
        // Start harvesting at the requested date:
        $token = $this->_getRecordsByDate($this->_startDate, $this->_set);

        // Keep harvesting as long as a resumption token is provided:
        while ($token !== false) {
            $token = $this->_getRecordsByToken($token);
        }
    }

    /**
     * Set up directory structure for harvesting (support method for constructor).
     *
     * @param string $target The OAI-PMH target directory to create.
     *
     * @return void
     * @access private
     */
    private function _setBasePath($target)
    {
        // Get the base VuFind path:
        $home = getenv('VUFIND_HOME');
        if (empty($home)) {
            die("Please set the VUFIND_HOME environment variable.\n");
        }

        // Build the full harvest path:
        $this->_basePath = $home . '/harvest/' . $target . '/';

        // Create the directory if it does not already exist:
        if (!is_dir($this->_basePath)) {
            if (!mkdir($this->_basePath)) {
                die("Problem creating directory {$this->_basePath}.\n");
            }
        }
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
     * Normalize a date to a Unix timestamp.
     *
     * @param string $date Date (ISO-8601 or YYYY-MM-DD HH:MM:SS)
     *
     * @return integer     Unix timestamp (or false if $date invalid)
     * @access protected
     */
    protected function normalizeDate($date)
    {
        // Remove timezone markers -- we don't want PHP to outsmart us by adjusting
        // the time zone!
        $date = str_replace(array('T', 'Z'), array(' ', ''), $date);

        // Translate to a timestamp:
        return strtotime($date);
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
     * Make an OAI-PMH request.  Die if there is an error; return a SimpleXML object
     * on success.
     *
     * @param string $verb   OAI-PMH verb to execute.
     * @param array  $params GET parameters for ListRecords method.
     *
     * @return object        SimpleXML-formatted response.
     * @access private
     */
    private function _sendRequest($verb, $params = array())
    {
        // Debug:
        if ($this->_verbose) {
            echo "Sending request: verb = {$verb}, params = ";
            print_r($params);
        }

        // Set up the request:
        $request = new Proxy_Request();
        $request->setMethod(HTTP_REQUEST_METHOD_GET);
        $request->setURL($this->_baseURL);

        // Load request parameters:
        $request->addQueryString('verb', $verb);
        foreach ($params as $key => $value) {
            $request->addQueryString($key, $value);
        }

        // Perform request and die on error:
        $result = $request->sendRequest();
        if (PEAR::isError($result)) {
            die($result->getMessage() . "\n");
        }

        // If we got this far, there was no error -- send back response.
        $response = $request->getResponseBody();
        return $this->_processResponse($response);
    }

    /**
     * Process an OAI-PMH response into a SimpleXML object.  Die if an error is
     * detected.
     *
     * @param string $xml OAI-PMH response XML.
     *
     * @return object     SimpleXML-formatted response.
     * @access private
     */
    private function _processResponse($xml)
    {
        // Parse the XML:
        $result = simplexml_load_string($xml);
        if (!$result) {
            die("Problem loading XML: {$xml}\n");
        }

        // Detect errors and die if one is found:
        if ($result->error) {
            $attribs = $result->error->attributes();
            die(
                "OAI-PMH error -- code: {$attribs['code']}, " .
                "value: {$result->error}\n"
            );
        }

        // If we got this far, we have a valid response:
        return $result;
    }

    /**
     * Get the filename for a specific record ID.
     *
     * @param string $id  ID of record to save.
     * @param string $ext File extension to use.
     *
     * @return string     Full path + filename.
     * @access private
     */
    private function _getFilename($id, $ext)
    {
        return $this->_basePath . time() . '_' . 
            preg_replace('/[^\w]/', '_', $id) . '.' . $ext;
    }

    /**
     * Create a tracking file to record the deletion of a record.
     *
     * @param string $id ID of deleted record.
     *
     * @return void
     * @access private
     */
    private function _saveDeletedRecord($id)
    {
        $filename = $this->_getFilename($id, 'delete');
        file_put_contents($filename, $id);
    }

    /**
     * Save a record to disk.
     *
     * @param string $id     ID of record to save.
     * @param object $record Record to save (in SimpleXML format).
     *
     * @return void
     * @access private
     */
    private function _saveRecord($id, $record)
    {
        if (!isset($record->metadata)) {
            die("Unexpected missing record metadata.\n");
        }

        // Extract the actual metadata from inside the <metadata></metadata> tags;
        // there is probably a cleaner way to do this, but this simple method avoids
        // the complexity of dealing with namespaces in SimpleXML:
        $xml = trim($record->metadata->asXML());
        $xml = preg_replace('/(^<metadata>)|(<\/metadata>$)/m', '', $xml);

        // If we are supposed to inject any values, do so now inside the first
        // tag of the file:
        $insert = '';
        if (!empty($this->_injectId)) {
            $insert .= "<{$this->_injectId}>" . htmlspecialchars($id) .
                "</{$this->_injectId}>";
        }
        if (!empty($this->_injectDate)) {
            $insert .= "<{$this->_injectDate}>" .
                htmlspecialchars((string)$record->header->datestamp) .
                "</{$this->_injectDate}>";
        }
        if (!empty($this->_injectSetSpec)) {
            if (isset($record->header->setSpec)) {
                foreach ($record->header->setSpec as $current) {
                    $insert .= "<{$this->_injectSetSpec}>" .
                        htmlspecialchars((string)$current) .
                        "</{$this->_injectSetSpec}>";
                }
            }
        }
        if (!empty($this->_injectSetName)) {
            if (isset($record->header->setSpec)) {
                foreach ($record->header->setSpec as $current) {
                    $name = $this->_setNames[(string)$current];
                    $insert .= "<{$this->_injectSetName}>" .
                        htmlspecialchars($name) .
                        "</{$this->_injectSetName}>";
                }
            }
        }
        if (!empty($insert)) {
            $xml = preg_replace('/>/', '>' . $insert, $xml, 1);
        }

        // Save our XML:
        file_put_contents($this->_getFilename($id, 'xml'), trim($xml));
    }

    /**
     * Load date granularity from the server.
     *
     * @return void
     * @access private
     */
    private function _loadGranularity()
    {
        echo "Autodetecting date granularity... ";
        $response = $this->_sendRequest('Identify');
        $this->_granularity = (string)$response->Identify->granularity;
        echo "found {$this->_granularity}.\n";
    }

    /**
     * Load set list from the server.
     *
     * @return void
     * @access private
     */
    private function _loadSetNames()
    {
        echo "Loading set list... ";

        // On the first pass through the following loop, we want to get the
        // first page of sets without using a resumption token:
        $params = array();

        // Grab set information until we have it all (at which point we will
        // break out of this otherwise-infinite loop):
        while (true) {
            // Process current page of results:
            $response = $this->_sendRequest('ListSets', $params);
            if (isset($response->ListSets->set)) {
                foreach ($response->ListSets->set as $current) {
                    $spec = (string)$current->setSpec;
                    $name = (string)$current->setName;
                    if (!empty($spec)) {
                        $this->_setNames[$spec] = $name;
                    }
                }
            }

            // Is there a resumption token?  If so, continue looping; if not,
            // we're done!
            if (isset($response->ListSets->resumptionToken)
                && !empty($response->ListSets->resumptionToken)
            ) {
                $params['resumptionToken']
                    = (string)$response->ListSets->resumptionToken;
            } else {
                echo "found " . count($this->_setNames) . "\n";
                return;
            }
        }
    }

    /**
     * Extract the ID from a record object (support method for _processRecords()).
     *
     * @param object $record SimpleXML record.
     *
     * @return string        The ID value.
     * @access private
     */
    private function _extractID($record)
    {
        // Normalize to string:
        $id = (string)$record->header->identifier;

        // Strip prefix if found:
        if (substr($id, 0, strlen($this->_idPrefix)) == $this->_idPrefix) {
            $id = substr($id, strlen($this->_idPrefix));
        }

        // Apply regular expression matching:
        if (!empty($this->_idSearch)) {
            $id = preg_replace($this->_idSearch, $this->_idReplace, $id);
        }

        // Return final value:
        return $id;
    }

    /**
     * Save harvested records to disk and track the end date.
     *
     * @param object $records SimpleXML records.
     *
     * @return void
     * @access private
     */
    private function _processRecords($records)
    {
        echo 'Processing ' . count($records) . " records...\n";

        // Array for tracking successfully harvested IDs:
        $harvestedIds = array();

        // Loop through the records:
        foreach ($records as $record) {
            // Die if the record is missing its header:
            if (empty($record->header)) {
                die("Unexpected missing record header.\n");
            }

            // Get the ID of the current record:
            $id = $this->_extractID($record);

            // Save the current record, either as a deleted or as a regular file:
            $attribs = $record->header->attributes();
            if (strtolower($attribs['status']) == 'deleted') {
                $this->_saveDeletedRecord($id);
            } else {
                $this->_saveRecord($id, $record);
                $harvestedIds[] = $id;
            }

            // If the current record's date is newer than the previous end date,
            // remember it for future reference:
            $date = $this->normalizeDate($record->header->datestamp);
            if ($date && $date > $this->_endDate) {
                $this->_endDate = $date;
            }
        }

        // Do we have IDs to log and a log filename?  If so, log them:
        if (!empty($this->_harvestedIdLog) && !empty($harvestedIds)) {
            $file = fopen($this->_basePath . $this->_harvestedIdLog, 'a')
                or die ("Problem opening {$this->_harvestedIdLog}.\n");
            fputs($file, implode(PHP_EOL, $harvestedIds));
            fclose($file);
        }
    }

    /**
     * Harvest records using OAI-PMH.
     *
     * @param array $params GET parameters for ListRecords method.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access private
     */
    private function _getRecords($params)
    {
        // Make the OAI-PMH request:
        $response = $this->_sendRequest('ListRecords', $params);

        // Save the records from the response:
        if ($response->ListRecords->record) {
            $this->_processRecords($response->ListRecords->record);
        }

        // If we have a resumption token, keep going; otherwise, we're done -- save
        // the end date.
        if (isset($response->ListRecords->resumptionToken)
            && !empty($response->ListRecords->resumptionToken)
        ) {
            return $response->ListRecords->resumptionToken;
        } else if ($this->_endDate > 0) {
            $dateFormat = ($this->_granularity == 'YYYY-MM-DD') ?
                'Y-m-d' : 'Y-m-d\TH:i:s\Z';
            $this->_saveLastHarvestedDate(date($dateFormat, $this->_endDate));
        }
        return false;
    }

    /**
     * Harvest records via OAI-PMH using date and set.
     *
     * @param string $date Harvest start date (null for all records).
     * @param string $set  Set to harvest (null for all records).
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access private
     */
    private function _getRecordsByDate($date = null, $set = null)
    {
        $params = array('metadataPrefix' => $this->_metadata);
        if (!empty($date)) {
            $params['from'] = $date;
        }
        if (!empty($set)) {
            $params['set'] = $set;
        }
        return $this->_getRecords($params);
    }

    /**
     * Harvest records via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access private
     */
    private function _getRecordsByToken($token)
    {
        return $this->_getRecords(array('resumptionToken' => (string)$token));
    }
}


?>