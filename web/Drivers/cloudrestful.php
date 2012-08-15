<?php 

require_once 'cloud.php';

class cloudrestful extends cloud
{
    /** where
     * Constructor
     *
     * @param string $configFile Name of configuration file to load (relative to
     * web/conf folder; defaults to VoyagerRestful.ini).
     *
     * @access public
     */
    public function __construct($configFile = 'cloudRestful.ini')
    {
        // Call the parent's constructor...
        parent::__construct($configFile);
        // Define Voyager Restful Settings

        $aArray =  $this->config;
//        error_log(print_r($aArray, true), 3, 'cloudRestful.log');
        $this->ws_host = $this->config['WebServices']['host'];
        $this->host   = $this->config['Catalog']['host'];
        $this->ws_port = $this->config['WebServices']['port'];
//        $this->ws_app = $this->config['WebServices']['app'];

//        error_log(__FILE__ . " line " . __LINE__ . ' inside cloud restful ws app ' . $this->ws_app ,0);
        $this->ws_dbKey = $this->config['WebServices']['dbKey'];
        $this->ws_patronHomeUbId = $this->config['WebServices']['patronHomeUbId'];
        $this->ws_pickUpLocations
            = (isset($this->config['pickUpLocations']))
            ? $this->config['pickUpLocations'] : false;
        $this->defaultPickUpLocation
            = $this->config['Holds']['defaultPickUpLocation'];
    }

    /**
     * Public Function which retrieves renew, hold and cancel settings from the
     * driver ini file.
     *
     * @param string $function The name of the feature to be checked
     *
     * @return array An array with key-value pairs.
     * @access public
     */
    public function getConfig($function)
    {
       // $aArray = $function ;
       // error_log(print_r($aArray, true), 3, 'function.log');
       // error_log(__FILE__ . " line " . __LINE__ . ' inside cloud restful getconfig ' . $function ,0);
        if (isset($this->config[$function]) ) {
            $functionConfig = $this->config[$function];
        } else {
            $functionConfig = false;
        }
        return $functionConfig;
    }

    /**
     * Private support method for VuFind Hold Logic. Take an array of status strings
     * and determines whether or not an item is holdable based on the
     * valid_hold_statuses settings in configuration file
     *
     * @param array $statusArray The status codes to analyze.
     *
     * @return bool Whether an item is holdable
     * @access private
     */
    private function _isHoldable($statusArray)
    {
        // User defined hold behaviour
        $is_holdable = true;

        if (isset($this->config['Holds']['valid_hold_statuses'])) {
            $valid_hold_statuses_array
                = explode(":", $this->config['Holds']['valid_hold_statuses']);

            if (count($valid_hold_statuses_array > 0)) {
                foreach ($statusArray as $status) {
                    if (!in_array($status, $valid_hold_statuses_array)) {
                        $is_holdable = false;
                    }
                }
            }
        }
        return $is_holdable;
    }

    /**
     * Private support method for VuFind Hold Logic. Takes an item type id
     * and determines whether or not an item is borrowable based on the
     * non_borrowable settings in configuration file
     *
     * @param string $itemTypeID The item type id to analyze.
     *
     * @return bool Whether an item is borrowable
     * @access private
     */
    private function _isBorrowable($itemTypeID)
    {
   //      error_log(__FILE__ . " line " . __LINE__ . ' inside cloud restful _isBorrowable ' . $itemTypeID ,0);
        $is_borrowable = true;
        if (isset($this->config['Holds']['non_borrowable'])) {
            $non_borrow = explode(":", $this->config['Holds']['non_borrowable']);
            if (in_array($itemTypeID, $non_borrow)) {
                $is_borrowable = false;
            }
        }

        return $is_borrowable;
    }

    /**
     * Protected support method for getHolding.
     *
     * @param array $id A Bibliographic id
     *
     * @return array Keyed data for use in an sql query
     * @access protected
     */
    protected function getHoldingItemsSQL($id)
    {
   // error_log(__FILE__ . " line " . __LINE__ . ' inside cloud restful getHoldingItemsSQL ' . $id ,0);
        $sqlArray = parent::getHoldingItemsSQL($id);
        $sqlArray['expressions'][] = "ITEM.ITEM_TYPE_ID";

        return $sqlArray;
    }

