<?php
elFinder::$netDrivers['onedrive'] = 'OneDrive';

/**
 * Simple elFinder driver for OneDrive
 * onedrive api v5.0
 *
 * @author Dmitry (dio) Levashov
 * @author Cem (discofever)
 **/
class elFinderVolumeOneDrive extends elFinderVolumeDriver {

    /**
     * Driver id
     * Must be started from letter and contains [a-z0-9]
     * Used as part of volume id
     *
     * @var string
     **/
    protected $driverId = 'od';    
	
	/**
     * @var string The base URL for API requests.
     */
    const API_URL = 'https://apis.live.net/v5.0/';

    /**
     * @var string The base URL for authorization requests.
     */
    const AUTH_URL = 'https://login.live.com/oauth20_authorize.srf';

    /**
     * @var string The base URL for token requests.
     */
    const TOKEN_URL = 'https://login.live.com/oauth20_token.srf';
	
	/**
     * OneDrive service object
     *
     * @var object
     **/
	 
    protected $onedrive = null;
       
    /**
     * Directory for tmp files
     * If not set driver will try to use tmbDir as tmpDir
     *
     * @var string
     **/
    protected $tmp = '';
    
    /**
     * Net mount key
     *
     * @var string
     **/
    public $netMountKey = '';
    
    /**
     * Thumbnail prefix
     *
     * @var string
     **/
    private $tmbPrefix = '';
    
	/**
	 * hasCache by folders
	 *
	 * @var array
	 **/
	protected $HasdirsCache = array();
	    
    /**
     * Constructor
     * Extend options with required fields
     *
     * @return void
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     **/
    public function __construct()
    {			
        $opts = array(
            'client_id'         => '',
            'client_secret'     => '',
            'accessToken'       => '',            
            'root'              => 'OneDrive.com',
            'OneDriveApiClient'   => '',
            'path'              => '/',
            'separator'         => '/',
            'tmbPath'           => '../files/.tmb',
			'tmbURL'            => dirname($_SERVER['PHP_SELF']) . '/../files/.tmb',
			'tmpPath'           => '../files/.tmp',	
            'acceptedName'      => '#^[^/\\?*:|"<>]*[^./\\?*:|"<>]$#',
            'rootCssClass'      => 'elfinder-navbar-root-onedrive'
        );
        $this->options = array_merge($this->options, $opts);
        $this->options['mimeDetect'] = 'internal';				
    }


    /**
     * Prepare
     * Call from elFinder::netmout() before volume->mount()
     *
     * @return Array
     * @author Naoki Sawada
     * @author Raja Sharma updating for OneDrive
     **/
    public function netmountPrepare($options)
    {
		
        if (empty($options['client_id']) && defined('ELFINDER_ONEDRIVE_CLIENTID')) {
            $options['client_id'] = ELFINDER_ONEDRIVE_CLIENTID;
        }
        if (empty($options['client_secret']) && defined('ELFINDER_ONEDRIVE_CLIENTSECRET')) {
            $options['client_secret'] = ELFINDER_ONEDRIVE_CLIENTSECRET;
        }
				
				
		if ($options['pass'] === 'reauth') {
			$options['user'] = 'init';
			$options['pass'] = '';
			$this->session->remove('elFinderOneDriveTokens');			
			$this->session->remove('elFinderOneDriveAuthTokens');       
        }

		try {
			if (empty($options['client_id']) || empty($options['client_secret'])) {
				return array('exit' => true, 'body' => '{msg:errNetMountNoDriver}');
			}
						
			$onedrive = array(				
				'client_id' => $options['client_id']
			);
			
			if (isset($_GET['code'])) {
				try {
						
					if($_GET['protocol'] !== 'onedrive'){						
						$callback_url = $this->getConnectorUrl().'?cmd=netmount&protocol=onedrive&host=1&code='.$_GET['code'];						
						header("Location: ".$callback_url); 
						exit();
					}		
						
					$onedrive = array(						
    					'client_id' => $options['client_id']
					); 
										
					// Persist the OneDrive client' state for next API requests											
					$onedrive = array(
						'client_id' => $options['client_id'],						
						// Restore the previous state while instantiating this client to proceed in
						// obtaining an access token
						'state'  => $this->session->get('elFinderOneDriveTokens')
					);
				
					// Obtain the token using the code received by the OneDrive API
										
					// Persist the OneDrive client' state for next API requests
																			
					$this->session->set('elFinderOneDriveAuthTokens',
										$this->obtainAccessToken($options['client_id'], $options['client_secret'], $_GET['code']));
					
					$out = array(
							'node' => 'elfinder',
							'json' => '{"protocol": "onedrive", "mode": "done", "reset": 1}',
							'bind' => 'netmount'
							
					);						
									
					return array('exit' => 'callback', 'out' => $out);
						
				
				} catch (Exception $e) {
					return $e->getMessage();
				}
			}
		
			if ($options['user'] === 'init') {				
				if (empty($_GET['code']) && empty($_GET['pass']) && empty($this->session->get('elFinderOneDriveAuthTokens'))) {		   
					$cdata = '';
					$innerKeys = array('cmd', 'host', 'options', 'pass', 'protocol', 'user');
					$this->ARGS = $_SERVER['REQUEST_METHOD'] === 'POST'? $_POST : $_GET;
					foreach ($this->ARGS as $k => $v) {
						if (! in_array($k, $innerKeys)) {
							$cdata .= '&' . $k . '=' . rawurlencode($v);
						}
					}
					if (!empty($options['url']) && strpos($options['url'], 'http') !== 0) {
						$options['url'] = $this->getConnectorUrl();
					}
					$callback  = $options['url']
						.'?cmd=netmount&protocol=onedrive&host=onedrive.com&user=init&pass=return&node='.$options['id'].$cdata;
				
				  try {
						// Instantiates a OneDrive client bound to your OneDrive application
						$this->onedrive = array(
							'client_id' => $options['client_id']
						);
						
						$offline = '';			
						// Gets a log in URL with sufficient privileges from the OneDrive API										
						if (! empty($options['offline'])) {
							$offline = 'wl.offline_access';
						}

						$url = self::AUTH_URL
							. '?client_id=' . urlencode($options['client_id'])
							. '&scope=' . urlencode('wl.signin '.$offline.' wl.skydrive_update')
							. '&response_type=code'
							. '&redirect_uri=' . urlencode($this->getConnectorUrl())
							. '&display=popup'
							. '&locale=en';
			
						
						// Persist the OneDrive client' state for next API requests
						$this->session->set('elFinderOneDriveTokens',  (object) array(
																		'redirect_uri' => $this->getConnectorUrl(),
																		'token'        => null,
																	));														
						
						$url .= '&oauth_callback='.rawurlencode($callback);
					} catch (Exception $e) {
						return array('exit' => true, 'body' => '{msg:errAccess}');
					}
						
					$html = '<input id="elf-volumedriver-onedrive-host-btn" class="ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only" value="{msg:btnApprove}" type="button" onclick="window.open(\''.$url.'\')">';
					$html .= '<script>
							$("#'.$options['id'].'").elfinder("instance").trigger("netmount", {protocol: "onedrive", mode: "makebtn"});
						</script>';				
						
					return array('exit' => true, 'body' => $html); 
									
				}
				else{						
								
					$result = $this->query('me/skydrive', $root = false, $recursive = false);
					$folders = [];					
					
					foreach ($result as $res) {				
						if($res->type == 'folder'){
							$folders[$res->id] = $res->name;								
						}								
					}
					
					natcasesort($folders);
					$folders = ['root' => 'OneDrive'] + $folders;
					$folders = json_encode($folders);
					
					$json = '{"protocol": "onedrive", "mode": "done", "folders": '.$folders.'}';
					$html = 'OneDrive.com';
					$html .= '<script>
							$("#'.$options['id'].'").elfinder("instance").trigger("netmount", '.$json.');
							</script>';
					
					return array('exit' => true, 'body' => $html);
					
				}			
			}
		} catch (Exception $e) {
			return array('exit' => true, 'body' => '{msg:errNetMountNoDriver}');
		}
	
        if ($this->session->get('elFinderOneDriveAuthTokens')) {           
		   $options['accessToken'] = json_encode($this->session->get('elFinderOneDriveAuthTokens'));		   
        }
        unset($options['user'], $options['pass']);        
		
        return $options;
    }
    
