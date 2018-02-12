<?php

class Syndicaster {


  /**
	 * Holds the URL for syndicasters api service.
	 */
  private $syndicaster_url = 'http://api.syndicaster.tv/';

  /**
	 * Holds the account information.
	 */
  public $auth = ''; // Gets from Wordpress Options
  public $account = [
    'user'=>'',
    'password'=>'',
    'content_owner'=>'',
    'publisher'=>''
  ];
  private $app = [
    'id'=>'',
    'secret'=>''
  ];

  /**
   * Loads the settings when the object is created.
   *
   * @param boolean $account Optional. Account information.
   * @param boolean $app     Optional. Application infromation.
   */
  public function __construct($account = false, $app = false) {
    $this->auth = get_option('syn_auth', false);
    $this->account = ($account) ? $account : get_option('syn_account', false); // Store the md5 password here.
    $this->app = ($app) ? $app : get_option('syn_app', false);
  }

  /**
   * Cuts the fluff and formats an array.
   *
   * @param  array $array The array to be formatted.
   * @return array        The formatted array.
   */
  public function cut($array){

    // TODO: Use timezone set in Wordpess settings.
    date_default_timezone_set('America/Chicago');

    // Determines what kind of array it is.
  	$data = (isset($array->results)) ? $array->results : [$array];
    $amount = count($data);
    $new_array = [];
    for($i = 0; $i < $amount; $i++){
      $date = strtotime($data[$i]->completed_at);
      $new_array[] = [
        "id" => $data[$i]->id,
        "parent_id" => $data[$i]->parent_file_set_id,
        "title" => $data[$i]->metadata->title,
        "date" => date('D, m/d/y \a\t h:i a', $date),
        "thumb" => $data[$i]->files[0]->uri,
        "image" => $data[$i]->files[1]->uri
      ];
    }
    return $new_array;
  }

  /**
   * Makes an API request using a given path and parameters.
   *
   * @param  string           $method  POST, GET, etc.
   * @param  string|array     $data    Information to send with the request.
   * @param  string           $path    API path
   * @param  boolean|integer  $json    Optional. Is the data json formatted.
   * @param  boolean|integer  $is_auth Optional. Is this an authentication request.
   *
   * @return array                     JSON decoded array.
   */
  private function api_request($method, $data, $path, $json = 1, $is_auth = 1) {
    $data = ($json) ? json_encode($data) : $data;
    $ch = curl_init($this->syndicaster_url . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS,($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Sets the header for authentication.
    if($is_auth) {
      $access = $this->auth['access_token'];
      $header = ["Content-Type: application/json","Authorization: OAuth ".$access];
      curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }

    // Execute the request.
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    date_default_timezone_set('America/Chicago');

    // 401 is an access denied response.
    if($httpCode == 401){
      // Get a new token.
      $token = $this->get_token();
      $header = ["Content-Type: application/json","Authorization: OAuth ".$token['access_token']];
      curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

      $response = curl_exec($ch);
      curl_close($ch);
      return json_decode($response);
    } else {
      curl_close($ch);
      return json_decode($response);
    }
  }

  /**
   * API authentication requestion to get new tokkens.
   *
   * @param  boolean|integer $refresh_token  Optional. Do we need a refresh token.
   *
   * @return string|array                    Response data formatted into an array.
   */
  public function get_token($refresh_token = 0){
    $post_data = ($refresh_token) ? array(
      'grant_type' => 'refresh_token',
      'client_id' => $this->app['id'],
      'client_secret' => $this->app['secret'],
      'refresh_token' => $refresh_token,
    ) : array(
    	'grant_type' => 'password',
    	'client_id' => $this->app['id'],
    	'client_secret' => $this->app['secret'],
    	'scope' => 'read',
    	'username' => $this->account['user'],
    	'password'=> $this->account['password']
    );
    $response = $this->api_request("POST", $post_data, "oauth/access_token", 0, 0);
    if(!isset($response->expires_in)){  return $response; }

    $options = get_object_vars($response);
    $options['expires_in'] = $options['expires_in'] + time();
    update_option('syn_auth', $options);
    return $options;
  }

  /**
   * API request for playlists.
   *
   * @return array|boolean False if there is no publisher, otherwise an array of playlists.
   */
  public function get_playlists(){
    if(empty($this->account['publisher'])) { return false; }
    $path='syndi_playlists.json?publisher_id[]='.$this->account['publisher'];
    $response = $this->api_request("GET", '', $path);
    return $response;

  }

  /**
   * API request to search videos given criteria
   *
   * @param  string  $playlist The playlist to search in.
   * @param  string  $lookup   Optional. The phrase to search for.
   * @param  integer $per_page Optional. The number of videos to limit to.
   * @param  integer $page     Optional. The current page to offset to.
   * @param  boolean $format   Optional. Format the returned array using the cut function.
   *
   * @return array             Contains video metadata.
   */
  public function search($playlist, $lookup = '', $per_page = 12, $page = 1, $format = True){
    $path = 'file_sets/search.json';
    $query = trim($lookup . ' ' . $playlist);
    $post_data = array(
     'content_owner_ids' => array($this->account['content_owner']),
     'distributable'=> true,
     'per_page'=>$per_page,
     'page'=>$page,
     'media_type_ids'=>array('3'),
     'query'=>$query,
     'status_ids'=>array('3')
   );
    $response = $this->api_request("POST", $post_data, $path);
    if($format){return $this->cut($response);}
    return $response;
  }

  /**
   * API request to get metadata for a specific video.
   *
   * @param  integer  $file_id  The specific video file to loopup.
   * @param  string   $options  Optional. What metadata to return.
   * @param  boolean  $format   Optional. Format the returned array using the cut function.
   *
   * @return array              Contains video metadata.
   */
  public function get_video_info($file_id, $options = 'metadata,files', $format = True){
    $path = 'file_sets/'.$file_id .'/'.$options;
    $response = $this->api_request("GET", '', $path);
    if($format){return $this->cut($response);}
    return $response;
  }

  /**
   * API request to get the clip id for a specific file id.
   *
   * @param  integer        $file_id    The specific video file to loopup.
   * @param  boolean        $return_id  Optional. Only return the clip id and nothing else.
   * @return integer|array              The clip id or an array with video distribution metadata.
   */
  public function get_clip_id($file_id, $return_id = True) {
  	$path = 'file_sets/'.$file_id.'/distributions';
    $response = $this->api_request("GET", '', $path);

    if($return_id){return $response[0]->repo_guid;}
    return $response;
  }

  /**
   * API request to get all the content owners on an account.
   *
   * @param  string $lookup Optional. Find information for a specific content owner.
   *
   * @return array          An array with content owner information.
   */
  public function content_owners($lookup = ''){
    $path = '/admin/content_owners';
    $post_data = array(
     'query' => $lookup,
   );
    $response = $this->api_request("GET", '', $path);
    return $response;
  }
}