    /**
     * Protected support method for getHolding.
     *
     * @param array $sqlRow SQL Row Data
     *
     * @return array Keyed data
     * @access protected
     */
    protected function processHoldingRow($sqlRow)
    {
        $row = parent::processHoldingRow($sqlRow);
        $row += array('item_id' => $sqlRow['ITEM_ID'], '_fullRow' => $sqlRow);
        return $row;
    }

    /**
     * Protected support method for getHolding.
     *
     * @param array $data   Item Data
     * @param mixed $patron Patron Data or boolean false
     *
     * @return array Keyed data
     * @access protected
     */

    protected function processHoldingData($data, $patron = false)
    {
        $holding = parent::processHoldingData($data, $patron);

        foreach ($holding as $i => $row) {
            $is_borrowable = $this->_isBorrowable($row['_fullRow']['ITEM_TYPE_ID']);
            $is_holdable = $this->_isHoldable($row['_fullRow']['STATUS_ARRAY']);
            // If the item cannot be borrowed or if the item is not holdable,
            // set is_holdable to false
            if (!$is_borrowable || !$is_holdable) {
                $is_holdable = false;
            }

            // Only used for driver generated hold links
            $addLink = false;

            // Hold Type - If we have patron data, we can use it to dermine if a
            // hold link should be shown
            if ($patron) {
                $holdType = $this->_determineHoldType(
                    $row['id'], $row['item_id'], $patron['id']
                );
                $addLink = $holdType ? $holdType : false;
            } else {
                $holdType = "auto";
            }

            $holding[$i] += array(
                'is_holdable' => $is_holdable,
                'holdtype' => $holdType,
                'addLink' => $addLink
            );
            unset($holding[$i]['_fullRow']);
        }
        return $holding;
    }

    /**
     * Determine Renewability
     *
     * This is responsible for determining if an item is renewable
     *
     * @param string $patronId The user's patron ID
     * @param string $itemId   The Item Id of item
     *
     * @return mixed Array of the renewability status and associated
     * message
     * @access private
     */

    private function _isRenewable($patronId, $itemId)
    {
        // Build Hierarchy
        $procrequest = 'isrenewable';
        $user = UserAccount::isLoggedIn();
        $home_library = $user->home_library;
	
	// error_log(__FILE__ . " line " . __LINE__ . ' inside cloud restful _isRenewable procrequest ' . $procrequest ,0);
        // Add Required Params
        $params = array(
            "ORG" => $home_library,
            "PATRON_ID" => $patronId,
            "ITEM_ID"   => $itemId
        );
        $renewability = $this->_makeRequest($procrequest, $params, "GET");
        $renewability = $renewability->children();
        $node = "reply-text";
        $reply = (string)$renewability->$node;
        if ($reply == "ok") {
            $loanAttributes = $renewability->resource->loan->attributes();
            $canRenew = (string)$loanAttributes['canRenew'];
            if ($canRenew == "Y") {
                $renewData['message'] = false;
                $renewData['renewable'] = true;
            } else {
                $renewData['message'] = "renew_item_no";
                $renewData['renewable'] = false;
            }
        } else {
            $renewData['message'] = "renew_determine_fail";
            $renewData['renewable'] = false;
        }
        return $renewData;
    }

    /**
     * Protected support method for getMyTransactions.
     *
     * @param array $sqlRow An array of keyed data
     * @param array $patron An array of keyed patron data
     *
     * @return array Keyed data for display by template files
     * @access protected
     */
    protected function processMyTransactionsData($sqlRow, $patron)
    {
        $transactions = parent::processMyTransactionsData($sqlRow, $patron);

        $renewData = $this->_isRenewable($patron['id'], $transactions['item_id']);
        $transactions['renewable'] = $renewData['renewable'];
        $transactions['message'] = $renewData['message'];

        return $transactions;
    }