    /**
     * process of on netunmount
     * Drop `onedrive` & rm thumbs
     * 
     * @param array $options
     * @return boolean
     */
    public function netunmount($netVolumes, $key)
    {
        if ($tmbs = glob(rtrim($this->options['tmbPath'], '\\/').DIRECTORY_SEPARATOR.$this->tmbPrefix.'*.png')) {
            foreach ($tmbs as $file) {
                unlink($file);
            }
        }
        
		return true;
	}
    
    /**
     * Get script url
     * 
     * @return string full URL
     * @author Naoki Sawada
     */
    private function getConnectorUrl()
    {
        $url  = ((isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')? 'https://' : 'http://')
               . $_SERVER['SERVER_NAME']                                              // host
              . ($_SERVER['SERVER_PORT'] == 80 ? '' : ':' . $_SERVER['SERVER_PORT'])  // port
               . $_SERVER['REQUEST_URI'];                                             // path & query
        list($url) = explode('?', $url);
        
        return $url;
    }
    
    /*********************************************************************/
    /*                        INIT AND CONFIGURE                         */
    /*********************************************************************/

    /**
     * Prepare FTP connection
     * Connect to remote server and check if credentials are correct, if so, store the connection id in $ftp_conn
     *
     * @return bool
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     **/
    protected function init()
    {
		if (!$this->options['accessToken']) {		
            return $this->setError('Required options undefined.');
        }
        
        // make net mount key
        $this->netMountKey = md5(join('-', array('onedrive', $this->options['path'])));
        
        if (! $this->onedrive) {					
            try {
                $this->onedrive = json_decode($this->options['accessToken']);
                if (true !== ($res = $this->refreshOneDriveToken($this->onedrive))) {
                    return $this->setError($res);
                }
            } catch (InvalidArgumentException $e) {
                return $this->setError($e->getMessage());
            }
            try {
				$this->onedrive = json_decode($this->options['accessToken']);
            } catch (Exception $e) {
                return $this->setError($e->getMessage());
            }
        }
        
        if (! $this->onedrive) {		
            return $this->setError('OAuth extension not loaded.');
        }
        
        // normalize root path
        if ($this->options['path'] == 'root') {
            $this->options['path'] = '/';
        }
		
        $this->root = $this->options['path'] = $this->_normpath($this->options['path']);
        
        $this->options['root'] == '' ?  $this->options['root']= 'OneDrive.com' : $this->options['root'];
        
        if (empty($this->options['alias'])) {
		    $this->options['alias'] = ($this->options['path'] === '/') || ($this->options['path'] === 'root')? $this->options['root'] : $this->getPathtoName($this->options['path']).'@OneDrive';
        }

        $this->rootName = $this->options['alias'];
               
        $this->tmbPrefix = 'onedrive'.base_convert($this->root, 10, 32);
        
        if (!empty($this->options['tmpPath'])) {
            if ((is_dir($this->options['tmpPath']) || mkdir($this->options['tmpPath'])) && is_writable($this->options['tmpPath']))
			{
                $this->tmp = $this->options['tmpPath'];				
			}
        }
		
        if (!$this->tmp && is_writable($this->options['tmbPath'])) {
            $this->tmp = $this->options['tmbPath'];
        }
        if (!$this->tmp && ($tmp = elFinder::getStaticVar('commonTempPath'))) {
            $this->tmp = $tmp;
        }
        
        // This driver dose not support `syncChkAsTs`
        $this->options['syncChkAsTs'] = false;

        // 'lsPlSleep' minmum 10 sec
        $this->options['lsPlSleep'] = max(10, $this->options['lsPlSleep']);
        
        return true;
    }


    /**
     * Configure after successfull mount.
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    protected function configure()
    {
        parent::configure();
                
        $this->disabled[] = 'archive';
        $this->disabled[] = 'extract';
    }
     
	 /**
     * Obtains a new access token from OAuth. This token is valid for one hour.
     *
     * @param string $clientSecret The OneDrive client secret.
     * @param string $code         The code returned by OneDrive after
     *                             successful log in.
     * @param string $redirectUri  Must be the same as the redirect URI passed
     *                             to LoginUrl.
     *
     * @throws \Exception Thrown if this Client instance's clientId is not set.
     * @throws \Exception Thrown if the redirect URI of this Client instance's
     *                    state is not set.
     */
    private function obtainAccessToken($client_id, $client_secret, $code)
    {
        if (null === $client_id) {
            return 'The client ID must be set to call obtainAccessToken()';
        }

        if (null === $client_secret) {
            return 'The client Secret must be set to call obtainAccessToken()';
        }

        $url = self::TOKEN_URL;

        $curl = curl_init();

        $fields = http_build_query(
            array(
                'client_id'     => $client_id,
                'redirect_uri'  => $this->getConnectorUrl(),
                'client_secret' => $client_secret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
            )
        );

        curl_setopt_array($curl, array(
            // General options.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $fields,

            CURLOPT_HTTPHEADER => array(
                'Content-Length: ' . strlen($fields),
            ),

            // SSL options.
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_URL            => $url,
        ));

        $result = curl_exec($curl);

        if (false === $result) {
            if (curl_errno($curl)) {
                throw new \Exception('curl_setopt_array() failed: '
                    . curl_error($curl));
            } else {
                throw new \Exception('curl_setopt_array(): empty response');
            }
        }

        $decoded = json_decode($result);

        if (null === $decoded) {
            throw new \Exception('json_decode() failed');
        }
        	
		$token = $this->session->get('elFinderOneDriveTokens');		
		$token->redirect_uri = null;
		
		$token->token = (object) array(
				'obtained' => time(),
				'data'     => $decoded,
        );
		
		return $token;
    }
	       
     /**
     * Get token and auto refresh
     * 
     * @param object $client OneDrive API client
     * @return true|string error message
     */
    private function refreshOneDriveToken($onedrive)
    {
        try {         
            if (0 >= ($onedrive->token->obtained + $onedrive->token->data->expires_in - time())) {			
					           	
				if (null === $this->options['client_id']) {
					$this->options['client_id'] = ELFINDER_ONEDRIVE_CLIENTID;
				}
				
				if (null === $this->options['client_secret']) {
					$this->options['client_secret'] = ELFINDER_ONEDRIVE_CLIENTSECRET;
				}
				
				if (null === $this->onedrive->token->data->refresh_token) {
					return 'The refresh token is not set or no permission for \'wl.offline_access\' was given to renew the token';
				}
					
				$url = self::TOKEN_URL;
		
				$curl = curl_init();
		
				curl_setopt_array($curl, array(
					// General options.
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_AUTOREFERER    => true,
					CURLOPT_POST           => 1, // i am sending post data
					CURLOPT_POSTFIELDS     => 'client_id=' . urlencode($this->options['client_id'])
						. '&client_secret=' . urlencode($this->options['client_secret'])
						. '&grant_type=refresh_token'
						. '&refresh_token=' . urlencode($onedrive->token->data->refresh_token),
								
					// SSL options.
					CURLOPT_SSL_VERIFYHOST => false,
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_URL            => $url,
				));
		
				$result = curl_exec($curl);
		
				if (false === $result) {
					if (curl_errno($curl)) {
						throw new \Exception('curl_setopt_array() failed: ' . curl_error($curl));
					} else {
						throw new \Exception('curl_setopt_array(): empty response');
					}
				}
		
				$decoded = json_decode($result);
		
				if (null === $decoded) {
					throw new \Exception('json_decode() failed');
				}
								
				$onedrive->token = (object) array(
						'obtained' => time(),
						'data'     => $decoded,
				);
				
				$this->session->set('elFinderOneDriveAuthTokens', $onedrive);            	
				$this->options['accessToken'] = json_encode($this->session->get('elFinderOneDriveAuthTokens'));
				$this->onedrive = json_decode($this->options['accessToken']);
			}
		} catch (Exception $e) {
			return $e->getMessage();
		}
        return true;
    }
    
    /**
     * Get dat(onedrive metadata) from OneDrive
     * 
     * @param string $path
     * @return array onedrive metadata
     */
    private function getPathtoName($path)
    {
		try { 
        	$itemId = basename($path);                 
			$res  = $this->query($itemId, $fetch_self = true);
		
        	return $res->name;
		} catch (Exception $e) {
            return $e->getMessage();
        }
    }
    
	/**
     * Creates a base cURL object which is compatible with the OneDrive API.   
     *
     * @return resource A compatible cURL object.
     */
    private function _prepareCurl()
    {
        $curl = curl_init();

        $defaultOptions = array(
            // General options.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => true,
        );

        curl_setopt_array($curl, $defaultOptions);
        return $curl;
    }
	
	/**
     * Creates a base cURL object which is compatible with the OneDrive API.
     *
     * @param string $path    The path of the API call (eg. me/skydrive).     
     *
     * @return resource A compatible cURL object.
     */
	private function _createCurl($path, $contents =false)
	{	
		$curl = curl_init($path); 
		curl_setopt($curl, CURLOPT_FAILONERROR, true); 
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); 
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false); 
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		   
		if($contents){
			return curl_exec($curl);
		}else{
			$result = json_decode(curl_exec($curl));
			if(isset($result->data)){ 
				return $result->data;
			}else{
				return $result;
			}
		}
	}
		    
