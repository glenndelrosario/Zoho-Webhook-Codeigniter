<?php

if ( ! defined('BASEPATH')) 
	exit('No direct script access allowed');

class Logging {
	
	protected $CI = NULL;

	public function __construct() {
        if (is_null($this->CI)) {
            $this->CI =& get_instance();
        }
  }

	/*
    Create a record of the API that had been called.
    Services from: ChargeBee, Tookan, SendGrid, HubSpot  
  */
	public function insert_api_log($values){
  	$this->CI->db->insert('ss_logs_api_calls',$values);
  	return $this->CI->db->insert_id();
  }

  public function insert_webhook_log($values){
  	$this->CI->db->insert('ss_logs_webhooks',$values);
  	return $this->CI->db->insert_id();
  }

}