     /**
     * Get Pick Up Locations
     *
     * This is responsible for gettting a list of valid library locations for
     * holds / recall retrieval
     *
     * @param array $patron Patron information returned by the patronLogin method.
     *
     * @return array        An keyed array where libray id => Library Display Name
     * @access public
     */
    public function getPickUpLocations($patron = false)
    {

        $aArray = $patron ;
        error_log(print_r($aArray, true), 3, '/tmp/gpatron.log');

        if ($this->ws_pickUpLocations) {
            foreach ($this->ws_pickUpLocations as $code => $library) {
                $pickResponse[] = array(
                    'locationID' => $code,
                    'locationDisplay' => $library
                );
            }
        }
        else {
            $sql = "SELECT ORG, BRANCHNAME FROM " .
                $this->dbName . ".my_library " .
                "where ORG !=0 ";
     error_log(__FILE__ . " line " . __LINE__ . ' inside cloud restful getPickUpLocations ' . $sql ,0);
            try {
                $sqlStmt = $this->db->prepare($sql);
     error_log(__FILE__ . " line " . __LINE__ . ' inside cloud restful getPickUpLocations ' . $sqlStmt ,0);

                $sqlStmt->execute();
            } catch (PDOException $e) {
                return new PEAR_Error($e->getMessage());
            }

            // Read results
            while ($row = $sqlStmt->fetch(PDO::FETCH_ASSOC)) {
                $pickResponse[] = array(
                    "locationID" => $row['ORG'],
                    "locationDisplay" => $row['BRANCHNAME']
                );
            }
        }

        return $pickResponse;
    }

    /**
     * Get Default Pick Up Location
     *
     * Returns the default pick up location set in VoyagerRestful.ini
     *
     * @param array $patron Patron information returned by the patronLogin method.
     *
     * @return array        An keyed array where libray id => Library Display Name
     * @access public
     */
    public function getDefaultPickUpLocation($patron = false)
    {
        return $this->defaultPickUpLocation;
    }

     /**
     * Make Request
     *
     * Makes a request to the cloud Restful API
     *
     * @param string  $procrequest string of name of procedure to call
     * the request (set value to false to inject a non-paired value).
     * @param array  $params    A keyed array of query data
     * @param string $mode      The http request method to use (Default of GET)
     * @param string $xml       An optional XML string to send to the API
     *
     * @return obj  A Simple XML Object loaded with the xml data returned by the API
     * @access private
     */
    private function _makeRequest($procrequest, $params = false, $mode = "GET",$xml = false) {
//       $urlParams = "http://{$this->ws_host}:{$this->ws_port}/$procrequest";
       $urlParams = "http://{$this->ws_host}:{$this->ws_port}/" .$procrequest;
 
//       error_log(__FILE__ . " line " . __LINE__ . ' _makeRequest mode=' . $mode .' $urlParams=' . $urlParams  ,0);
       $aArray = $params;
       error_log(print_r($aArray, true), 3, '/tmp/params.log');
//       error_log(__FILE__ . " line " . __LINE__ . ' _makeRequest function $xml=' . $xml  ,0);

        foreach ($params as $key => $param) {
            $queryString[] = $key. "=" . urlencode($param);
        }
//      error_log(__FILE__ . " line " . __LINE__ . ' _makeRequest mode=' . $mode .' $urlParams=' . $urlParams  ,0);
//        $client = new Proxy_Request($urlParams);
        if ($mode == "POST") {
            $header = "Content-type: text/xml \r\n";
            $header .= "Content-length: ".strlen($xml)." \r\n";
            $header .= "Content-transfer-encoding: text \r\n";
            $header .= "Connection: close \r\n\r\n";
            $context = stream_context_create(array(
             'http' => array(
             'method' => 'POST',
             'header' => $header,                                   //'Content-Type: application/xml',
             'content' => $xml
             )
             ));
             $xmlResponse = file_get_contents($urlParams, false, $context);
//             error_log(__FILE__ . " line " . __LINE__ . ' _makeRequest $xmlResponse=' . $xmlResponse ,0);
        } else {
            $urlParams .= "?" . implode("&", $queryString);
//            error_log(__FILE__ . " line " . __LINE__ . ' _makeRequest $urlParams=' . $urlParams ,0);
            $client = new Proxy_Request($urlParams);
            $client->setMethod(HTTP_REQUEST_METHOD_GET);
            $client->sendRequest();
            $xmlResponse = $client->getResponseBody();
        }

        $oldLibXML = libxml_use_internal_errors();
        libxml_use_internal_errors(true);
        $simpleXML = simplexml_load_string($xmlResponse);
        $aArray = $simpleXML;
        error_log(print_r($aArray, true), 3, '/tmp/_makeRequestxmlresponse.log');
        libxml_use_internal_errors($oldLibXML);
        if ($simpleXML === false) {
            return false;
        }

        return $simpleXML;
    }