    /**
     * Drive query and fetchAll
     * 
     * @param string $sql
     * @return boolean|array
     */
    private function query($itemId, $fetch_self = false, $recursive = false)
    {						
        $result = [];
		        
        if (null === $itemId) {
            $itemId = 'me/skydrive';
        }		

		if($fetch_self == true){
			$path = $itemId;
		}else{
			$path = $itemId.'/files';
		}
		
		$url = self::API_URL . $path
            		. '?access_token=' . urlencode($this->onedrive->token->data->access_token);

		if ($recursive) {			
			foreach ($this->_createCurl($url) as $file) {
				if($file->type == 'folder'){				
					$result[] = $file;
					$result = array_merge($result, $this->query($file->id, $root = false, $recursive = true));			        		
				}else{
					$result[] = $file;
				}			 											
			}
		}else{			
			$result = $this->_createCurl($url);
		}
         
        return $result;
    }
	     
    /**
     * Get dat(onedrive metadata) from OneDrive
     * 
     * @param string $path
     * @return array onedrive metadata
     */
    private function getDBdat($path)
    {  
		if($path == '/'){
			$res = $this->query('me/skydrive', $fetch_self = true);			
			return $res;
		}
	 
       empty($this->HasdirsCache[$path]) ? $HasPath = $path : $HasPath = $this->HasdirsCache[$path][0];
	   	   
       $itemId = basename($HasPath);
        
       try {		
            $res = $this->query($itemId, $fetch_self = true);			
            return $res;
        } catch (Exception $e) {
			return array();
		}
    }
    

