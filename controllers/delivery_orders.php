<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Delivery_orders extends CI_Controller {

	public function __construct (){
		parent::__construct();

		$this->auth_token = '7781a9e0faed3c60b6106f93816bd7ee';

		$api_key = $this->config->item('rest_api_key');

		if(!$_POST){
			exit('The page that you\'re looking for doesn\'t exist.');
		}else{
			if($api_key !== $_POST['key']){
				exit('The page that you\'re looking for doesn\'t exist.');
			}
		}
		$this->load->library('Logging');
		$this->load->library('Tookan');
	}

	/*
		Create a copy of Delivery Order from Zoho to SpaceX
		@params string Delivery_ID - delivery number of the DO
		@params string Order_ID    - order number of the Order
		@params string Source      - creation source of the DO (SpaceX, Frontend, Zoho)  
	*/
  public function add(){

		$data = $this->input->post();
		unset($data['key']);

		$db_data['post_data']     = json_encode($data);
		$db_data['delivery_id']   = $data['Delivery_ID'];
		$db_data['order_number']  = $data['Order_ID'];
		$source    								= $data['Source'];
		
		$do_tookan_id 						= $this->_check_if_do_exists($data['zoho_id']);
		
		if(!empty($do_tookan_id)){
			$this->db->where('zoho_id',$data['zoho_id']);
			$this->db->update('ss_zoho_delivery_orders',$db_data);			
		}else{			
			$db_data['zoho_id']     = $data['zoho_id'];
			// If source is from SpaceX, save the tookan id too
			if($source == "SpaceX"){
				$db_data['tookan_id'] = $data['Tookan_ID'];
			}		
			$this->db->insert('ss_zoho_delivery_orders',$db_data);
			$do_id = $this->db->insert_id();
		}	

		$status  		= $data['Status'];
		if(strpos($source, 'Zoho') !== false || strpos($source, 'Frontend') !== false){
			// Use the tookan ID from database
			$tookan_id  = (!empty($do_tookan_id))?$do_tookan_id:NULL;	
		}else{
			// Use the tookan ID from Zoho
			$tookan_id  = (!empty($data['Tookan_ID']))?$data['Tookan_ID']:NULL;
		}
		      
		if(($status == "Assigned to driver" || $status == "Confirmed") && (ENVIRONMENT == 'production')){
			$response = $this->tookan->create_task_to_tookan($data,$tookan_id);	
		}

		if($status=='Cancelled'){
			$status_id = 9;
		}else if($status=='Completed'){
			$status_id = 2;
		}else{ // send unassigned to tookan
			$status_id = 6;
		}
		
		if(!is_null($tookan_id) && (ENVIRONMENT == 'production')){
				$response = $this->tookan->update_tookan_status($tookan_id,$status_id);
				
				$log_data['webhook_type'] = "Tookan Update"; 
				$log_data['details'] = json_encode($response);				
				$log_data['status']  = 'success';
				$this->logging->insert_webhook_log($log_data);
		}

		if($response['status']==200){
			echo json_encode($response);
		}else{
			echo json_encode($response);
		}

  }

  /*
		Send Notification email to Customer using SendGrid App  	
  */
  public function send_email(){
  	$post_data = $this->input->post();
		
		$webhook_url    = $this->config->item('webhook_url');
		$url            = $webhook_url.'sendgrid_do_emails';

		$delivery_type  = $this->_get_sendgrid_type_id($post_data['Delivery_Type']);
		$sendgrid_data  = array();

		$order_id = 0;
		$this->db->where('Order_ID',$post_data['Order_ID']);
		$query = $this->db->get('ss_orders');
		if($query->num_rows() == 1){
			$row = $query->row();
			$order_id = $row->Or_ID;
		}
                                                
    $sendgrid_data['delivery_type']    = $delivery_type;
    $sendgrid_data['order_id']         = $order_id;
    $sendgrid_data['user_id']          = $post_data['User_ID'];

    $start_time = date('g:s a',strtotime($post_data['Scheduled_Start']));
    $end_time   = date('g:s a',strtotime($post_data['Scheduled_End']));
    $sendgrid_data['assigned_date']    = $post_data['Scheduled_Date'];
    $sendgrid_data['assigned_time']    = $start_time.' - '.$end_time;                       
    $sendgrid_data['delivery_address'] = $post_data['Full_Address'];

    $email_data['type'] 							 = 'delivery_order';
    $email_data['Admin_ID'] 					 = 5; 
    $email_data['id'] 								 = $post_data['Delivery_ID'];
		    
    $sendgrid_data['email_data']       = $email_data;

    $this->load->library('New/Zoho/Zoho_Lib');    
		$response = $this->zoho_lib->trigger_webhook($url,$sendgrid_data); 

		// Create a log
		$log_data['webhook_type'] = "Send Email To Customer from Zoho DO"; 
		$log_data['details'] = json_encode($sendgrid_data);		
		$log_data['status']  = 'success';
		$this->logging->insert_webhook_log($log_data);

		echo 'Email successfully sent!';
  }

   /*
  	Check if the zoho id passed already exists in database
  	@params int Zoho ID of the Delivery Order
  	@return int tookan id
  */
  private function _check_if_do_exists($zoho_id){
  	$this->db->where('zoho_id',$zoho_id);
  	$query = $this->db->get('ss_zoho_delivery_orders');

  	if($query->num_rows()==1){
  		$row = $query->row();  	 
  		return $row->tookan_id;
  	}
  	
  	return false;

  }

  /*
  	Get the ID number for the passed delivery type
  	@params string name of the delivery type
  	@return int numerical id assigned in Sendgrid App
  */
  private function _get_sendgrid_type_id($type){

    switch ($type) {
        case 'Delivery':
        	return 3;
        break;
        
        case 'Termination':
        	return 6;
        break;

        case 'Moving':
        	return 4;
        break;

        case 'WH Return':
        	return 12;
        break;

        case 'WH Termination':           
        	return 13;
        break; 

        case 'Box Drop + Collection':
        case 'Collection':
       		return 1;
        break;
        case 'Box Drop':
        	return 0;
        break;
    }
 }

}