    /**
     * Check Account Blocks
     * Checks if a user has any blocks against their account which may prevent them
     * performing certain operations
     * @param string $patronId A Patron ID
     * @return mixed           A boolean false if no blocks are in place and an array
     * of block reasons if blocks are in place
     * @access private
     */
    private function _checkAccountBlocks($patronId)
    {
        error_log(__FILE__ . " line " . __LINE__ . ' _checkAccountBlocks $patronId=' . $patronId,0);
        $blockReason = false;
       	    $user = UserAccount::isLoggedIn();
	    $home_library = $user->home_library;
	 // Build Hierarchy
        $procrequest = 'checkaccountblocks';
            $params = array(
                "RTYPE"          => 'BLOCKS',
                "ORG"            => $home_library,
                "PATRON_NUM"     => $patronId
            );
        $result = $this->_makeRequest($procrequest, $params);	
	error_log(print_r($result, true), 3, '/tmp/_checaccountblocs.log');
            if ($result) {
                $node  = $result->children();
                $reply = $node[0]->replytext;
                $note  = (string)$node[0]->note;  //$patronId = $renewDetails['patron'][0]['pat_id']; // was 'id'
		$note2 = (string)$node[0]->note2;
                // Valid Response
                if ($reply == "ok" && $note == "good") {
		
		   // $blockReason = array();    // Note If I want to block and send why I would do it here.
		   
		   // $blockReason[] = $note  ;  // this gets sent as an xml object ???? (string)$cancel->$node;
		   // $blockReason[] = $note2 ;  // this gets sent as an xml object ????	    
                }
            }	
        return $blockReason;
    }
    
    /**
     * Renew My Items
     *
     * Function for attempting to renew a patron's items.  The data in
     * $renewDetails['details'] is determined by getRenewDetails().
     *
     * @param array $renewDetails An array of data required for renewing items
     * including the Patron ID and an array of renewal IDS
     *
     * @return array              An array of renewal information keyed by item ID
     * @access public
     */
    public function renewMyItems($renewDetails)
    {
        $aArray = $renewDetails ;
        error_log(print_r($aArray, true), 3, '/tmp/renewmyitems1.log');
        $renewProcessed = array();
        $renewResult = array();
        $failIDs = array();
        $patronId = $renewDetails['patron'][0]['pat_id']; // was 'id'
	
	$user = UserAccount::isLoggedIn();
	$home_library = $user->home_library;
	
	
  //  error_log(__FILE__ . " line " . __LINE__ . ' renewMyItems $patronId=' . $patronId,0);
	
        // Get Account Blocks
       $finalResult['blocks'] = $this->_checkAccountBlocks($patronId);
        error_log(print_r($finalResult, true), 3, '/tmp/renewmyitems2.log');		

        if ($finalResult['blocks'] === false) {
            // Add Items and Attempt Renewal
	//    error_log(__FILE__ . " line " . __LINE__ . ' in the if === fasle ' . $patronId,0);    
            foreach ($renewDetails['details'] as $renewID) {
                // Build an array of item ids which may be of use in the template
                // file
                $failIDs[$renewID] = "";
              

                // Create Rest API Renewal Key
                $restRenewID = $this->ws_dbKey. "|" . $renewID;

            //    $procrequest[$restRenewID] = false;
		

             //   error_log(__FILE__ . " line " . __LINE__ . ' $renewID=' . $renewID,0);
		
            $procrequest = 'renew_item';
             // Add Required Params
	     $renew = 'REQUESTRENEW';
            $params = array(
                "RTYPE"     => $renew,
                "PATRON_ID" => $patronId,
                "ORG"    => $home_library,
                "ITEM_ID"   => $renewID
            );				
                // Attempt Renewal
                $renewalObj = $this->_makeRequest($procrequest, $params);
		
                error_log(print_r($renewalObj, true), 3, '/tmp/renewalObj.log');
		
                $process = $this->_processRenewals($renewalObj);
                if (PEAR::isError($process)) {
                    return $process;
                }
                // Process Renewal
                $renewProcessed[] = $process;
            }

            // Place Successfully processed renewals in the details array
            foreach ($renewProcessed as $renewal) {
                if ($renewal !== false) {
                    $finalResult['details'][$renewal['item_id']] = $renewal;
                    unset($failIDs[$renewal['item_id']]);
                }
            }
            // Deal with unsuccessful results
            foreach ($failIDs as $id => $junk) {
                $finalResult['details'][$id] = array(
                    "success" => false,
                    "new_date" => false,
                    "item_id" => $id,
                    "sysMessage" => ""
                );
            }
        }
        return $finalResult;
    }

