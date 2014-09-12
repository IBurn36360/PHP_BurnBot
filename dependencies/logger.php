<?php
if (!defined('IN_PHPBURNBOT'))
{
    exit;
}

/**
 * Logger library
 * 
 * @author Anthony 'IBurn36360' Diaz
 * @final
 * @name Logger
 * @version 1.0.0
 * 
 * Handles writing and formatting logs
 */
final class logger
{
    protected $file;
    protected $handle;
    
    // Static vars
    protected $spacer = 17;
    
    public function __construct($file)
    {
        // does our log file exist?
        if (file_exists($file))
        {
            $this->handle = fopen($file, 'a');
        } else {
            $this->handle = fopen($file, 'w');
            fwrite($this->handle, '<?php exit; ?>');
        }
    }
    
    public function __destruct()
    {
        @fclose($this->handle);
    }
    
    /**
     * Writes a new line to the log as is
     * 
     * @param $str[string] - String line to be written
     */
    protected function writeLine($str)
    {
        @fwrite($this->handle, "\n" . str_replace("\r\n", ' ', $str));
    }
    
    /**
     * Logs a line of output to the log file
     * 
     * @param $line[string] - String message to log to the file
     * @param $module[string] - String module name for the log entry
     */
    public function logLine($line, $module = 'undefined')
    {
        $logTime = date('m-d-y[H:i:s]', time());
        $space = '';
        
        for ($i = 0; $i <= ($this->spacer - strlen($module)); $i++)
        {
            $space .= ' ';
        }
        
        $this->writeLine($logTime . $space . '[' . strtoupper($module) . "] $line");
    }
    
    /**
     * Logs an error to the file
     * 
     * @param $str[string] - string error to write to the file
     */
    public function logError($str)
    {
        $logTime = date('m-d-y[H:i:s]', time());
        $space = '';
        
        for ($i = 0; $i <= ($this->spacer - 5); $i++)
        {
            $space .= ' ';
        }
        
        $this->writeLine($logTime . $space . "[ERROR] $str");
    }
    
    /**
     * Our error handler, takes control of all trigger_error output
     * 
     * @param $errNo[int] - The integer error number
     * @param $errStr[string] - The string message of the error
     * @param $file[string] - The string filename for the error
     * @param $line[int] - The int line number of the error
     */
    public function logErrorHandler($errNo, $errStr, $file, $line)
    {
        $logTime = date('m-d-y[H:i:s]', time());
        $space = '';
        
        switch ($errNo)
        {
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $errLevel = 'DEPRECIATED';
                break;
            
            case E_USER_NOTICE:
            case E_NOTICE:
                $errLevel = 'NOTICE';
                break;
            
            case E_ERROR;
                $errLevel = 'FATAL';
                break;
            
            case E_STRICT:
                $errLevel = 'STRICT';
                break;
            
            case E_USER_ERROR:
                $errLevel = 'USER_ERROR';
                break;
            
            case E_USER_WARNING:
                $errLevel = 'USER_WARNING';
                break;
            
            case E_WARNING:
                $errLevel = 'WARNING';
                break;
            
            default:
                $errLevel = 'ERROR';
                break;
        }
        
        for ($i = 0; $i <= ($this->spacer - strlen($errLevel)); $i++)
        {
            $space .= ' ';
        }
        
        $this->writeLine($logTime . $space . "[$errLevel] $errStr In [$file:$line]");
        
        $stack = debug_backtrace();
        if (is_array($stack) && !empty($stack) && (count($stack) > 1))
        {
            array_shift($stack);
            $this->logStackTrace($stack);
        }
    }
    
    /**
     * Prints out the stacktrace for an error
     * 
     * @param - $stack[array] - the full stacktrace for the error
     */
    public function logStackTrace($stack)
    {
        foreach ($stack as $row)
        {
            // The extra checks here stop our custom error handlers from being part of the stack
            if (empty($row) || (isset($row['function']) && (($row['function'] == 'fatalCrashHandler') || ($row['function'] == 'exceptionHandler') || ($row['function'] == 'errorHandler'))))
            {
                continue;
            }
            
            $str = '';
            
            for ($i = 0; $i <= ($this->spacer + 27); $i++)
            {
                $str .= ' ';
            }
            
            if (isset($row['type']) && $row['type'])
            {
                $str .= 'From ' . $row['class'] . $row['type'] . $row['function'] . '(' . implode(', ', $row['args']) . ') Called at [' . $row['file'] . ':' . $row['line'] . ']';
            } else {
                $str .= 'From ' . $row['function'] . '(' . implode(', ', $row['args']) . ') Called at [' . $row['file'] . ':' . $row['line'] . ']';                
            }
            
            $this->writeLine($str);
        }
    }
    
    /**
     * Logs exceptions if they are thrown
     * 
     * @param #exception[object] - The exception object tossed when an exception is thrown
     */
    public function logException($exception)
    {
        $logTime = date('m-d-y[H:i:s]', time());
        $space = '';
        
        for ($i = 0; $i <= ($this->spacer - 9); $i++)
        {
            $space .= ' ';
        }
        
        $this->writeLine($logTime . $space . "[EXCEPTION] " . $exception->getMessage() . '. In file "' . $exception->getFile() . '" on line ' . $exception->getLine());
    }
    
    /**
     * Attempts to log a fatal error.  This is the shutdown function
     */
    public function logFatal()
    {
        $str = error_get_last();
        $logTime = date('m-d-y[H:i:s]', time());
        $space = '';
        
        for ($i = 0; $i <= ($this->spacer - 5); $i++)
        {
            $space .= ' ';
        }
        
        $this->writeLine($logTime . $space . "[FATAL] $str");
    }
}
?>