    /*********************************************************************/
    /*                               FS API                              */
    /*********************************************************************/

    /**
     * Close opened connection
     *
     * @return void
     * @author Dmitry (dio) Levashov
     **/
    public function umount()
    {
    }
        
    /**
     * Parse line from onedrive metadata output and return file stat (array)
     *
     * @param  string  $raw  line from ftp_rawlist() output
     * @return array
     * @author Dmitry Levashov
     **/
    protected function parseRaw($raw)
    {		
        $stat = array();
	
		$stat['rev']	= $raw->id;
        $stat['name']	= $raw->name;
        $stat['mime']	= $raw->type =='folder' ? 'directory' : parent::$mimetypes[pathinfo($raw->name, PATHINFO_EXTENSION)];		
        $stat['size']	= $raw->type =='folder' ? 0 : (int)$raw->size;
        $stat['ts']		= $raw->updated_time !== null ? strtotime($raw->updated_time) : $_SERVER['REQUEST_TIME'];
        $stat['dirs']	= $raw->type =='folder' ? 1 : 0;
        $stat['url']	= '1';
	    
		if ($raw->type !=='folder') {
            isset($raw->width) ? $stat['width'] = $raw->width : $stat['width'] = 0;
            isset($raw->height)? $stat['height']= $raw->height: $stat['height']= 0;
        }
		
        return $stat;
    }

    /**
     * Cache dir contents
     *
     * @param  string  $path  dir path
     * @return void
     * @author Dmitry Levashov
     **/
    protected function cacheDir($path)
    {		
        $path == '/' ? $HasPath= '/' : (empty($this->HasdirsCache[$path]) ? $HasPath = $path : $HasPath = $this->HasdirsCache[$path][0]);
        if($HasPath == '/'){
		  $items = $this->query('me/skydrive',$fetch_self=true);   // get root directory with folder & files
			$itemId = $items->id; 			 
		}else{
			$itemId = basename($HasPath);
		}
        
        
        $this->dirsCache[$path] = array();
        $res = $this->query($itemId);
        
        $path == '/' ? $mountPath = '/' : $mountPath = $this->_normpath($HasPath.'/');
        
        if ($res) {
            foreach ($res as $raw) {
                if ($stat = $this->parseRaw($raw)) {
                    $stat = $this->updateCache($mountPath.$raw->id, $stat);
                    if (empty($stat['hidden']) && $path !== $mountPath.$raw->id) {
                        $this->dirsCache[$path][] = $mountPath.$raw->id;
						$this->HasdirsCache[$this->_normpath($path.'/'.$raw->name)][] = $mountPath.$raw->id;
                    }
                }
            }
        }

        
        return $this->dirsCache[$path];
    }

    /**
    * Recursive files search
    *
    * @param  string  $path   dir path
    * @param  string  $q      search string
    * @param  array   $mimes
    * @return array
    * @author Naoki Sawada
    **/
    protected function doSearch($path, $q, $mimes)
    {
        $path == '/' ? $itemId= 'me/skydrive' : $itemId= basename($path); 		   
    	empty($mimes) ? $mimeType = parent::$mimetypes[strtolower($q)] :
						$mimeType = parent::$mimetypes[strtolower(explode("/",$mimes[0])[1])];
		
		$path = $this->_normpath($path.'/');		
		$result = [];
			
		$res = $this->query($itemId); 
				
		foreach ($res as $raw) {		
			if ($raw->type =='folder')  {
				$result = array_merge($result, $this->doSearch($path.$raw->id, $q, $mimes));									
			}
			else
			{				   
				$timeout = $this->options['searchTimeout']? $this->searchStart + $this->options['searchTimeout'] : 0;
			
				if ($timeout && $timeout < time()) {
					$this->setError(elFinder::ERROR_SEARCH_TIMEOUT, $this->path($this->encode($path)));
					break;
				}
				if ((!empty($mimeType) && parent::$mimetypes[pathinfo($raw->name, PATHINFO_EXTENSION)] !== $mimeType) || (empty($mimeType) && strcasecmp($raw->name,$q))){ 
					continue;
				}						
				if ($stat = $this->parseRaw($raw)) {
					if (!isset($this->cache[$path.$raw->id])) {
						$stat = $this->updateCache($path.$raw->id, $stat);
					}
					if (!empty($stat['hidden']) || ($mimes && $stat['mime'] === 'directory') || !$this->mimeAccepted($stat['mime'], $mimes)) {
						continue;
					}

				$stat = $this->stat($path.$raw->id);
				$stat['path'] = $this->path($stat['hash']);
				$result[] = $stat;
				}
				
			}
		}
		
		return $result;
    }
    