    /**
     * Process Renewals
     *
     * A support method of renewMyItems which determines if the renewal attempt
     * was successful
     *
     * @param object $renewalObj A simpleXML object loaded with renewal data
     *
     * @return array             An array with the item id, success, new date (if
     * available) and system message (if available)
     * @access private
     */
    private function _processRenewals($renewalObj)
    {
        $aArray = $renewalObj ;
        error_log(print_r($aArray, true), 3, '/tmp/_processrenewals.log');
        // Not Sure Why, but necessary!
        $node = $renewalObj->children();

	$reply = (string)$node[0]->note;
	
        // Valid Response
        if ($reply == "ok") {

            error_log(__FILE__ . " line " . __LINE__ . ' reply=' . $reply,0);
	    
	    $itemId                 = (string)$node[0]->id;
	    $renewalStatus          = (string)$node[0]->status;
	    $dueDate                = (string)$node[0]->duedate;
	    $response['item_id']    = $itemId;
	    $response['sysMessage'] = $renewalStatus ;
	    
	    
	    error_log(__FILE__ . " line " . __LINE__ . ' $itemId=' . $itemId . ' $renewalStatus='. $renewalStatus . ' $dueDate' . $dueDate,0);
	    
	    
            if ($renewalStatus == "Success") {
 
                if (!empty($dueDate)) {
                    // Convert Voyager Format to display format
                    $newDate = $this->dateFormat->convertToDisplayDate(
                        "Y-m-d H:i", $dueDate
                    );
                    $newTime = $this->dateFormat->convertToDisplayTime(
                        "Y-m-d H:i", $dueDate
                    );
                    if (!PEAR::isError($newDate)) {
                        $response['new_date'] = $newDate;
                    }
                 //   if (!PEAR::isError($newTime)) {
                 //       $response['new_time'] = $newTime;
                 //   }
                }
                $response['success'] = true;
            } else {
                $response['success'] = false;
                $response['new_date'] = false;
                $response['new_time'] = false;
            }

            return $response;
        } else {
            // System Error
            return false;
        }
    }

