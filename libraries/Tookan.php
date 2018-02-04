<?php

if ( ! defined('BASEPATH')) 
	exit('No direct script access allowed');

class Tookan {
	
	protected $CI = NULL;

	public function __construct() {
		if (is_null($this->CI)) {
			$this->CI =& get_instance();
		}
	}


	public function create_task_to_tookan($data,$tookan_id){
        
        $fields        = $this->data_mapping_for_tookan($data);

        $tookan_status = $this->check_task_exists_in_tookan($tookan_id);

        if($tookan_status==200){
            $url = 'https://api.tookanapp.com/v2/edit_task';
            $fields['job_id'] = $tookan_id;               
            $log_data['webhook_type'] = "Zoho Update Delivery Order";        
        }else{
            $url = 'https://api.tookanapp.com/v2/create_task';     
            $log_data['webhook_type'] = "Zoho Add New Delivery Order";                       
        }
        // Send request to Tookan App
        $json_request = $this->curl_to_tookan($fields,$url);
        $response 		= json_decode($json_request,true);

        // Create log
		$this->CI->load->library('New/Logging');
		$log_data['details'] = $json_request;				
		$log_data['status']  = ($response['status']==200)?'success':'failed';
		$this->CI->logging->insert_webhook_log($log_data);
	            
        if($response['status']==200 && is_null($tookan_id) ){      

            $upval['tookan_id'] = $response['data']['job_id'];
            $this->CI->db->where('zoho_id',$data['zoho_id']);
            $this->CI->db->update('ss_zoho_delivery_orders',$upval);
        }


        return $response;
    }