    /**
    * Copy file/recursive copy dir only in current volume.
    * Return new file path or false.
    *
    * @param  string  $src   source path
    * @param  string  $dst   destination dir path
    * @param  string  $name  new file name (optionaly)
    * @return string|false
    * @author Dmitry (dio) Levashov
    * @author Naoki Sawada
    **/
    protected function copy($src, $dst, $name)
    {
        $this->clearcache();
        
        if (explode(".",basename($src))[0] == 'folder') {
            $itemId = basename($this->_mkdir($dst, $name));
            $path = $this->_joinPath($dst, $itemId);
                            
            $res = $this->query(basename($src));
            foreach ($res as $raw) {                
				$raw->type =='folder' ? $this->copy($src.'/'.$raw->id, $path, $raw->name) : $this->_copy($src.'/'.$raw->id, $path, $raw->name);
            }
            
            return $itemId
            ? $this->_joinPath($dst, $itemId)
            : $this->setError(elFinder::ERROR_COPY, $this->_path($src));
        } else {
            $itemId = $this->_copy($src, $dst, $name);
            return $itemId
            ? $this->_joinPath($dst, $itemId)
            : $this->setError(elFinder::ERROR_COPY, $this->_path($src));
        }
    }
    
    /**
    * Remove file/ recursive remove dir
    *
    * @param  string  $path   file path
    * @param  bool    $force  try to remove even if file locked
    * @return bool
    * @author Dmitry (dio) Levashov
    * @author Naoki Sawada
    **/
    protected function remove($path, $force = false, $recursive = false)
    {
        $stat = $this->stat($path);
        $stat['realpath'] = $path;
        $this->rmTmb($stat);
        $this->clearcache();
    
        if (empty($stat)) {
            return $this->setError(elFinder::ERROR_RM, $this->_path($path), elFinder::ERROR_FILE_NOT_FOUND);
        }
    
        if (!$force && !empty($stat['locked'])) {
            return $this->setError(elFinder::ERROR_LOCKED, $this->_path($path));
        }
    
        if ($stat['mime'] == 'directory') {
            if (!$recursive && !$this->_rmdir($path)) {
                return $this->setError(elFinder::ERROR_RM, $this->_path($path));
            }
        } else {
            if (!$recursive && !$this->_unlink($path)) {
                return $this->setError(elFinder::ERROR_RM, $this->_path($path));
            }
        }
    
        $this->removed[] = $stat;
        return true;
    }
    
    /**
    * Create thumnbnail and return it's URL on success
    *
    * @param  string  $path  file path
    * @param  string  $mime  file mime type

    * @return string|false
    * @author Dmitry (dio) Levashov
    * @author Naoki Sawada
    **/
    protected function createTmb($path, $stat)
    {
        if (!$stat || !$this->canCreateTmb($path, $stat)) {
            return false;
        }
            
        $name = $this->tmbname($stat);
        $tmb  = $this->tmbPath.DIRECTORY_SEPARATOR.$name;
    
        // copy image into tmbPath so some drivers does not store files on local fs
        if (! $data = $this->getThumbnail($path)) {
            return false;
        }
        if (! file_put_contents($tmb, $data)) {
            return false;
        }
        
        $result = false;
    
        $tmbSize = $this->tmbSize;
    
        if (($s = getimagesize($tmb)) == false) {
            return false;
        }
    
        /* If image smaller or equal thumbnail size - just fitting to thumbnail square */
        if ($s[0] <= $tmbSize && $s[1]  <= $tmbSize) {
            $result = $this->imgSquareFit($tmb, $tmbSize, $tmbSize, 'center', 'middle', $this->options['tmbBgColor'], 'png');
        } else {
            if ($this->options['tmbCrop']) {
    
                /* Resize and crop if image bigger than thumbnail */
                if (!(($s[0] > $tmbSize && $s[1] <= $tmbSize) || ($s[0] <= $tmbSize && $s[1] > $tmbSize)) || ($s[0] > $tmbSize && $s[1] > $tmbSize)) {
                    $result = $this->imgResize($tmb, $tmbSize, $tmbSize, true, false, 'png');
                }
    
                if (($s = getimagesize($tmb)) != false) {
                    $x = $s[0] > $tmbSize ? intval(($s[0] - $tmbSize)/2) : 0;
                    $y = $s[1] > $tmbSize ? intval(($s[1] - $tmbSize)/2) : 0;
                    $result = $this->imgCrop($tmb, $tmbSize, $tmbSize, $x, $y, 'png');
                }
            } else {
                $result = $this->imgResize($tmb, $tmbSize, $tmbSize, true, true, 'png');
            }
        
            $result = $this->imgSquareFit($tmb, $tmbSize, $tmbSize, 'center', 'middle', $this->options['tmbBgColor'], 'png');
        }
        
        if (!$result) {
            unlink($tmb);
            return false;
        }
    
        return $name;
    }
    