    /**
     * Check Item Requests
     *
     * Determines if a user can place a hold or recall on a specific item
     *
     * @param string $bibId    An item's Bib ID
     * @param string $patronId The user's Patron ID
     * @param string $request  The request type (hold or recall)
     * @param string $itemId   An item's Item ID (optional)
     *
     * @return boolean         true if the request can be made, false if it cannot
     * @access private
     */
    private function _checkItemRequests($bibId, $patronId, $request, $itemId = false)
    {  
//    error_log(__FILE__ . " line " . __LINE__ . ' _checkItemRequests $bibId=' . $bibId . ' $patronId=' . $patronId . ' $request=' . $request . ' $itemId=' . $itemId ,0);
        if (!empty($bibId) && !empty($patronId) && !empty($request) ) {
            $procrequest = 'checkitemrequest';
             // Add Required Params
	    $user = UserAccount::isLoggedIn();
	    $home_library = $user->home_library;
            $params = array(
                "RTYPE"     => $request,
                "PATRON_ID" => $patronId,
                "homelib"   => $home_library,
                "BIB_ID"    => $bibId,
                "ITEM_ID"   => $itemId
            );
	    error_log(print_r($params, true), 3, '/tmp/_checkItemRequests1.xml');
            $result = $this->_makeRequest($procrequest, $params, "GET", false);
            error_log(print_r($result, true), 3, '/tmp/_checkItemRequests2.xml');
	    
            if ($result) {
                // Process
                $node = $result->children();
                $reply = $node[0]->replytext;
                $note = $node[0]->note;
                // Valid Response
                if ($reply == "ok" && $note == "good") {
                   // $response['success'] = true;
                   // $response['status'] = "hold_success";
		    return true;
                }
            }
        }
        return false;
    }

    /**
     * Make Item Requests
     * Places a Hold or Recall for a particular item
     * @param string $bibId       An item's Bib ID
     * @param string $patronId    The user's Patron ID
     * @param string $request     The request type (hold or recall)
     * @param array  $requestData An array of data to submit with the request,
     * may include comment, lastInterestDate and pickUpLocation
     * @param string $itemId      An item's Item ID (optional)
     * @return array             An array of data from the attempted request
     * including success, status and a System Message (if available)
     * @access private
     */
    private function _makeItemRequests($bibId, $patronId, $request,$requestData, $itemId = false) {
        $response = array('success' => false, 'status' =>"hold_error_fail");
   //  error_log(__FILE__ . " line " . __LINE__ . ' _makeItemRequests $bibId=' . $bibId . ' $patronId=' . $patronId . ' $request=' . $request . ' $itemId=' . $itemId ,0);
        if (!empty($bibId) && !empty($patronId) && !empty($requestData)
            && !empty($request)
        ) {
	
        $user = UserAccount::isLoggedIn();
	$home_library = $user->home_library;

            $procrequest = 'request_rsv' ;
            // Add Required Params
            $params = array(
                "patron" => $patronId,
                "patron_homedb" => $home_library,
                "view" => "full"
            );

$xmltopost = "<?xml version='1.0'?>
<group>
  <data>
    <requesttype>"         .
	  $request
    . "</requesttype>
    <pickuplocation>" .
       $requestData['pickupLocation']
    . "</pickuplocation>
    <lastinterestdate>"      .
       $requestData['lastInterestDate']
    . "</lastinterestdate>
    <comment>" .
       $requestData['comment']
    . "</comment>
    <org>" .
       $home_library
    . "</org>
   <patronid>" .
	   $patronId
    . "</patronid>
   <itemid>" .
	   $itemId
    . "</itemid>
   <bibid>" .
	   $bibId
    . "</bibid>
  </data>
</group>";

        $result = $this->_makeRequest($procrequest, $params, "POST", $xmltopost); // was PUT
//            $aArray = $result ;
//            error_log(print_r($aArray, true), 3, 'xmlr.log');
         
            if ($result) {
                // Process
                $node = $result->children();
                $reply = $node[0]->replytext;
                $note = $node[0]->note;
                // Valid Response
                if ($reply == "ok" && $note == "Your request was successful.") {
                    $response['success'] = true;
                    $response['status'] = "hold_success";
                } else {
                    // Failed
                    $response['sysMessage'] = $note;
                }
            }
        }
        return $response;
    }