    public function data_mapping_for_tookan($data){
        $customer_username = !is_null($data['Contact_Name']) && $data['Contact_Name']!='' ? $data['Contact_Name']:$data['User_Name'];

        $customer_phone = !is_null($data['Contact_No']) && $data['Contact_No']!='' ? $data['Contact_No']:$data['User_Mobile'];

        $custom_reference = ($data['DO_reference_no']!='')?'DO reference no. '.$data['DO_reference_no']:'';
        $remarks  = ''; 
        $remarks .= $data['Item_Description'].PHP_EOL.PHP_EOL;
        $remarks .= $data['Ops_Remarks'].PHP_EOL.PHP_EOL;
        $remarks .= $custom_reference.PHP_EOL.PHP_EOL;

        $to_date  = $data['Scheduled_Date'];
        $to_start = date('H:i:s',strtotime($data['Scheduled_Start']));
        $to_end   = date('H:i:s',strtotime($data['Scheduled_End']));

        $job_pickup_datetime   = $to_date.' '.$to_start;
        $job_delivery_datetime = $to_date.' '.$to_end;

        // Services
        $assemble = ($data['of_Items_to_Assemble']>0)?'Assemble x '.$data['of_Items_to_Assemble'].PHP_EOL:'';
        $dismantle = ($data['of_Items_to_Dismantle']>0)?'Dismantle x '.$data['of_Items_to_Dismantle'].PHP_EOL:'';
        $lift = ($data['of_Items_that_cannot_fit_into_lift']>0)?'Cannot fit into lift x '.$data['of_Items_that_cannot_fit_into_lift'].PHP_EOL:'';
        $twkg = ($data['of_items_20kg']>0)?'Items > 20kg x '.$data['of_items_20kg'].PHP_EOL:'';
        $hhkg = ($data['of_items_100kg']>0)?'Items > 100kg x '.$data['of_items_100kg'].PHP_EOL:'';

        $disposal = ($data['Disposal_of_Items']=='true')?'Disposal of Items: Yes '.PHP_EOL:'';

        $vtd = ($data['of_Vehicle_Trips_Disposal']>0)?'Vehicle Trips (Disposal) x '.$data['of_Vehicle_Trips_Disposal'].PHP_EOL:'';
        
        $Other_Services  = $assemble;
        $Other_Services .= $dismantle;
        $Other_Services .= $lift;
        $Other_Services .= $twkg;
        $Other_Services .= $hhkg;
        $Other_Services .= $disposal;
        $Other_Services .= $vtd;

        $user_id = $data['User_ID'];

        // Materials
        $pbc = ($data['of_Plastic_Boxes_to_Collect']>0)?'Plastic Boxes x '.$data['of_Plastic_Boxes_to_Collect'].PHP_EOL:'';
        $cbc = ($data['of_Carton_Boxes_to_Collect']>0)?'Carton Boxes x '.$data['of_Carton_Boxes_to_Collect'].PHP_EOL:'';
        $tc = ($data['of_Tapes_to_Collect']>0)?'Tapes x '.$data['of_Tapes_to_Collect'].PHP_EOL:'';
        $bwc = ($data['of_Bubble_Wraps_to_Collect']>0)?'Bubble Wraps x '.$data['of_Bubble_Wraps_to_Collect'].PHP_EOL:'';				

        $pbd = ($data['of_Plastic_Boxes_to_Deliver']>0)?'Plastic Boxes x '.$data['of_Plastic_Boxes_to_Deliver'].PHP_EOL:'';
        $cbd = ($data['of_Carton_Boxes_to_Deliver']>0)?'Carton Boxes x '.$data['of_Carton_Boxes_to_Deliver'].PHP_EOL:'';
        $td = ($data['of_Tapes_to_Deliver']>0)?'Tapes x '.$data['of_Tapes_to_Deliver'].PHP_EOL:'';
        $bwd = ($data['of_Bubble_Wraps_to_Deliverof_Bubble_Wraps_to_Deliver']>0)?'Bubble Wraps x '.$data['of_Bubble_Wraps_to_Deliver'].PHP_EOL:'';				

        $Additional_Materials  = $pbc;
        $Additional_Materials .= $cbc;
        $Additional_Materials .= $tc;
        $Additional_Materials .= $bwc;

        $Additional_Materials .= $pbd;
        $Additional_Materials .= $cbd;
        $Additional_Materials .= $td;
        $Additional_Materials .= $bwd;

        $Vehicle_Type 	  = '';
        $Vehicle_Capacity = '';

        if($data['Vehicle_Type']!=''){
            $arr = explode(',',$data['Vehicle_Type']);
            foreach ($arr as $key => $value) {
                $clean = str_replace(array('[',']'),'',$value);
                $Vehicle_Type .= $clean.PHP_EOL;
            }
        }

        if($data['Vehicle_Capacity']!=''){
            $arr = explode(',',$data['Vehicle_Capacity']);
            foreach ($arr as $key => $value) {
                $clean = str_replace(array('[',']'),'',$value);
                $Vehicle_Capacity .= $clean.PHP_EOL;						
            }
        }

        $fields['customer_email']    		 = $data['User_Email'];
        $fields['order_id'] 			     = $data['Order_ID'];
        $fields['customer_username'] 		 = $customer_username;
        $fields['customer_phone']    		 = $customer_phone;
        $fields['customer_address']  		 = $data['Full_Address'];
        $fields['job_description']   		 = $remarks;
        $fields['job_pickup_datetime']       = $job_pickup_datetime;
        $fields['job_delivery_datetime']     = $job_delivery_datetime;
        $fields['has_pickup'] 			     = 0;
        $fields['has_delivery'] 		     = 0;
        $fields['layout_type'] 			     = 1;
        $fields['tracking_link'] 		     = 1;
        $fields['timezone'] 			     = 480;
        $fields['custom_field_template']     = 'Main';

        $fields['meta_data'][0]['label']     = 'Delivery_ID';
        $fields['meta_data'][0]['data']      = $data['Delivery_ID'];

        $fields['meta_data'][1]['label']     = 'Warehouse_Address';
        $fields['meta_data'][1]['data']      = $data['WH_Address'];

        $fields['meta_data'][2]['label']     = 'Delivery_Type';
        $fields['meta_data'][2]['data']      = $data['Delivery_Type'];

        $fields['meta_data'][3]['label']     = 'Vehicle_Type';
        $fields['meta_data'][3]['data']      = $Vehicle_Type;

        $fields['meta_data'][4]['label']     = 'Vehicle_Capacity';
        $fields['meta_data'][4]['data']      = $Vehicle_Capacity;

        $fields['meta_data'][5]['label']     = 'Manpower_Required';
        $fields['meta_data'][5]['data']      = $data['Num_of_Manpower'];

        $fields['meta_data'][6]['label']     = 'Additional_Materials';
        $fields['meta_data'][6]['data']      = $Additional_Materials;

        $fields['meta_data'][7]['label']     = 'Other_Services';
        $fields['meta_data'][7]['data']      = $Other_Services;

        $fields['meta_data'][8]['label']     = 'uid';
        $fields['meta_data'][8]['data']      = $user_id;

        $fields['geofence']                  = 0;
        $fields['tags']                      = '';
        $fields['notify']                    = 1;
        $fields['ref_images']                = 0;
        $fields['fleet_id']                  = '';
        $fields['auto_assignment']           = 0;
        $fields['team_id']                   = 10314;

        return $fields;
    }