    /**
     * Return thumbnail file name for required file
     *
     * @param  array  $stat  file stat
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function tmbname($stat)
    {
        return $this->tmbPrefix.$stat['rev'].$stat['ts'].'.png';
    }
    
    /**
     * Get thumbnail from OneDrive.com
     * @param string $path
     * @param string $size
     * @return string | boolean
     */
    protected function getThumbnail($path)
    {        					
        $itemId = basename($path);
        
        try {			
			$url = self::API_URL . $itemId.'/content'
            . '?access_token=' . urlencode($this->onedrive->token->data->access_token);
			
			$contents = $this->_createCurl($url, $contents=true);					 	
            rewind($contents);            
			return $contents;		
           
        } catch (Exception $e) {
            return false;
        }
    }

	
    /**
    * Return content URL
    *
    * @param string  $hash  file hash
    * @param array $options options
    * @return array
    * @author Naoki Sawada
    **/
    public function getContentUrl($hash, $options = array())
	{
	
        if (($file = $this->file($hash)) == false || !$file['url'] || $file['url'] == 1) {
            $path = $this->decode($hash);                 
			
			$itemId = basename($path);
			$file  = $this->query($itemId,$fetch_self =true);
             							
			if ($url = $file->source) {				
				return $url;					
			}
			             							
		}
		
		return $file['url'];
	}
   
    /*********************** paths/urls *************************/