    /**
     * Determine Hold Type
     * Determines if a user can place a hold or recall on a particular item
     * @param string $bibId    An item's Bib ID
     * @param string $itemId   An item's Item ID (optional)
     * @param string $patronId The user's Patron ID
     * @return string          The name of the request method to use or false on
     * failure
     * @access private
     */
    private function _determineHoldType($bibId, $itemId, $patronId)
    {
        // Check for account Blocks
        if ($this->_checkAccountBlocks($patronId)) {
            return "block";
        }
        // Check Recalls First
        $recall = $this->_checkItemRequests($bibId, $patronId, "recall", $itemId);
        if ($recall) {
            return "recall";
        } else {
            // Check Holds
            $hold = $this->_checkItemRequests($bibId, $patronId, "hold", $itemId);
            if ($hold) {
                return "hold";
            }
        }
        return false;
    }

    /**
     * Hold Error
     *
     * Returns a Hold Error Message
     *
     * @param string $msg An error message string
     *
     * @return array An array with a success (boolean) and sysMessage key
     * @access private
     */
    private function _holdError($msg)
    {
        return array(
                    "success" => false,
                    "sysMessage" => $msg
        );
    }

    /**
     * Place Hold
     *
     * Attempts to place a hold or recall on a particular item and returns
     * an array with result details or a PEAR error on failure of support classes
     *
     * @param array $holdDetails An array of item and patron data
     *
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available) or a
     * PEAR error on failure of support classes
     * @access public
     */
    public function placeHold($holdDetails)
    {  
//        $aArray = $holdDetails ;
//        error_log(print_r($aArray, true), 3, 'holdDetails.log');
        $patron = $holdDetails['patron'][0]['pat_id'];
        // $type =  $holdDetails['holdtype'];
        $type = $_GET['reserve'];

        $pickUpLocation = !empty($holdDetails['pickUpLocation'])
        ? $holdDetails['pickUpLocation'] : $this->defaultPickUpLocation;
    
        $itemId = $_GET['number'];
        // $itemId = $holdDetails['item_id'];  // was  $itemId = $holdDetails['item_id'];
        $comment = $holdDetails['comment'];
        $bibId = $holdDetails['id'];
//        error_log(__FILE__ . " line " . __LINE__ . ' placeHold patron=' . $patron . ' type=' . $type . ' pickUpLocation=' . $pickUpLocation . ' itemId=' . $itemId . ' bibId=' . $bibId,0);
        // Request was initiated before patron was logged in -
        //Let's determine Hold Type now
        if ($type == "auto") {
            $type = $this->_determineHoldType($bibId, $itemId, $patron);
            if (!$type || $type == "block") {
                error_log(__FILE__ . " line " . __LINE__ . ' inside1 placehold ' . $patron ,0);
                return $this->_holdError("hold_error_blocked");
            }
        }

        // Convert last interest date from Display Format to Voyager required format
        $lastInterestDate = $this->dateFormat->convertFromDisplayDate(
            "Y-m-d", $holdDetails['requiredBy']
        );
        if (PEAR::isError($lastInterestDate)) {
            // Hold Date is invalid
            return $this->_holdError("hold_date_invalid");
        }

        $checkTime =  $this->dateFormat->convertFromDisplayDate(
            "U", $holdDetails['requiredBy']
        );
        if (PEAR::isError($checkTime) || !is_numeric($checkTime)) {
            return $checkTime;
        }
        if (time() > $checkTime) {
            // Hold Date is in the past
            return $this->_holdError("hold_date_passed");
        }
        // Make Sure Pick Up Library is Valid
        $pickUpValid = false;
        $pickUpLibs = $this->getPickUpLocations();
        foreach ($pickUpLibs as $location) {
            if ($location['locationID'] == $pickUpLocation) {
                $pickUpValid = true;
            }
        }
        if (!$pickUpValid) {
            // Invalid Pick Up Point
            return $this->_holdError("hold_invalid_pickup");
        }
        // Build Request Data
        $requestData = array(
            'pickupLocation' => $pickUpLocation,
            'lastInterestDate' => $lastInterestDate,
            'comment' => $comment
        );
        if ($this->_checkItemRequests($bibId, $patron, $type, $itemId)) {
            // Attempt Request
            $result = $this->_makeItemRequests($bibId, $patron, $type, $requestData, $itemId);
            if ($result) {
                return $result;
            }
        }
      error_log(__FILE__ . " line " . __LINE__ . ' inside2 placehold ' . $patron ,0);
        return $this->_holdError("hold_error_blocked");
    }

