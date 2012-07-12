<?php

require_once 'Interface.php';
require_once 'sys/VuFindDate.php';
class cloud implements DriverInterface
{
    private   $port;
    protected $agency;
    protected $host;
    protected $config;
    protected $dateFormat;
    protected $db;
    protected $dbName;

    public function __construct($configFile = false)
//    function __construct()
    {
        if ($configFile) {
            // Load Configuration passed in
            $this->config = parse_ini_file('conf/'.$configFile, true);
        } else {
            // Hard Coded Configuration
            $this->config = parse_ini_file('conf/cloud.ini', true);
        }
        // Set up object for formatting dates and times:
        $this->dateFormat = new VuFindDate();

 $this->dbName = $this->config['Catalog']['database'];

 $host   = $this->config['Catalog']['host'];
 $db     = $this->config['Catalog']['database'];
 $user   = $this->config['Catalog']['user'];
 $pass   = $this->config['Catalog']['password'];
 
//  error_log(__FILE__ . " line " . __LINE__ . '  host=' . $host . ' db=' . $db . ' user=' . $user . ' pass=' . $pass ,0);

        // Load Configuration for this Module
        $configArray = parse_ini_file('conf/cloud.ini', true);
        $this->host   = $configArray['Catalog']['host'];
        $this->port   = $configArray['Catalog']['port'];
	$this->agency = $configArray['Catalog']['agency'];

    }	
    
    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber; on
     * failure, a PEAR_Error.
     * @access public
     */
    public function getStatus($id)
    {
      //	$params = 'getstatus?id=' . $id . '&agency=' . $this->agency ;
	$params = 'getstatus?id=' . $id  ;
      //  error_log(__FILE__ . " line " . __LINE__ . ' params='. $params,0);
        $response = $this->search_cloud($params);
        $xml = simplexml_load_string($response);
        $items = array();
          foreach($xml as $item){
            $items[] = array(
	    'id'           => trim($item->id),
            'number'       => trim($item->number),
	    'barcode'      => trim($item->barcode),
            'availability' => trim($item->availability),
            'status'       => trim($item->status),
            'location'     => trim($item->location),
            'reserve'      => trim($item->reserve),
            'callnumber'   => trim($item->callnumber),
            'duedate'      => trim($item->duedate)			
            );
	  }	
        return $items ;
    }

    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $ids The array of record ids to retrieve the status for
     *
     * @return mixed     An array of getStatus() return values on success,
     * a PEAR_Error object otherwise.
     * @access public
     */
    public function getStatuses($ids)
    {
        $status = array();
        foreach ($ids as $id) {
            $status[] = $this->getStatus($id);
        }
        return $status;
    }
 
    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber, duedate,
     * number, barcode; on failure, a PEAR_Error.
     * @access public     public function getHolding($id)
     */
    public function getHolding($id, $patron = false)	 
    {
      //  error_log(__FILE__ . " line " . __LINE__ . ' getHolding='. $id,0);
        return $this->getStatus($id);
    }

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @return mixed     An array with the acquisitions data on success, PEAR_Error
     * on failure
     * @access public
     */
    public function getPurchaseHistory($id)
    {
        return array();
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $barcode  The patron barcode
     * @param string $password The patron password
     *
     * @return mixed           Associative array of patron info on successful login,
     * null on unsuccessful login, PEAR_Error on error.
     * @access public
     */
    public function patronLogin($barcode, $password)
    {
       // error_log(__FILE__ . " line " . __LINE__ . ' patronLogin barcode='. $barcode . ' password=' . $password,0);
	
       // $home_library = $_REQUEST['home_library'];
        $user = UserAccount::isLoggedIn();
	$home_library = $user->home_library;
       
 	$params = 'patronlogin?barcode=' . $barcode . '&password=' . $password . '&homelib=' . $home_library ; 
        $response = $this->search_cloud($params);
        $xml = simplexml_load_string($response);
		
	 $user = array();
	 foreach($xml as $item){
           $user[] = array(
            'id'           => trim($item->id),
            'firstname'    => trim($item->firstname),
	    'lastname'     => trim($item->lastname),
            'cat_username' => trim($item->barcode),
            'cat_password' => trim($item->password),
            'email'        => trim($item->email),
            'pat_id'       => trim($item->pat_id),
            'pat_class'    => trim($item->pat_class),
            'home_library' => trim($item->home_library),
            'reg_date'     => trim($item->reg_date)			
         );		 
	  }	
	  
      return $user;
    }
    /**
     * Get Patron Profile
     */
    public function getMyProfile($patron)
    {
    
        $luser = UserAccount::isLoggedIn();
	$home_library = $luser->home_library;
    
        $pat_id = $patron[0]['pat_id'];
 	$params = 'getmyprofile?pat_id=' . $pat_id . '&homelib=' . $home_library; 
        $response = $this->search_cloud($params);
        $xml = simplexml_load_string($response);	
 //       error_log(__FILE__ . " line " . __LINE__ . ' getMyProfile function pat_id=' . $pat_id   ,0);			
        $user = array();
	 
	 foreach($xml as $item){
           $user = array(
            'firstname'    => trim($item->firstname),
	    'lastname'     => trim($item->lastname),
            'address1'     => trim($item->address1),
            'address2'     => trim($item->address2),
            'zip'          => trim($item->zip),
            'phone'        => trim($item->phone),
            'group'        => trim($item->pgroup)
		);	
 	  }	
      return $user;
		
    }
    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's fines on success, PEAR_Error
     * otherwise.
     * @access public
     */
    public function getMyFines($patron)
    {
    
        $luser = UserAccount::isLoggedIn();
	$home_library = $luser->home_library;
	
        $pat_id = $patron[0]['pat_id'];
	$params = 'getmyfines?id=' . $pat_id  . '&homelib=' . $home_library ;		
        $response = $this->search_cloud($params);
        $xml = simplexml_load_string($response);	
     //   error_log(__FILE__ . " line " . __LINE__ . ' getmyfines function pat_id=' . $pat_id   ,0);	
		
	foreach($xml as $item){
          $items[] = array(
            'amount'  => trim($item->amount),
	    'checkout'=> trim($item->checkout),
            'fine'    => trim($item->fine),  
            'balance' => trim($item->balance), 
            'duedate' => trim($item->duedate),
            'id'      => trim($item->id)			
         );		 
	 }
       //   $aArray = $items;
       //   error_log(print_r($aArray, true), 3, 'debug.log');
         return $items;
    }

    /**
     * Get Patron Holds
     * This is responsible for retrieving all holds by a specific patron.
     * @param array $patron The patron array from patronLogin
     * @return mixed        Array of the patron's holds on success, PEAR_Error
     * otherwise.
     * @access public
     */
    public function getMyHolds($patron)
    {
        $luser = UserAccount::isLoggedIn();
	$home_library = $luser->home_library;
        $pat_id = $patron[0]['pat_id'];
 	$params = 'getmyholds?pat_id=' . $pat_id  . '&homelib=' . $home_library ;
        $response = $this->search_cloud($params);
        $xml = simplexml_load_string($response);	
   //     error_log(__FILE__ . " line " . __LINE__ . ' getMyHolds function pat_id=' . $pat_id   ,0);				
	foreach($xml as $item){
         $items[] = array(
          'id'        => trim($item->id),
	  'location'  => trim($item->location),
          'expire'    => trim($item->expire),  
          'create'    => trim($item->create), 
          'reqnum'    => trim($item->reqnum)			
         );		 
	 }			
  	return $items;
    }

    /**
     * Get Patron Transactions
     *
     * This is responsible for retrieving all transactions (i.e. checked out items)
     * by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's transactions on success,
     * PEAR_Error otherwise.
     * @access public
     */
    public function getMyTransactions($patron)
    {
    
        $user = UserAccount::isLoggedIn();
	$home_library = $user->home_library;
    
	$pat_id = $patron[0]['pat_id'];	
	$params = 'getmytransactions?id=' . $pat_id  . '&homelib=' . $home_library  ;		
        $response = $this->search_cloud($params);
        $xml = simplexml_load_string($response);	
     //   error_log(__FILE__ . " line " . __LINE__ . ' getMyTransactions function pat_id=' . $pat_id   ,0);				
	foreach($xml as $item){
           $items[] = array(
	    'duedate'      => trim($item->duedate),
	    'barcode'      => trim($item->barcode),
            'renew'        => trim($item->renew),   // not exactly sure whate 
            'id'           => trim($item->id),
            'item_id'      => trim($item->item_id),
            'renewable'    => trim($item->renewable)                 // renewable i dunno
            );
	    }
//            $aArray = $items;
//            error_log(print_r($aArray, true), 3, 'debug.log');

        return $items;
    }

    /**
     * Get Funds
     *
     * Return a list of funds which may be used to limit the getNewItems list.
     *
     * @return array An associative array with key = fund ID, value = fund name.
     * @access public

    public function getFunds()
    {
        return array("alpatFund A", "Fund B", "Fund C");
    }
     */
    /**
     * Get Departments
     *
     * Obtain a list of departments for use in limiting the reserves list.
     *
     * @return array An associative array with key = dept. ID, value = dept. name.
     * @access public

    public function getDepartments()
    {
        return array("alpatDept. A", "Dept. B", "Dept. C");
    }
     */
    /**
     * Get Instructors
     *
     * Obtain a list of instructors for use in limiting the reserves list.
     *
     * @return array An associative array with key = ID, value = name.
     * @access public

    public function getInstructors()
    {
        return array("alpatInstructor A", "Instructor B", "Instructor C");
    }
     */
    /**
     * Get Courses
     *
     * Obtain a list of courses for use in limiting the reserves list.
     *
     * @return array An associative array with key = ID, value = name.
     * @access public
 
    public function getCourses()
    {
        return array("alpatCourse A", "Course B", "Course C");
    }

     */
     
    public function search_cloud($params)  // alpat
    {
        $url = $this->build_query($params);
    	// error_log(" line " . __LINE__ . ' Url=' . $url ,0);
        $response = file_get_contents($url);
        return $response;
    }

    public function build_query($params)  //alpat
    {
        $url = $this->host;
        if ($this->port) {
            $url =  "http://" . $url . ":" . $this->port . "/" ;
        } else {
            $url =  "http://" . $url . "/" ;
        }
       // error_log(" line " . __LINE__ . ' Url=' . $url ,0);
        $url = $url . $params;
        return $url;
    }

 	
	
	
}
?>