    public function update_tookan_status($tookan_id,$status){
        
        $url = 'https://api.tookanapp.com/v2/update_task_status';
                                
        $update['job_status'] = $status; 
        $update['job_id']     = $tookan_id;                
        $json_request         = $this->curl_to_tookan($update,$url);
        $response             = json_decode($json_request,TRUE);

        return $response;
    }

    public function check_task_exists_in_tookan($tookan_id){
        $url = 'https://api.tookanapp.com/v2/get_task_details';
        $fields['job_id'] = $tookan_id;
        $fields['user_id'] = '10216';
        $json_request = $this->curl_to_tookan($fields,$url);
        $res = json_decode($json_request,true);

        return $res['status'];
    }

     public function get_subscription_for_tookan($order_id){
        $this->CI->db->select('Name');
        $this->CI->db->where('Order_ID',$order_id);
        $this->CI->db->where('Category','Storage Subscription');
        $this->CI->db->join('ss_product_catalog','ss_product_catalog.Prod_ID=ss_order_items.Product_ID');
        $this->CI->db->order_by('Start_Date DESC');
        $query = $this->CI->db->get('ss_order_items');
        if($query->num_rows()>0){
            $row = $query->row();
            return $row->Name;
        }

        return 'Not Set';
    }


    public function get_lookup_value_for_tookan($id,$type){
        $this->CI->db->where('Name',$type);
        $this->CI->db->where('Value',$id);
        $query = $this->CI->db->get('ss_lookup');
        if($query->num_rows()==1){
            $row = $query->row();
            return $row->Label;
        }
    }

    public function get_delivery_type($delivery_type){
        
        $this->CI->db->where('Name','DeliveryOrder.Type');
        $this->CI->db->where('Value',$delivery_type);
        $query = $this->CI->db->get('ss_lookup');
        if($query->num_rows()==1){
            $row = $query->row();
            return $row->Label;
        }
    }

    public function get_warehouse_address($address_id){
        $this->CI->db->where('Address_ID',$address_id);
        $this->CI->db->where('Type',2);
        $query = $this->CI->db->get('ss_userAddress');
        if($query->num_rows()==1){
            $row = $query->row();
            return $row->Address;
        }
    }

    public function get_do_attachment($delivery_id){
        
        $this->CI->db->where('delivery_id',$delivery_id);
        $query = $this->CI->db->get('ss_delivery_order_attachments');
        $data = array();

        if($query->num_rows()>0){
            foreach($query->result_array() as $k=>$v){
                $data[$k] = $v['url'];
            }
        }

        return $data;
    }

    public function get_additional_details_for_tokan($delivery_id,$type){
        $this->CI->db->where('Delivery_Order_ID',$delivery_id);
        $this->CI->db->where('Lookup_Name',$type);
        $query = $this->CI->db->get('ss_do_additional_details');
        $data = array();

        if($query->num_rows()>0){
            foreach($query->result_array() as $k=>$v){
                $data[$k] = $this->get_lookup_value_m($v['Lookup_Value'],$type,$v['quantity']);
            }
        }

        return $data;
    }

    public function get_lookup_value_m($id,$type,$quantity){
        $this->CI->db->where('Name',$type);
        $this->CI->db->where('Value',$id);
        $query = $this->CI->db->get('ss_lookup');
        if($query->num_rows()==1){
            $row = $query->row();
            return $row->Label.' x '.$quantity;
        }
    }

    public function get_order_number($order_id){
        $this->CI->db->where('Or_ID',$order_id);        
        $query = $this->CI->db->get('ss_orders');
        if($query->num_rows()==1){
            $row = $query->row();
            return $row->Order_ID;
        }
    }

    public function curl_to_tookan($fields,$url){
        $fields['api_key'] = $this->config->item('tookan_api');
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);

        curl_setopt($ch, CURLOPT_POST, TRUE);

        curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($fields));

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          "Content-Type: application/json; charset=utf-8"
        ));

        $json_request = curl_exec($ch);
        curl_close($ch);

        return $json_request;

    }

}