    /**
     * Cancel Holds
     * Attempts to Cancel a hold or recall on a particular item. The
     * data in $cancelDetails['details'] is determined by getCancelHoldDetails().
     * @param array $cancelDetails An array of item and patron data
     * @return array               An array of data on each request including
     * whether or not it was successful and a system message (if available)
     * @access public
     */
    public function cancelHolds($cancelDetails)
    {
        $aArray =  $cancelDetails;
        error_log(print_r($aArray, true), 3, '/tmp/cancelDetails.log');
        $details  = $cancelDetails['details'];
        $patron   = $cancelDetails['patron'][0];
        $user = UserAccount::isLoggedIn();
        $home_library = $user->home_library;
    //  got patron and [zero] array deepness above
        $count    = 0;
        $response = array();
        foreach ($details as $cancelDetails) {
            list($itemId, $cancelCode) = explode("|", $cancelDetails);
            // Create Rest API Cancel Key
            // $cancelID = $this->ws_dbKey. "|" . $cancelCode;
            $cancelID =  $cancelCode;
            // Build Hierarchy
           $procrequest = 'request_rsv' ;
 
            // Add Required Params
            $params = array(
                "RTYPE"          => 'CANCEL',
                "ORG"            => $patron['home_library'],
                "PATRON_NUM"     => $patron['pat_id'],
                "TITLE_NUM"      => $itemId,
                "RSV_ID"         => $cancelID
            );
            error_log(__FILE__ . " line " . __LINE__ . ' cancelHolds cancelId=' . $cancelID . ' $itemId=' . $itemId . ' home_library='. $home_library  ,0);
            // Get Data
            // $cancel = $this->_makeRequest($procrequest, $params, "DELETE");
            $cancel = $this->_makeRequest($procrequest, $params, "GET");
            if ($cancel) {
                // Process Cancel
                $cancel = $cancel->children();
                $node = "reply-text";
                $reply = (string)$cancel->$node;
                $count = ($reply == "ok") ? $count+1 : $count;
                
                $response[$itemId] = array(
                    'success' => ($reply == "ok") ? true : false,
                    'status' => ($result[$itemId]['success'])
                        ? "hold_cancel_success" : "hold_cancel_fail",
                    'sysMessage' => ($reply == "ok") ? false : $reply,
                );
                
            } else {
                $response[$itemId] = array(
                    'success' => false, 'status' => "hold_cancel_fail"
                );
            }
        }
        $result = array('count' => $count, 'items' => $response);
        return $result;
    }

    /**
     * Get Cancel Hold Details
     * In order to cancel a hold, Voyager requires the patron details an item ID
     * and a recall ID. This function returns the item id and recall id as a string
     * separated by a pipe, which is then submitted as form data in Hold.php. This
     * value is then extracted by the CancelHolds function.
     * @param array $holdDetails An array of item data
     * @return string Data for use in a form field
     * @access public
     */
    public function getCancelHoldDetails($holdDetails)
    {
        $aArray = $holdDetails ;
        error_log(print_r($aArray, true), 3, '/tmp/holddetails.log');
// was  $cancelDetails = $holdDetails['item_id']."|".$holdDetails['reqnum'];
        $cancelDetails = $holdDetails['id']."|".$holdDetails['reqnum'];
        return $cancelDetails;
    }

    /**
     * Get Renew Details
     * In order to renew an item, Voyager requires the patron details and an item
     * id. This function returns the item id as a string which is then used
     * as submitted form data in checkedOut.php. This value is then extracted by
     * the RenewMyItems function.
     * @param array $checkOutDetails An array of item data
     * @return string Data for use in a form field
     * @access public
     */
    public function getRenewDetails($checkOutDetails)
    {
        $aArray = $checkOutDetails ;
        error_log(print_r($aArray, true), 3, '/tmp/checkOutDetails.log');
        $renewDetails = $checkOutDetails['id'];
        return $renewDetails;
    }
}

?>
