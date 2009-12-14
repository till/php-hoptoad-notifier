<?php
require_once dirname(__FILE__) . '/spyc.php';

/**
 * @category error
 * @package  Services_Hoptoad
 * @author   Rich Cavanaugh
 * @license  
 */
class Services_Hoptoad
{
    protected static $reportESTRICT = false;

    public static $apiKey;

    /**
     * Install the error and exception handlers that connect to Hoptoad
     *
     * @return void
     * @author Rich Cavanaugh
     */
    public static function installHandlers($api_key = NULL)
    {
        if (isset($api_key)) {
            self::$apiKey = $api_key;
        }
    
        set_error_handler(array("Hoptoad", "errorHandler"));
        set_exception_handler(array("Hoptoad", "exceptionHandler"));
    }
  
    /**
     * Handle a php error
     *
     * @param string $code 
     * @param string $message 
     * @param string $file 
     * @param string $line 
     * @return void
     * @author Rich Cavanaugh
     */
    public static function errorHandler($code, $message, $file, $line)
    {
        if ($code == E_STRICT && self::$reportESTRICT === false) {
            return;
        }

	    $trace = Hoptoad::tracer();
        Hoptoad::notifyHoptoad(HOPTOAD_API_KEY, $message, $file, $line, $trace, null);
    }
  
    /**
     * Handle a raised exception
     *
     * @param Exception $exception 
     *
     * @return void
     * @author Rich Cavanaugh
     * @uses   self::tracer()
     * @uses   self::notifyHoptoad()
     */
    public static function exceptionHandler(Exception $exception)
    {
        $trace = self::tracer($exception->getTrace());

        self::notifyHoptoad(
            self::$apiKey,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $trace,
            null
        );
    }
  
    /**
     * Pass the error and environment data on to Hoptoad
     *
     * @param string $api_key
     * @param string $message
     * @param string $file
     * @param string $line
     * @param array  $trace
     * @param mixed  $error_class
     *
     * @author Rich Cavanaugh
     */
    public static function notifyHoptoad($api_key, $message, $file, $line, $trace, $error_class = null)
    {
        array_unshift($trace, "$file:$line");

        $session = array();
        if (isset($_SESSION)) {
            $session = array('key' => session_id(), 'data' => $_SESSION);
        }

        $environment = array();
        if (isset($_SERVER)) {
            $environment['_SERVER'] = $_SERVER;
        }
        if (isset($_ENV)){
            $environment['_ENV'] = $_ENV;
        }
    
        $url  = "http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}"; // FIXME for cli
        $body = array(
            'api_key'         => $api_key,
            'error_class'     => $error_class,
            'error_message'   => $message,
            'backtrace'       => $trace,
            'request'         => array("params" => $_REQUEST, "url" => $url),
            'session'         => $session,
            'environment'     => $environment,
        );

	    $yaml = Spyc::YAMLDump(array("notice" => $body), 4, 60);

	    $curlHandle = curl_init(); // init curl

        // cURL options
        curl_setopt($curlHandle, CURLOPT_URL, 'http://hoptoadapp.com/notices/'); // set the url to fetch
        curl_setopt($curlHandle, CURLOPT_POST, 1);	
        curl_setopt($curlHandle, CURLOPT_HEADER, 0);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 10); // time to wait in seconds
	    curl_setopt($curlHandle, CURLOPT_POSTFIELDS,  $yaml);
	    curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array("Accept: text/xml, application/xml", "Content-type: application/x-yaml"));
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, 1);

        curl_exec($curlHandle);
        curl_close($curlHandle); 
    }
  
    /**
     * Build a trace that is formatted in the way Hoptoad expects
     *
     * @param string $trace 
     * @return array
     *
     * @author Rich Cavanaugh
     */
    public static function tracer($trace = NULL)
    {
        $lines = array(); 

        $trace = $trace ? $trace : debug_backtrace();
    
        $indent = '';
        $func   = '';
    
        foreach ($trace as $val) {
            if (isset($val['class']) && $val['class'] == 'Hoptoad') {
                continue;
            }
      
            $file        = isset($val['file']) ? $val['file'] : 'Unknown file';
            $line_number = isset($val['line']) ? $val['line'] : '';
            $func        = isset($val['function']) ? $val['function'] : '';
            $class       = isset($val['class']) ? $val['class'] : '';
      
            $line = $file;

            if ($line_number) {
                $line .= ':' . $line_number;
            }
            if ($func) {
                $line .= ' in function ' . $func;
            }
            if ($class) {
                $line .= ' in class ' . $class;
            }
            $lines[] = $line;
        }
    
        return $lines;
    }
}