    /**
     * Return parent directory path
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dirname($path)
    {
        return $this->_normpath(dirname($path));
    }

    /**
     * Return file name
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _basename($path)
    {
        return basename($path);
    }

    /**
     * Join dir name and file name and retur full path
     *
     * @param  string  $dir
     * @param  string  $name
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _joinPath($dir, $name)
    {
        return $this->_normpath($dir.'/'.$name);
    }

    /**
     * Return normalized path, this works the same as os.path.normpath() in Python
     *
     * @param  string  $path  path
     * @return string
     * @author Troex Nevelin
     **/
    protected function _normpath($path)
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        }
        $path = '/' . ltrim($path, '/');
        
        return $path;
    }

    /**
     * Return file path related to root dir
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _relpath($path)
    {
        return $path;
    }

    /**
     * Convert path related to root dir into real path
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _abspath($path)
    {
        return $path;
    }

    /**
     * Return fake path started from root dir
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _path($path)
    {
        return $this->rootName . $this->_normpath(substr($path, strlen($this->root)));
    }

    /**
     * Return true if $path is children of $parent
     *
     * @param  string  $path    path to check
     * @param  string  $parent  parent path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _inpath($path, $parent)
    {
        return $path == $parent || strpos($path, $parent.'/') === 0;
    }

    /***************** file stat ********************/
    /**
     * Return stat for given path.
     * Stat contains following fields:
     * - (int)    size    file size in b. required
     * - (int)    ts      file modification time in unix time. required
     * - (string) mime    mimetype. required for folders, others - optionally
     * - (bool)   read    read permissions. required
     * - (bool)   write   write permissions. required
     * - (bool)   locked  is object locked. optionally
     * - (bool)   hidden  is object hidden. optionally
     * - (string) alias   for symlinks - link target path relative to root path. optionally
     * - (string) target  for symlinks - link target path. optionally
     *
     * If file does not exists - returns empty array or false.
     *
     * @param  string  $path    file path
     * @return array|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _stat($path)
    {
        if ($raw = $this->getDBdat($path)) {
            return $this->parseRaw($raw);
        }
        return false;
    }

    /**
     * Return true if path is dir and has at least one childs directory
     *
     * @param  string  $path  dir path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _subdirs($path)
    {
        return ($stat = $this->stat($path)) && isset($stat['dirs']) ? $stat['dirs'] : false;
    }

    /**
     * Return object width and height
     * Ususaly used for images, but can be realize for video etc...
     *
     * @param  string  $path  file path
     * @param  string  $mime  file mime type
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dimensions($path, $mime)
    {
        if (strpos($mime, 'image') !== 0) {
            return '';
        }
		
		$cache = $this->getDBdat($path);
        
        if (isset($cache->width) && isset($cache->height)) {
            return $cache->width.'x'.$cache->height;
        }
		
        $ret = '';
        if ($work = $this->getWorkFile($path)) {
            if ($size = @getimagesize($work)) {
                $cache['width'] = $size[0];
                $cache['height'] = $size[1];
                $ret = $size[0].'x'.$size[1];
            }
        }
        is_file($work) && @unlink($work);
        return $ret;
    }

    /******************** file/dir content *********************/

    /**
     * Return files list in directory.
     *
     * @param  string  $path  dir path
     * @return array
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     **/
    protected function _scandir($path)
    {
        return isset($this->dirsCache[$path])
            ? $this->dirsCache[$path]
            : $this->cacheDir($path);
    }

    /**
     * Open file and return file pointer
     *
     * @param  string  $path  file path
     * @param  bool    $write open file for writing
     * @return resource|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _fopen($path, $mode='rb')
    {	
        if (($mode == 'rb' || $mode == 'r')) {
            try {                						
                $itemId = basename($path);                            
                $url = self::API_URL . $itemId.'/content'
            				. '?access_token=' . urlencode($this->onedrive->token->data->access_token);
			
				$contents = $this->_createCurl($url, $contents=true);					 	
            	
                $fp = tmpfile();
                fputs($fp , $contents);                
                rewind($fp);
								
                return $fp;
            } catch (Exception $e) {
                return false;
            }
        }
        
        if ($this->tmp) {
            $contents = $this->_getContents($path);
            
            if ($contents === false) {
                return false;
            }
            
            if ($local = $this->getTempFile($path)) {
                if (file_put_contents($local, $contents, LOCK_EX) !== false) {
                    return @fopen($local, $mode);
                }
            }
        }

        return false;
    }

    /**
     * Close opened file
     *
     * @param  resource  $fp  file pointer
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _fclose($fp, $path='')
    {
        fclose($fp);
        if ($path) {
            unlink($this->getTempFile($path));
        }
    }

    /********************  file/dir manipulations *************************/

    /**
     * Create dir and return created dir path or false on failed
     *
     * @param  string  $path  parent dir path
     * @param string  $name  new directory name
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _mkdir($path, $name)
    {	
        $path = $this->_normpath($path.'/'.$name);
        basename(dirname($path)) == '' ? $parentId = 'me/skydrive' : $parentId = basename(dirname($path));
        
        try {
			$properties = array(
            	'name' => (string) $name,
        	);
		   
			$data = (object) $properties;
			
			$url  = self::API_URL .$parentId;
			
			$curl = $this->_prepareCurl();
			
			curl_setopt_array($curl, array(
            	CURLOPT_URL        => $url,
            	CURLOPT_POST       => true,

            	CURLOPT_HTTPHEADER => array(
                	// The data is sent as JSON as per OneDrive documentation.
                	'Content-Type: application/json',

                	'Authorization: Bearer ' . $this->onedrive->token->data->access_token,
            	),

            	CURLOPT_POSTFIELDS => json_encode($data),
        	));
					
			//create the Folder in the Parent
			$result = curl_exec($curl);
            $folder = json_decode($result);
						             
            basename(dirname($path)) == '' ? $path = '/'.$folder->id : $path = dirname($path).'/'.$folder->id;
            
        } catch (Exception $e) {		
            return $this->setError('OneDrive error: '.$e->getMessage());
        }

        return $path;
    }

    /**
     * Create file and return it's path or false on failed
     *
     * @param  string  $path  parent dir path
     * @param string  $name  new file name
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _mkfile($path, $name)
    {
        $path == '/' ? $path = $path.$name : $path = $path.'/'.$name;
        return $this->_filePutContents($path, '');
    }

    /**
     * Create symlink. FTP driver does not support symlinks.
     *
     * @param  string  $target  link target
     * @param  string  $path    symlink path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _symlink($target, $path, $name)
    {
        return false;
    }

    /**
     * Copy file into another file
     *
     * @param  string  $source     source file path
     * @param  string  $targetDir  target directory path
     * @param  string  $name       new file name
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _copy($source, $targetDir, $name)
    {
        $path = $this->_normpath($targetDir.'/'.$name);
        
        try {                                                
            //Set the Parent id			
            $targetDir == '/' ? $parentId = 'me/skydrive' : $parentId = basename($targetDir);
            $properties = array('destination'=>$parentId);
			
			$url  = self::API_URL . basename($source);
        	$data = (object) $properties;
			
        	$curl = $this->_prepareCurl();           
            
			curl_setopt_array($curl, array(
				CURLOPT_URL           => $url,
				CURLOPT_CUSTOMREQUEST => 'COPY',
	
				CURLOPT_HTTPHEADER    => array(
					// The data is sent as JSON as per OneDrive documentation.
					'Content-Type: application/json',
	
					'Authorization: Bearer ' . $this->onedrive->token->data->access_token,
				),
	
				CURLOPT_POSTFIELDS    => json_encode($data),
			));
			
			//copy File or Folder in the Parent
			$result = json_decode(curl_exec($curl));
			
			return $result->id;
			
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }
        return true;
    }

    /**
     * Move file into another parent dir.
     * Return new file path or false.
     *
     * @param  string  $source  source file path
     * @param  string  $target  target dir path
     * @param  string  $name    file name
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _move($source, $targetDir, $name)
    {
       // $target = $this->_normpath($targetDir.'/'.basename($source));
		
        try {
            //moving and renaming a file or directory                                  
            //Set new Parent and remove old parent				
            $targetDir == '/' ? $targetParentId = 'me/skydrive' : $targetParentId = basename($targetDir);
			$target = $this->_normpath($targetDir.'/'.basename($source));
			
			$itemId = basename($source);
            $file = $this->query($itemId, $fetch_self = true);
			//rename file or folder			
			if($file->name !== $name){
				$properties = array('name'=>$name);
				$comments = 'Rename Object';
				$properties = (object) $properties;
				$encoded    = json_encode($properties);
				
				$url   = self::API_URL . $itemId;
				
				$curl = $this->_prepareCurl();
			
				$headers = array(
            		'Authorization: Bearer ' . $this->onedrive->token->data->access_token,
					'Content-Type: ' . 'application/json',
        		);
				
				$stream = 'Rename Object';
				$stream  = fopen('php://' . ($stream ? 'temp' : 'memory'), 'rw+b');

				if (false === $stream) {
					throw new \Exception('fopen() failed');
				}
		
				if (false === fwrite($stream, $encoded)) {
					throw new \Exception('fwrite() failed');
				}
		
				if (!rewind($stream)) {
					throw new \Exception('rewind() failed');
				}
				$stats = fstat($stream);
				
				$options = array(
					CURLOPT_URL        => $url,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_PUT        => true,
					CURLOPT_INFILE     => $stream,
					CURLOPT_INFILESIZE => $stats[7], // Size
				);
        		curl_setopt_array($curl, $options);
				
				//rename File or Folder in the Parent
				$result = curl_exec($curl);            									
				
			}else{
				//move file or folder in destination target					
				$properties = array('destination'=>$targetParentId);				
				$url  = self::API_URL . $itemId;
        		$data = (object) $properties;			
				
				$curl = $this->_prepareCurl();
				
				curl_setopt_array($curl, array(
					CURLOPT_URL           => $url,
					CURLOPT_CUSTOMREQUEST => 'MOVE',
		
					CURLOPT_HTTPHEADER    => array(
						// The data is sent as JSON as per OneDrive documentation.
						'Content-Type: application/json',
		
						'Authorization: Bearer ' . $this->onedrive->token->data->access_token,
					),
		
					CURLOPT_POSTFIELDS    => json_encode($data),
				));
				
				//move File or Folder in the Target
				$result = curl_exec($curl);
				
			}
        } catch (Exception $e) {		
            return $this->setError('OneDrive error: '.$e->getMessage());
        }
        
        return $target;
    }
	
	
    /**
     * Remove file
     *
     * @param  string  $path  file path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _unlink($path)
    {
        try {
			$itemId = basename($path);
			
			$url = self::API_URL . $itemId
            . '?access_token=' . urlencode($this->onedrive->token->data->access_token);

        	$curl = $this->_prepareCurl();
			curl_setopt_array($curl, array(
				CURLOPT_URL           => $url,
				CURLOPT_CUSTOMREQUEST => 'DELETE',
			));
            
			//unlink or delete File or Folder in the Parent
			$result = curl_exec($curl);            
			         
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }
        return true;
    }

    /**
     * Remove dir
     *
     * @param  string  $path  dir path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _rmdir($path)
    {
        return $this->_unlink($path);
    }

    /**
     * Create new file and write into it from file pointer.
     * Return new file path or false on error.
     *
     * @param  resource  $fp   file pointer
     * @param  string    $dir  target dir path
     * @param  string    $name file name
     * @param  array     $stat file stat (required by some virtual fs)
     * @return bool|string
     * @author Dmitry (dio) Levashov
     **/
    protected function _save($fp, $path, $name, $stat)
    {		
        if ($name) {
            $path .= '/'.$name;
        }
        $path = $this->_normpath($path);

        try {
			if(empty($name) && empty($stat) & strpos(basename($path),'file') !== false && strpos(basename($path),'!') !== false){
				$file = $this->query(basename($path), $fetch_self = true);				
				$itemId = $file->id;
				$name = $file->name;
				$parentId = $file->parent_id;		
			}elseif(!empty($stat['rev'])){
				$itemId = $stat['rev'];
				$name = $stat['name'];
				$parentId = 'folder.'.explode(".",$stat['rev'])[1];
			}elseif(empty($name) && empty($stat) && strpos(basename($path),'file') == false && strpos(basename($path),'!') == false){	
				$name = basename($path);			
				basename(dirname($path)) == '' ? $parentId = 'me/skydrive' : $parentId = basename(dirname($path));
			}elseif(!empty($name) && !empty($stat)){
				$name = $name;			
				basename(dirname($path)) == '' ? $parentId = 'me/skydrive' : $parentId = basename(dirname($path));
				$res = $this->query($parentId);
				foreach($res as $f){		
					if($f->name == $name){					
						return $path.'/'.$f->id();
						break;
					}
				}
			}
            //Create or Update a file            				
			$content = stream_get_contents($fp);                
			$content =  strlen($content) == 0 ? ' ' : $content; 
			
			if (is_resource($content)) {
				$stream = $content;
			} else {
				$options = array(
					'stream_back_end' => 'php://temp',
				);
	
				$stream = fopen($options['stream_back_end'], 'rw+b');
					
				if (false === $stream) {
					throw new \Exception('fopen() failed');
				}
	
				if (false === fwrite($stream, $content)) {
					fclose($stream);
					throw new \Exception('fwrite() failed');
				}
	
				if (!rewind($stream)) {
					fclose($stream);
					throw new \Exception('rewind() failed');
				}
			}
			
			$params = array('overwrite'=>'true');
			$query = http_build_query($params);
			
			$url   = self::API_URL . $parentId. '/files/' . urlencode($name) . "?$query";
        	$curl  = $this->_prepareCurl();
        	$stats = fstat($stream);
			
			$headers = array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $this->onedrive->token->data->access_token,
			);
			
			$options = array(
				CURLOPT_URL        => $url,
				CURLOPT_HTTPHEADER => $headers,
				CURLOPT_PUT        => true,
				CURLOPT_INFILE     => $stream,
				CURLOPT_INFILESIZE => $stats[7], // Size
			);

        	curl_setopt_array($curl, $options);
			
			//create or update File in the Target
			$file = json_decode(curl_exec($curl));			
			
			if (!is_resource($content)) {
				fclose($stream);
			}
				
            
           
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }
        $path = $this->_normpath(dirname($path).'/'.$file->id);
        
        return $path;
    }

    /**
     * Get file contents
     *
     * @param  string  $path  file path
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _getContents($path)
    {
        $contents = '';
        
        try {           					
            $itemId = basename($path);            
            $url = self::API_URL . $itemId.'/content'
            			. '?access_token=' . urlencode($this->onedrive->token->data->access_token);
			
			$contents = $this->_createCurl($url, $contents=true);            
			
			rewind($contents);
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }
        
        return $contents;
    }

    /**
     * Write a string to a file
     *
     * @param  string  $path     file path
     * @param  string  $content  new file content
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _filePutContents($path, $content)
    {
        $res = false;
        
        if ($local = $this->getTempFile($path)) {
            if (file_put_contents($local, $content, LOCK_EX) !== false
            && ($fp = fopen($local, 'rb'))) {
                clearstatcache();
                $res = $this->_save($fp, $path, '', array());
                fclose($fp);

            }
            file_exists($local) && unlink($local);
        }

        return $res;
    }

	
    /**
     * Detect available archivers
     *
     * @return void
     **/
    protected function _checkArchivers()
    {
        // die('Not yet implemented. (_checkArchivers)');
        return array();
    }

    /**
     * chmod implementation
     *
     * @return bool
     **/
    protected function _chmod($path, $mode)
    {
        return false;
    }

    /**
     * Unpack archive
     *
     * @param  string  $path  archive path
     * @param  array   $arc   archiver command and arguments (same as in $this->archivers)
     * @return true
     * @return void
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     **/
    protected function _unpack($path, $arc)
    {
        die('Not yet implemented. (_unpack)');
        //return false;
    }

    /**
     * Recursive symlinks search
     *
     * @param  string  $path  file/dir path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _findSymlinks($path)
    {
        die('Not yet implemented. (_findSymlinks)');
    }

    /**
     * Extract files from archive
     *
     * @param  string  $path  archive path
     * @param  array   $arc   archiver command and arguments (same as in $this->archivers)
     * @return true
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _extract($path, $arc)
    {
        die('Not yet implemented. (_extract)');
    }

    /**
     * Create archive and return its path
     *
     * @param  string  $dir    target dir
     * @param  array   $files  files names list
     * @param  string  $name   archive name
     * @param  array   $arc    archiver options
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _archive($dir, $files, $name, $arc)
    {
        die('Not yet implemented. (_archive)');
    }
} // END class

