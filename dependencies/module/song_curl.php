<?php

// A lightweight library to perform cURL calls, a very stripped down version of the cURL implementation that the Twitch library uses

class song_cURL
{
    public function cURL_get($url, array $get = array(), array $options = array(), array $header = array())
    {
        $cURL_URL = rtrim($url . '?' . http_build_query($get), '?');
        
        $default = array(
            CURLOPT_URL => $cURL_URL, 
            CURLOPT_HEADER => 0, 
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $header
        );
        
        $handle = curl_init();
        
        if (function_exists('curl_setopt_array')) // Check to see if the function exists
        {
            curl_setopt_array($handle, ($options + $default));
        } else { // nope, set them one at a time
            foreach (($default + $options) as $key => $opt) // Options are set last so you can override anything you don't want to keep from defaults
            {
                curl_setopt($handle, $key, $opt);
            }
        }
        
        $result = curl_exec($handle);
        curl_close($handle);
        return $result; 
    }
    
    public function cURL_post($url, array $post = array(), array $options = array(), array $header = array())
    {
        $postfields = '';
        
        // Custom build the post fields
        foreach ($post as $field => $value)
        {
            $postfields .= $field . '=' . urlencode($value) . '&';
        }
        // Strip the trailing &
        $postfields = rtrim($postfields, '&');
        
        $default = array( 
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_URL => $url, 
            CURLOPT_POST => count($post),
            CURLOPT_HEADER => 0,
            CURLOPT_FRESH_CONNECT => 1, 
            CURLOPT_RETURNTRANSFER => 1, 
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_HTTPHEADER => $header
        );
        
        $handle = curl_init();
        
        if (function_exists('curl_setopt_array')) // Check to see if the function exists
        {
            curl_setopt_array($handle, ($options + $default));
        } else { // nope, set them one at a time
            foreach (($default + $options) as $key => $opt) // Options are set last so you can override anything you don't want to keep from defaults
            {
                curl_setopt($handle, $key, $opt);
            }
        }
        
        $result = curl_exec($handle);
        curl_close($handle);
        return $result; 
    }
    
    public function cURL_put($url, array $put = array(), array $options = array(), array $header = array())
    {
        $postfields = '';

        // Custom build the post fields
        $postfields = (is_array($put)) ? http_build_query($put) : $put;
        
        $default = array( 
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_URL => $url,
            CURLOPT_HEADER => 0,
            CURLOPT_FRESH_CONNECT => 1, 
            CURLOPT_RETURNTRANSFER => 1, 
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_HTTPHEADER => $header
        );
        
        $handle = curl_init();
        
        if (function_exists('curl_setopt_array')) // Check to see if the function exists
        {
            curl_setopt_array($handle, ($options + $default));
        } else { // nope, set them one at a time
            foreach (($default + $options) as $key => $opt) // Options are set last so you can override anything you don't want to keep from defaults
            {
                curl_setopt($handle, $key, $opt);
            }
        }
        
        $result = curl_exec($handle);
        curl_close($handle);
        return $result; 
    }
    
    public function cURL_delete($url, array $post = array(), array $options = array(), array $header = array())
    {
        $default = array(
            CURLOPT_URL => $url,
            CURLOPT_CONNECTTIMEOUT => 15, 
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADER => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $header
        );
        
        $handle = curl_init();
        
        if (function_exists('curl_setopt_array')) // Check to see if the function exists
        {
            curl_setopt_array($handle, ($options + $default));
        } else { // nope, set them one at a time
            foreach (($default + $options) as $key => $opt) // Options are set last so you can override anything you don't want to keep from defaults
            {
                curl_setopt($handle, $key, $opt);
            }
        }
        
        ob_start();
        $result = curl_exec($handle);
        curl_close($handle); 
        ob_end_clean();
        return $result; 
    }
}

?>