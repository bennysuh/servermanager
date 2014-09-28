#!/usr/bin/php
<?php
    error_reporting(-1);

    /**
     * Basic Constants
     */
    defined('__DIR__')      or define('__DIR__',    dirname(__FILE__));
    
    /**
     * Directories
     */
    define('MANAGERDIR',    '~/.mcmanager');
    define('SERVERDIR',     '~/servers');
    define('BACKUPDIR',     MANAGERDIR . '/backup');
    
    /**
     * Files
     */
    define('SCRIPT_RUN',    'run.sh');
    define('SCRIPT_STOP',   'stop.sh');
    define('SCRIPT_BACKUP', 'backup.sh');
    define('LOG_FILE',      'servermanager.log');
    
    /**
     * 
     */
    abstract class ServerManagerException extends Exception
    {
        /**
         *
         * @param string $message the exception message
         * @param int $code the exception code
         */
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
            Logger::write(LogType::UNCAUGHT_EXCEPTION, '{BLACK}{_RED}' . $message, LogDest::SCREEN);
        }
    }

    class ServerManagingException extends ServerManagerException
    {
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
        }
    }

    class ProcExceptiion extends ServerManagerException
    {
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
        }
    }

    class MissingDependencyException extends ServerManagerException
    {
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
        }
    }

    class ScreenException extends ServerManagerException
    {
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
        }
    }

    class IOException extends ServerManagerException
    {
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
        }
    }

    class DatabaseException extends ServerManagerException
    {
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
        }
    }

    class CommandException extends ServerManagerException
    {
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
        }
    }

    class NoValidCommandException extends CommandException
    {
        public function __construct($message, $code = 0)
        {
            parent::__construct($message, $code);
        }
    }
    
    class Proc
    {
        protected $command;
        protected $descriptorSpecs;
        protected $process;
        protected $pipes;
        protected $workingDir;
        
        
        /**
         *
         * @param string $process the process id/name
         * @param bool $numeric if a processID was given
         */
        public function __construct($cmd, $cwd = __DIR__, array $env = null)
        {
            $this->process = null;
            $this->command = $cmd;
            $this->descriptorSpecs = array(
                0 => array(
                    'pipe',
                    'r'
                ),
                1 => array(
                    'pipe',
                    'w'
                ),
                2 => array(
                    'pipe',
                    'w'
                )
            );
            $this->workingDir = $cwd;
            $this->env = ($env !== null ? $env : $_ENV);
        }
        
        public function __destruct()
        {
            $this->close();
        }
        
        public function open()
        {
            $this->process = proc_open($this->command, $this->descriptorSpecs, $this->pipes, $this->workingDir);
            if ($this->process === false)
            {
                throw new ProcException('Proc::open', 'Failed to open the new process!');
            }
        }
        
        public function close()
        {
            if (is_resource($this->process))
            {
                proc_close($this->process);
                $this->process = null;
            }
        }
        
        public function &getStdin()
        {
            return $this->pipes[0];
        }
        
        public function &getStdout()
        {
            return $this->pipes[1];
        }
        
        public function &getStderr()
        {
            return $this->pipes[2];
        }
        
        public function addEnv($name, $value)
        {
            $name = strval($name);
            $value = strval($value);
            $this->env[$name] = $value;
            
            return $this;
        }
        
        public function setEnv(array $env)
        {
            $this->env = $env;
        }
        
        public function getEnv($env = null)
        {
            if ($env === null)
            {
                return $this->env;
            }
            else
            {
                return (isset($this->env[$name]) ? $this->env[$name] : null);
            }
        }
        
        public function getWorkingDir()
        {
            return $this->workingDir;
        }
        
        public function setWorkingDir($dir)
        {
            $dir = trim(strval($dir));
            if (file_exists($dir))
            {
                $this->workingDir = $dir;
            }
            
            return $this;
        }
        
        public static function nice($increment)
        {
            return @proc_nice($increment);
        }
        
        public function terminate($signal = SIGTERM)
        {
            return proc_terminate($this->process, $signal);
        }
        
        public function getStatus()
        {
            return proc_get_status($this->process);
        }
        
        public function getPid()
        {
            $status = $this->getStatus();
            return $status['pid'];
        }
        
        public function running()
        {
            $status = $this->getStatus();
            return $status['running'];
        }
        
        /**
         *
         * @return bool true is the process is running, otherwise false
         */
        public static function runs($process)
        {
            $process = trim($process);
            $numeric = Util::is_numeric($process);

            if ($numeric)
            {
                $kill = CLI::exec("kill -0 $process");
                return ($kill[1] === 0);
            }
            else
            {
                $processes = Proc::ls();
                return (array_search($process, $processes) !== false);
            }
        }
        
        /**
         *
         * @param bool $force whether to force kill the process
         * @return bool true on success, otherwise false
         */
        public static function killPid($pid, $force = false)
        {
            if (!Util::is_numeric($pid))
            {
                throw new ProcException('The process ID must be a number!');
            }

            $cmd = 'kill ';
            $cmd .= ($force ? '-KILL' : '-SIGTERM');
            $cmd .= ' ' . $this->process;

            $kill = CLI::exec($cmd);
            
            return ($kill[1] === 0);
        }
        
        /**
         *
         * @return int[] associated array with imagenames as keys and PIDs as values
         */
        public static function ls()
        {
            $list = CLI::exec('ps -A -o "\"%a\",\"%p\""');
            $list = $list[0];
            unset($list[0]);
            
            $processes = array();
            foreach ($list as $entry)
            {
                $entry = explode(',', $entry);
                $name = trim($entry[0]);
                $name = trim(substr($name, 1, strlen($name) - 2));
                $pid = trim($entry[1]);
                $pid = intval(trim(substr($pid, 1, strlen($pid) - 2)));
                
                $processes[$pid] = $name;
            }
            
            return $processes;
        }
        
        public static function runAsync($path, array $args = array(), array $envs = array())
        {
            if (!function_exists('pcntl_fork'))
            {
                throw new ProcException('The required pcntl_fork function is not available!');
            }
            
            $pid = pcntl_fork();
            if ($pid == -1)
            {
                return false;
            }
            elseif ($pid)
            {
                return $pid;
            }
            else
            {
                pcntl_exec($path, $args, $envs);
            }
        }
        
        public static function callAsync($callback, array& $params = null)
        {
            if (!function_exists('pcntl_fork'))
            {
                throw new MissingDependencyException('The required pcntl_fork function is not available!');
            }
            
            if (!is_callable($callback))
            {
                throw new ProcException('The given callback is not callable!');
            }
            
            $pid = pcntl_fork();
            if ($pid == -1)
            {
                return false;
            }
            elseif ($pid)
            {
                return $pid;
            }
            else
            {
                if ($params === null)
                {
                    call_user_func($callback);
                }
                else
                {
                    call_user_func_array($callback, $params);
                }
            }
        }

        public static function execInBackground($command)
        {
            if (!function_exists('popen'))
            {
                throw new MissingDependencyException('The required function popen is not available!');
            }

            $handle = @popen($command, 'r');
            if ($handle === false)
            {
                return false;
            }

            fclose($handle);

            return true;
        }
    }
    
    class Screen
    {
        protected $name;
        protected $params;
        protected $pid;
        protected static $prefix = 'managed__';
        
        public static function ls()
        {
            $output = CLI::exec('screen -ls');
            $output = array_map('trim', $output[0]);
            
            $prefix = preg_quote(self::$prefix, '/');
            $screens = array();
            
            foreach ($output as $line)
            {
                $matches = array();
                $matched = preg_match('/^([\d]+)\.' . $prefix . '([\w]+)/', $line, $matches);
                if ($matched)
                {
                    $screens[$matches[2]] = $matches[1];
                }
            }
            
            return $screens;
        }
        
        public function __construct($name)
        {
            $this->name = $name;
            $this->params = array();
            $this->pid = null;
        }
        
        public function reattach()
        {
            if (!function_exists('pcntl_exec'))
            {
                throw new MissingDependencyException('The required function pcntl_exec does not exist!');
            }
            
            $TERM = getenv('TERM');
            if ($TERM === false)
            {
                throw new ScreenException('The TERM environment variable was not found!');
            }
            
            pcntl_exec(CLI::which('screen'), array('-r', '-S', self::$prefix . $this->name), array('TERM' => $TERM));
        }
        
        public function exists()
        {
            $screens = Screen::ls();
            return isset($screens[$this->name]);
        }
        
        public function start($cmd, array $params = array(), $outputLogging = false)
        {
            $cmd = 'screen -q' . ($outputLogging ? ' -L' : '') . ' -dmS "' . self::$prefix . $this->name . '"';
            foreach ($params as $param)
            {
                $cmd .= ' "' . strval($param) . '"';
            }
            $result = CLI::exec($cmd);
            
            return $this->getPid();
        }
        
        public function getPid()
        {
            if ($this->pid === null)
            {
                $screens = Screen::ls();
                if (isset($screens[$this->name]))
                {
                    $this->pid = $screens[$this->name];
                }
                else
                {
                    $this->pid = 0;
                }
            }
            
            return $this->pid;
        }
        
        public function tell(array $commands)
        {
            foreach ($commands as $command)
            {
                $command = trim($command);
                $result = CLI::exec('screen -S "' . self::$prefix . $this->name . '" -X stuff $\'' . $command . '\n\'');
            }
        }
    }
    
    class CLI
    {
        protected static $ANSI = array(
            '{CLEAR}'           => "\033[0m",
            '{BOLD}'            => "\033[1m",
            '{UNDERLINE}'       => "\033[4m",
            '{BLINK}'           => "\033[5m",
            '{INVERSE}'         => "\033[7m",

            '{BLACK}'           => "\033[0;30m",
            '{GRAY}'            => "\033[1;30m",
            '{BLUE}'            => "\033[0;34m",
            '{AQUA}'            => "\033[1;34m",
            '{GREEN}'           => "\033[0;32m",
            '{LIME}'            => "\033[1;32m",
            '{CYAN}'            => "\033[0;36m",
            '{RED}'             => "\033[0;31m",
            '{PINK}'            => "\033[1;31m",
            '{PURPLE}'          => "\033[0;35m",
            '{MAGENTA}'         => "\033[1;35m",
            '{BROWN}'           => "\033[0;33m",
            '{YELLOW}'          => "\033[1;33m",
            '{SILVER}'          => "\033[0;37m",
            '{WHITE}'           => "\033[1;37m",

            '{_BLACK}'          => "\033[40m",
            '{_RED}'            => "\033[41m",
            '{_GREEN}'          => "\033[42m",
            '{_YELLOW}'         => "\033[43m",
            '{_BLUE}'           => "\033[44m",
            '{_MAGENTA}'        => "\033[45m",
            '{_CYAN}'           => "\033[46m",
            '{_WHITE}'          => "\033[47m"
        );
        
        /**
         *
         * @param string $command the command to execute
         * @return mixed[] 
         */
        public static function exec($command)
        {
            $output = null;
            $return_var = null;
            exec($command, $output, $return_var);

            return array($output, $return_var);
        }
        
        /**
         *
         * @param string $string the string to print
         */
        public static function println($string)
        {
            Logger::write(LogType::INFO, CLI::parseAnsi($string), LogDest::BOTH);
        }
        
        /**
         *
         * @param string $string the string to parse
         * @param bool $autoclear whether to add {CLEAR} to the end
         * @return string the parsed string
         */
        public static function parseAnsi($string, $autoclear = true)
        {
            $string = str_replace(array_keys(self::$ANSI), array_values(self::$ANSI), $string);
            if ($autoclear)
            {
                $string .= self::$ANSI['{CLEAR}'];
            }
            
            return $string;
        }
        
        /**
         *
         * @param string $string the string to replace the codes in
         * @return string the clean string
         */
        public static function stripAnsi($string)
        {
            return str_replace(array_keys(self::$ANSI), '', $string);
        }
        
        /**
         * PHP implementation of the UNIX which command
         *
         * @param string $cmd the file to search
         * @return string the full path to the final
         */
        public static function which($cmd)
        {
            $PATH = getenv('PATH');
            if ($PATH === false)
            {
                return '';
            }
            
            $paths = explode(PATH_SEPARATOR, $PATH);
            foreach ($paths as $path)
            {
                $path = $path . DIRECTORY_SEPARATOR . $cmd;
                if (file_exists($path))
                {
                    return $path;
                }
            }
            return '';
        }
        
        public static function rmdir($dir)
        {
            $result = CLI::exec('rm -R -f "' . $dir . '"');
            return ($result[1] === 0);
        }
    }

    /**
     * 
     */
    class Util
    {
        /**
         *
         * @param string $string the string to check
         * @return bool true if the string consists of numbers, otherwise false
         */
        public static function is_numeric($string)
        {
            return (bool) preg_match('/^\d+$/', $string);
        }

        public static function wait($seconds)
        {
            $seconds = intval($seconds);
            Logger::write(LogType::INFO, 'Waiting for ' . $seconds . ' seconds');
            for ($i = 0; $i < $seconds; $i++)
            {
                echo '.';
                sleep(1);
            }
            echo "\n";
        }
        
        /**
         *
         * @param string $pattern the regex pattern to search
         * @param string $string the string to search in
         * @return bool whether the pattern was found or not
         */
        public static function matchesRegex($pattern, $string)
        {
            return ((bool) preg_match($pattern, $string));
        }

        /**
         *
         * @param string $pattern the pattern to search
         * @param string $string the string to search in
         * @param bool $caseinsensitive whether to search case sensitive or insensitive
         * @return bool whether the pattern was found or not
         */
        public static function matches($pattern, $string, $caseinsensitive = false)
        {
            $pattern = '/' . preg_quote($pattern, '/') . '/';
            if (!$caseinsensitive)
            {
                $pattern .= 'i';
            }
            return ((bool) preg_match($pattern, $string));
        }
        
        /**
         *
         * @param type $path
         * @param type $mode
         * @return type 
         */
        public static function isWritable($path, $mode = 0754)
        {
            if (!is_writable($path))
            {
                @chmod($path, $mode);
                return is_writable($path);
            }
            else
            {
                return true;
            }
        }
        
        /**
         *
         * @param type $path
         * @param type $mode 
         */
        public static function prepareDirectory($path, $mode = 0754)
        {
            if (!file_exists($path))
            {
                if (!@mkdir($path, $mode))
                {
                    throw new IOException('Failed to create the server directory!');
                }
            }
            if (!Util::isWritable($path, $mode))
            {
                throw new IOException('Failed to set the file permissions!');
            }
        }
    }
    
    /**
     * 
     */
    class LogType
    {
        const INFO                  = 0;
        const NOTICE                = 1;
        const WARNING               = 2;
        const ERROR                 = 3;
        const PHP_ERROR             = 4;
        const CAUGHT_EXCEPTION      = 5;
        const UNCAUGHT_EXCEPTION    = 6;
    }
    
    /**
     * 
     */
    class LogDest
    {
                                 // f = file
                                 // s = screen
                                 //
                                 //       fs
                                 //       ||
        const ALL       = -1;    // 11111111
        const SCREEN    =  1;    // 00000001
        const FILE      =  2;    // 00000010
    }
    
    /**
     * 
     */
    class Logger
    {
        protected static $file = null;
        
        protected static $typeStrings = array(
            LogType::INFO                  => 'Info',
            LogType::NOTICE                => 'Notice',
            LogType::WARNING               => 'Warning',
            LogType::ERROR                 => 'Error',
            LogType::PHP_ERROR             => 'PHP Error',
            LogType::CAUGHT_EXCEPTION      => 'Exception',
            LogType::UNCAUGHT_EXCEPTION    => 'Uncaught'
        );
        
        protected static function isBitSet($dest, $bit)
        {
            return (($dest & $bit) == $bit);
        }
        
        /**
         *
         * @param string $type the message type
         * @param string $text the text
         * @param bool $screenonly whether to print only on the screen
         */
        public static function write($type, $text, $destination = LogDest::ALL)
        {
            /*****DBG*****/
            $destination = LogDest::SCREEN;
            /**********/

            $typeStr = 'unknown';
            if (isset(self::$typeStrings[$type]))
            {
                $typeStr = self::$typeStrings[$type];
            }
            else
            {
                $typeStr = self::$typeStrings[LogType::INFO];
            }
            
            $pre = '[' . date('d.m. H:i:s') . "][$typeStr] ";
            
            if (self::isBitSet($destination, LogDest::SCREEN))
            {
                if ($type > LogType::WARNING)
                {
                    file_put_contents('php://stderr', $pre . CLI::parseAnsi($text) . "\n", FILE_APPEND);
                }
                else
                {
                    file_put_contents('php://stdout', $pre . CLI::parseAnsi($text) . "\n", FILE_APPEND);
                }
                flush();
            }
            
            if (self::isBitSet($destination, LogDest::FILE))
            {
                try
                {
                    self::open();
                    fwrite(self::$file, $pre . CLI::stripAnsi($text) . "\n");
                }
                catch(ServerManagerException $sme)
                {}
            }
        }
        
        /**
         * 
         */
        protected static function open()
        {
            if (self::$file === null)
            {
                if (!Util::isWritable(__DIR__))
                {
                    throw new IOException('Script path is not writable!');
                }
                self::$file = fopen(__DIR__ . DS . LOG_FILE, 'ab');
                if (self::$file === false)
                {
                    self::$file = null;
                    throw new IOException('Log could not be opened!');
                }
                self::write('Logger', 'Log opened');
            }
        }
    }
    
    abstract class AbstractDatabaseConfig
    {
        protected $config = array();
        
        public final function get($name)
        {
            return (isset($this->config[$name]) ? $this->config[$name] : null);
        }
    }

    final class MySQLConfig extends AbstractDatabaseConfig
    {
        protected $config = array(
            'host'      => 'localhost',
            'user'      => 'root',
            'pass'      => '$up3r$1ch3r',
            'dbname'    => 'servermanager',
            'charset'   => 'UTF8'
        );
    }
    
    final class SQLiteConfig extends AbstractDatabaseConfig
    {
        protected $config = array(
            'file'      => 'servers.sqlite'
        );
    }
    
    abstract class AbstractDatabaseBackend
    {
        
        /**
         * 
         */
        public static function factory(AbstractDatabaseConfig $config)
        {
            $class = preg_replace('/Config$/', 'Backend', get_class($config));
            if (class_exists($class))
            {
                return new $class($config);
            }
            else
            {
                throw new MissingDependencyException('Class ' . $class . ' not found!');
            }
        }
        
        public abstract function __construct(AbstractDatabaseConfig $config);
        
        /**
         * 
         */
        public abstract function connect();
        
        /**
         *
         * @param string $query the query
         * @param bool $return whether the query returns something
         * @return mixed[] the fetched result of the query
         */
        public abstract function query($query, $return = true);
        
        /**
         * 
         */
        public abstract function error();
    }
    
    final class MySQLBackend extends AbstractDatabaseBackend
    {
        protected $db;
        protected $connected = false;
        protected $config;
        
        public function __construct(MySQLConfig $config)
        {
            $this->config = $config;
            $this->connected = false;
        }
        
        public function connect()
        {
            if (!$this->connected)
            {
                $this->db = mysql_connect($this->config->get('host'), $this->config->get('user'), $this->config->get('pass'));
                if (!is_resource($this->db))
                {
                    throw new DatabaseException('Connection to the database failed!', 501);
                }
                if (!mysql_select_db($this->config->get('dbname'), $this->db))
                {
                    throw new DatabaseException('Database selection failed!', 502);
                }
                if ($this->config->get('charset'))
                {
                    @mysql_set_charset($this->config->get('charset'), $this->db);
                }
                $this->connected = true;
            }
        }
        
        /**
         *
         * @param string $query the query
         * @param bool $return whether the query returns something
         * @return mixed[] the fetched result of the query
         */
        public function query($query, $return = true)
        {
            $this->connect();
            
            $result = @mysql_unbuffered_query($query, $this->db);
            if ($result === false)
            {
                Logger::write(LogType::ERROR, 'Query failed! Query: "' . $query . '" Error: "' . $this->error() . '"', LogDest::FILE);
                throw new DatabaseException('The given query failed!');
            }
            
            if ($return)
            {
                $resultdata = array();
                while (($row = mysql_fetch_array($result, MYSQL_ASSOC)))
                {
                    $resultdata[] = $row;
                }
                return $resultdata;
            }
            else
            {
                return null;
            }
        }
        
        public function error()
        {
            return mysql_error($this->db);
        }
    }
    
    final class SQLiteBackend extends AbstractDatabaseBackend
    {
        protected $db;
        protected $error;
        protected $connected;
        protected $config;
        
        protected static $INIT_SQL = '
CREATE  TABLE "main"."servers" ("id" INTEGER PRIMARY KEY  AUTOINCREMENT  NOT NULL , "pid" INTEGER DEFAULT -1, "game" VARCHAR, "name" VARCHAR, "params" VARCHAR, "stopcommands" VARCHAR, "starttime" DATETIME, "stoptime" DATETIME)
';
        
        public function __construct(SQLiteConfig $config)
        {
            $this->config = $config;
            $this->connected = false;
        }
        
        public function connect()
        {
            $init = false;
            if (!$this->connected)
            {
                $path = __DIR__ . DS . $this->config->get('file');
                $dir = dirname($path);
                if (!file_exists($path))
                {
                    if (!Util::isWritable($dir))
                    {
                        throw new DatabaseException('Could not create the database, path not writable!');
                    }
                    $init = true;
                }
                
                $this->db = new PDO('sqlite:' . $path, 0766, $this->error);
                if ($this->db === false)
                {
                    throw new DatabaseException('Failed to connect to the database!');
                }
                $this->connected = true;
            }
            if ($init)
            {
                if (!$this->query(self::$INIT_SQL, false))
                {
                    throw new DatabaseException('Failed to initialize the database structure!');
                }
            }
        }
        
        public function query($query, $return = true)
        {
            $this->connect();
            
            if ($return)
            {
                $result = $this->db->query($query);
                if ($result === false)
                {
                    throw new DatabaseException('The query failed!');
                }
                $fetchedResult = array();
                foreach ($result as $row)
                {
                    $fetchedResult[] = $row;
                }
                
                return $fetchedResult;
            }
            else
            {
                return ($this->db->exec($query) !== false);
            }
        }
        
        public function error()
        {
            return $this->error;
        }
    }
    
    /**
     * 
     */
    class ServerDB
    {
        private static $instance = null;
        private static $dbConfig = null;
        protected $db;
        
        public static function dbConfig(AbstractDatabaseConfig $config = null)
        {
            if ($config === null)
            {
                return self::$dbConfig;
            }
            else
            {
                self::$dbConfig = $config;
            }
        }
        
        /**
         * 
         */
        private function __construct()
        {
            $this->db = AbstractDatabaseBackend::factory(self::$dbConfig);
        }
        
        /**
         * 
         */
        private function __clone()
        {}
        
        /**
         *
         * @return type 
         */
        public static function &instance()
        {
            if (self::$instance === null)
            {
                if (self::$dbConfig === null)
                {
                    throw new MissingDependencyException('No database configuration found!');
                }
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         *
         * @param string $game the game
         * @param string $name the server
         * @return mixed[] the infos of the server 
         */
        public function getServer(PlainIdentifier $id)
        {
            $result = $this->db->query("SELECT * FROM servers WHERE name='" . $id->name() . "'");
            if (count($result) > 0)
            {
                return $result[0];
            }
            else
            {
                return false;
            }
        }

        /**
         *
         * @return string[] the server data of all servers
         */
        public function getAllServers()
        {
            $tmp = $this->db->query('SELECT name FROM servers ORDER BY name ASC');
            $servers = array();
            
            foreach ($tmp as $server)
            {
                $servers[] = $server['name'];
            }
            
            return $servers;
        }
        
        /**
         *
         * @param string $name the server
         * @param int $ram the amount auf RAM (in megabyte) the server should get
         * @return bool true on success, false on failure
         */
        public function addServer(PlainIdentifier $id, $ram)
        {
            $time = time();
            return $this->db->query("INSERT INTO servers (pid, name, ram, starttime, stoptime) VALUES (-1, '" . $id->name() . "', $ram, $time, 0)", false);
        }
        
        public function setProcessID(PlainIdentifier $id, $pid)
        {
            if (!Util::is_numeric($pid))
            {
                return false;
            }
            
            return $this->db->query("UPDATE servers SET pid=$pid WHERE name='" . $id->name() . "'", false);
        }

        /**
         *
         * @staticvar array $PIDcache
         * @param string $game
         * @param string $name
         * @return int the process ID of the server or false on failure
         */
        public function getProcessID(PlainIdentifier $id)
        {
            $result = $this->db->query("SELECT pid FROM servers WHERE game='" . $id->game() . "' AND name='" . $id->name() . "'");
            if (count($result) > 0)
            {
                return $result[0]['pid'];
            }
            else
            {
                return false;
            }
        }
        
        public function setStopCommands(PlainIdentifier $id, array $commands)
        {
            $commandsStr = addslashes(serialize($commands));
            
            return $this->db->query("UPDATE servers SET stopcommands='$commandsStr' WHERE game='" . $id->game() . "' AND name='" . $id->name() . "'", false);
        }
        
        public function getStopCommands(PlainIdentifier $id)
        {
            $result = $this->db->query("SELECT stopcommands FROM servers WHERE game='" . $id->game() . "' AND name='" . $id->name() . "'");
            if (count($result) > 0)
            {
                return $result[0]['stopcommands'];
            }
            else
            {
                return false;
            }
        }
        
        public function setParams(PlainIdentifier $id, $paramStr)
        {
            
        }

        /**
         *
         * @param string $game the game
         * @param string $name the server
         * @return bool true on success, otherwise false
         */
        public function removeServer(PlainIdentifier $id)
        {
            return $this->db->query("DELETE FROM servers WHERE name='" . $id->name() . "'", false);
        }
    }

    /**
     * 
     */
    class Args
    {
        private static $instance = null;
    
        protected $argv;
        protected $argc;
        
        protected $params;
        protected $values;
        protected $command;
    
        /**
         * 
         */
        private function __construct()
        {
            $this->argv = $_SERVER['argv'];
            $this->argc = $_SERVER['argc'];
            
            $this->params = array();
            $this->values = array();
            $this->command = '';
            
            $this->readArgs();
        }
        
        /**
         * 
         */
        private function __clone()
        {}
        
        /**
         *
         * @return Args the instance of the Args class 
         */
        public static function &instance()
        {
            if (self::$instance === null)
            {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         * 
         */
        protected function readArgs()
        {
            $this->command = $this->argv[0];
            
            $lastCmd = null;
            for ($i = 1; $i < $this->argc; $i++)
            {
                if (substr($this->argv[$i], 0, 1) == '-')
                {
                    $arg = ltrim($this->argv[$i], '-');
                    if ($this->exists($arg))
                    {
                        $this->values[] =& $this->argv[$i];
                        continue;
                    }
                    $this->params[$arg] = array();
                    $lastCmd =& $this->params[$arg];
                }
                else
                {
                    if ($lastCmd !== null)
                    {
                        $lastCmd[] =& $this->argv[$i];
                        $this->values[] =& $this->argv[$i];
                    }
                    else
                    {
                        $this->values[] =& $this->argv[$i];
                    }
                }
                if (substr($this->argv[$i], 0, 2) == '--')
                {
                    $this->flags[substr($this->argv[$i], 2)] = true;
                }
            }
        }

        /**
         *
         * @param mixed $name the name (string) or the names (string[]) of the argument
         * @param bool $returnname whether to return the name
         * @return mixed true (bool) or the name (string) of the argument. False (bool) if none ex
         */
        public function exists($name, $returnname = false)
        {
            if (is_array($name))
            {
                foreach ($name as $entry)
                {
                    if (isset($this->params[$entry]))
                    {
                        return ($returnname ? $entry : true);
                    }
                }
            }
            else
            {
                if (isset($this->params[$name]))
                {
                    return ($returnname ? $name : true);
                }
            }
            return false;
        }
        
        /**
         *
         * @param mixed $name the name (string) or the names (string[]) of the argument
         * @param int $index the index of the argument to return (default: 0)
         * @param mixed $default the default value to return, if the argument does not exist
         * @return string the argument
         */
        public function getArg($name, $index = 0, $default = null)
        {
            $name = $this->exists($name, true);
            if ($name !== false && isset($this->params[$name][$index]))
            {
                return $this->params[$name][$index];
            }
            else
            {
                return $default;
            }
        }

        /**
         *
         * @param mixed $name the name (string) or names (string[]) of the argument
         * @return string[] the arguments or null if none exist
         */
        public function getArgsAll($name)
        {
            $name = $this->exists($name, true);
            if ($name !== false)
            {
                return $this->params[$name];
            }
            else
            {
                return null;
            }
        }
    }

    /**
     * 
     */
    class ServerManager
    {
        private static $instance = null;
        protected $serverdb;

        /**
         * 
         */
        private function __construct()
        {
            if (PHP_OS == 'WINNT')
            {
                Logger::write(LogType::ERROR, 'The script is not compatible with windows and will never be!');
                exit(1);
            }
            //set_error_handler(array($this, 'error_handler'));
            //set_exception_handler(array($this, 'exception_handler'));
            $this->checkDependencies();
            $this->serverdb = ServerDB::instance();
            $this->syncDBandFS();
        }

        /**
         * 
         */
        private function __clone()
        {}

        /**
         *
         * @return ServerManager the instance of the manager 
         */
        public static function &instance()
        {
            if (self::$instance === null)
            {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         * 
         * @return AbtractCommand the command
         */
        protected function getCommand()
        {
            $command = Args::instance()->getArg(array('c', 'command'), 0, 'help');
            $command = ucfirst(strtolower(trim($command))) . 'Command';
            
            if (!class_exists($command))
            {
                $command = 'HelpCommand';
            }
            if (!in_array('AbstractCommand', class_parents($command)))
            {
                throw new NoValidCommandException('Could not find a valid command.');
            }
            
            return new $command();
        }
        
        /**
         * @todo problematic for interactiv mode...
         */
        public function execute()
        {
            static $called = false;
            
            if (!$called)
            {
                $called = true;
                $command = $this->getCommand();
                CLI::println('Running "' . get_class($command) . '"');
                if ($command->run() === false)
                {
                    Logger::write(LogType::WARNING, 'The command returned false. Check error messages!');
                }
            }
            else
            {
                throw new CommandException('The command was already executed!');
            }
        }
        
        /**
         *
         * @return bool true if the current user is root, otherwise false
         */
        public function userIsRoot()
        {
        //  if (__WINDOWS)
        //  {
        //      return false;
        //  }
            $name = CLI::exec("whoami");
            $name = trim(implode('', $name[0]));
            if ($name == 'root')
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        
        /**
         * 
         */
        protected function checkDependencies()
        {
            if (class_exists('PDO'))
            {
                ServerDB::dbConfig(new SQLiteConfig());
            }
            elseif (extension_loaded('mysql'))
            {
                ServerDB::dbConfig(new MySQLConfig());
            }
            else
            {
                throw new MissingDependencyException('No compatible database extention found!');
            }
            
            Util::prepareDirectory(SERVERDIR);
            Util::prepareDirectory(PACKAGEDIR);
            Util::prepareDirectory(BACKUPDIR);

            if (!CLI::which('7z'))
            {
                throw new MissingDependencyException('7-Zip not found!');
            }

            if (!CLI::which('screen'))
            {
                throw new MissingDependencyException('Screen not found!');
            }
        }
        
        /**
         * teh error handler
         *
         * @access public
         * @static
         * @param int $errno
         * @param string $errstr
         * @param string $errfile
         * @param int $errline
         * @param array $errcontext
         */
        public function error_handler($errno, $errstr, $errfile, $errline, $errcontext)
        {
            if (error_reporting() == 0)
            {
                return;
            }
            $errstr = strip_tags($errstr);
            $errfile = (isset($errfile) ? basename($errfile) : 'unknown');
            $errline = (isset($errline) ? $errline : 'unknown');

            $errortype = $this->getErrorTypeStr($errno);
            
            Logger::write(LogType::PHP_ERROR, '[' . $errortype .  '][' . $errfile . ':' . $errline . '] ' . $errstr);
        }

        /**
         *
         * @param int $errno the error number
         * @return string the error type
         */
        public function getErrorTypeStr($errno)
        {
            $errortype = 'unknown';
            switch ($errno)
            {
                case E_ERROR:
                    $errortype = 'error';
                    break;
                case E_WARNING:
                    $errortype = 'warning';
                    break;
                case E_NOTICE:
                    $errortype = 'notice';
                    break;
                case E_STRICT:
                    $errortype = 'strict';
                    break;
                case E_DEPRECATED:
                    $errortype = 'deprecated';
                    break;
                case E_RECOVERABLE_ERROR:
                    $errortype = 'recoverable error';
                    break;
                case E_USER_ERROR:
                    $errortype = 'usererror';
                    break;
                case E_USER_WARNING:
                    $errortype = 'user warning';
                    break;
                case E_USER_NOTICE:
                    $errortype = 'user notice';
                    break;
                case E_USER_DEPRECATED:
                    $errortype = 'user deprecated';
            }
            return $errortype;
        }

        /**
         * the exception handler
         *
         * @access public
         * @static
         * @param Exception $e
         */
        public function exception_handler($e)
        {
            Logger::write(LogType::UNCAUGHT_EXCEPTION, '[' . get_class($e) . '][' . basename($e->getFile()) . ':' . $e->getLine() . '] ' . $e->getMessage());
        }

        /**
         * 
         */
        protected function syncDBandFS()
        {
            $serversDB = $this->serverdb->getAllServers();
            $serversFS = $this->getServersFromFS();
            
            foreach ($serversDB as $server)
            {
                if (!in_array($server, $serversFS))
                {
                    CLI::println('Removing server "' . $server . '" from database');
                    $this->serverdb->removeServer(new PlainIdentifier($server));
                }
            }
            
            foreach ($serversFS as $server)
            {
                if (!in_array($server, $serversDB))
                {
                    CLI::println('Adding server "' . $server . '" with 1024M RAM to database');
                    $this->serverdb->addServer(new PlainIdentifier($server), 1024);
                }
            }
            
        }

        /**
         *
         * @staticvar int $recursionlevel
         * @staticvar string $game
         * @param string[] $output the
         * @param string $dir the directory to read (default: "(script directory)/servers")
         */
        protected function getServersFromFS($dir = SERVERDIR)
        {
            $names = glob(SERVERDIR . DS . '*', GLOB_ONLYDIR);
            if ($names === false)
            {
                throw new IOException('Failed get the servers!', 401);
            }
            return array_map('basename', $names);
        }

        /**
         *
         * @param string the game name
         * @return string the package path
         * @todo needed ?
         */
        protected function getPackage($name)
        {
            $package = PACKAGEDIR . DS . "$name.7z";
            if (file_exists($package))
            {
                return $package;
            }
            else
            {
                throw new MissingDependencyException('Package does not exist!');
            }
        }
        
        /**
         *
         * @param string the full name
         * @return bool true if the server exists, otherwise false
         */
        public function gameserverExists(DistinctPartialIdentifier $name)
        {
            $name = $name->name();
            if (!ServerDB::instance()->getServer(new PlainIdentifier($name)))
            {
                return false;
            }
            
            if (!file_exists(SERVERDIR . DS . $name))
            {
                return false;
            }
            
            return true;
        }

        /**
         *
         * @param type $game
         * @return string 
         */
        protected function createGameserverDir(DistinctPartialIdentifier $name)
        {
            $dir = SERVERDIR . DS . $name->name();
            if (file_exists($dir))
            {
                throw new IOException('Gameserver directory already exists!');
            }
            
            Util::prepareDirectory($dir);
            
            return $dir;
        }

        /**
         *
         * @param string $game
         * @param string $name
         * @return bool 
         */
        public function create(PlainIdentifier $name, $ram)
        {
            $path = '';
            try
            {
                $package = $this->getPackage($package);
                $path = $this->createGameserverDir($name);
            
                $cmd = '7z x "-o' . $path . '" "' . $package. '"';
                $result = CLI::exec($cmd);
                if ($result[1] === 1)
                {
                    throw new IOException('Failed to extract the server!');
                }

                if (!ServerDB::instance()->addServer($name, $ram))
                {
                    throw new ServerManagerException('ServerManager::create', 'Failed to add the server to the Database!');
                }
            
                return true;
            }
            catch (ServerManagerException $e)
            {
                if (file_exists($path))
                {
                    CLI::rmdir($path) or Logger::write(LogType::ERROR, 'Failed to remove "' . $path .'" after a installation failure!');
                }
                return false;
            }
        }
        
        public function remove(DistinctPartialIdentifier $id)
        {
            try
            {
                if (!$this->gameserverExists($id))
                {
                    throw new ServerManagerException('ServerManager::remove', 'The given gameserver does not exist!');
                }
                CLI::rmdir(SERVERDIR . DS . $id->game() . DS . $id->name());
                $this->serverdb->removeServer($id->game(), $id->name());
                
                return true;
            }
            catch (ServerManagerException $e)
            {
                return false;
            }
        }
        
        public function start(PartialIdentifier $id, $params, $persistparams, $reattach)
        {
            $persistparams = (bool) $persistparams;
            $reattach = (bool) $reattach;
            
            if ($reattach && count($id) > 1)
            {
                Logger::write(LogType::WARNING, 'I can only reattach if the ID is distinct!', LogDest::SCREEN);
                $reattach = false;
            }
                
            
            foreach ($id as $plainID)
            {
                try
                {
                    Logger::write(LogType::INFO, '', LogDest::BOTH);
                    $path = SERVERDIR . DS . $plainID->game() . DS . $plainID->name();

                    $screen = new Screen(strval($id));
                }
                catch (ServerManagerException $e)
                {
                    Logger::write(LogType::ERROR, '', LogDest::BOTH);
                }
            }
        }
        
        /**
         *
         * @param type $name
         * @return type 
         */
        public function getStatus($name)
        {
            if (!$this->gameserverExists($name))
            {
                throw new ServerManagerException('ServerManager::getStatus', 'The given game server does not exist!');
            }
            
            $name = Util::parseName($name, true, false);

            $server = $this->serverdb->processID($name[0], $name[1]);
            if ($server === null)
            {
                throw new ServerManagerException("ServerManager::getStatus', 'Server does not exist in database!", 404);
            }

            if ($server['pid'] === -1)
            {
                return false;
            }

            return Proc::factory($server['pid'])->isRunning();
        }
    }
    
    abstract class Identifier
    {
        /**
         *
         * @param string $id the name to parse
         * @param bool $partial whether the name may be partial
         * @return string[] the matching names
         */
        public static function &parsePartial($id, $distinct = false)
        {
            if (!is_string($id))
            {
                throw new ServerManagerException('Util::parseName', 'Invalid ID given!', 401);
            }
            
            $id = trim($id);
            $distinct = (bool) $distinct;

            if (empty($id))
            {
                $id = '*';
            }
            
            if (self::matchesRegex('/[^\w\*\?]/', $id))
            {
                throw new ServerManagerException('Util::parseName', 'Invalid ID given!', 401);
            }
            
            $names = array();
            if (self::matches('*', $id))
            {
                $names = glob(SERVERDIR . DS . $id, GLOB_ONLYDIR);
                if ($names === false)
                {
                    throw new ServerManagerException('Util::parseName', 'Failed to resolve the partial ID!', 401);
                }
                $names = array_map('basename', $names);
            }
            else
            {
                if (file_exists(SERVERDIR . DS . $id))
                {
                    $names = array($id);
                }
            }
            
            if (!count($names))
            {
                throw new ServerManagerException('Util::parseName', 'No matching server found!');
            }
            
            if ($distinct)
            {
                if (count($names) > 1)
                {
                    throw new ServerManagerException('Util::parseName', 'The given name was not distinct!');
                }
                
                return new DistinctPartialIdentifier($names[0]);
            }
            else
            {
                return new PartialIdentifier($names);
            }
        }
        
        public static function parsePlain($id)
        {
            if (!is_string($id))
            {
                throw new ServerManagerException('Util::parseName', 'Invalid ID given!', 401);
            }

            if (!file_exists(SERVERDIR . DS . $id))
            {
                throw new ServerManagerException('Util::parseName', 'No matching server found!');
            }
            
            return new PlainIdentifier(trim($id));
        }
    }
    
    abstract class AbstractIdentifier implements IteratorAggregate, Countable
    {
        protected $IDs;
        protected $count;
        
        public abstract function __construct($id);
        public abstract function __toString();
        
        public abstract function name();
        public abstract function plainID();
        
        public function &getNames()
        {
            return $this->IDs;
        }

        public function getIterator()
        {
            return new ArrayIterator($this->IDs);
        }

        public function count()
        {
            return $this->count;
        }
    }
    
    class PartialIdentifier extends AbstractIdentifier
    {
        private $namelist;
        
        public function __construct(array $ids)
        {
            foreach ($ids as $id)
            {
                if (is_string($id))
                {
                    $this->IDs[] = new PlainIdentifier($id);
                    $this->namelist[] = $id;
                }
            }
            $this->count = count($this->IDs);
        }
        
        public function name()
        {
            $name = each($this->namelist);
            if ($name === false)
            {
                return null;
            }
            return $name['value'];
        }
        
        public function plainID()
        {
            $id = each($this->IDs);
            if ($id === null)
            {
                return null;
            }
            return $id['value'];
        }
        
        public function __toString()
        {
            return __CLASS__;
        }
    }
    
    class DistinctPartialIdentifier extends PartialIdentifier
    {
        protected $id;
        
        public function __construct($id)
        {
            $this->id = new PlainIdentifier($id);
            $this->IDs = array(&$this->id);
            $this->count = 1;
        }
        
        public function name()
        {
            return $this->id->name();
        }
        
        public function plainID()
        {
            return $this->id;
        }
        
        public function __toString()
        {
            return $this->id->name();
        }
    }
    
    class PlainIdentifier extends DistinctPartialIdentifier
    {
        protected $name;
        
        public function __construct($id)
        {   
            $this->name = $id;
            $this->IDs = array(&$this);
            $this->count = 1;
        }
        
        public function name()
        {
            return $this->name;
        }
        
        public function plainID()
        {
            return $this;
        }
        
        public function __toString()
        {
            return $this->name;
        }
    }

    /**
     * 
     */
    abstract class AbstractCommand
    {
        /**
         *
         */
        public abstract function getName();

        /**
         *
         */
        public function hasGui()
        {
            return false;
        }

        /**
         * 
         */
        public abstract function run();

        /**
         *
         */
        public function runGui()
        {}
    }
    
    /**
     * 
     */
    class HelpCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Help';
        }

        public function run()
        {
            CLI::println(<<<manual

ServerManager - Manual

Hier kommen infos zu den commands hin :D (paramter, aliase, ...)
manual
);
            return true;
        }
    }
    
    /**
     * 
     */
    class InstallCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Install';
        }

        public function run()
        {
            try
            {
                $id = Args::instance()->getArg(array('i', 'id'), 0);
                if ($id === null)
                {
                    Logger::write(LogType::ERROR, "{RED}The name is missing.");
                    return false;
                }
                $id = Identifier::parsePlain($id);
                if (!ServerManager::instance()->gameserverExists($id))
                {
                    $package = Args::instance()->getArg(array('p', 'package'));
                    if ($package === null)
                    {
                        Logger::write(LogType::ERROR, "No package given!");
                        return false;
                    }
                    $ram = Args::instance()->getArg('ram', 0, 1024);
                    $matches = array();
                    if (preg_match('/(\d+)([KMGTPE])?/', $ram, $matches))
                    {
                        $amount = $matches[1];
                        if (isset($matches[2]))
                        {
                            switch ($matches[2])
                            {
                                case 'E':
                                    $amount *= 1024;
                                case 'P':
                                    $amount *= 1024;
                                case 'T':
                                    $amount *= 1024;
                                case 'G':
                                    $amount *= 1024;
                                    break;
                                case 'K':
                                    $amount /= 1024;
                                default:
                                    break;
                            }
                        }
                    }
                    else
                    {
                        Logger::write(LogType::ERROR, "Invalid RAM value given!");
                        return false;   
                    }
                    if (ServerManager::instance()->create($id, $package, $ram))
                    {
                        CLI::println("{GREEN}The server has been successfully installed and should be ready to start.");
                    }
                    else
                    {
                        Logger::write(LogType::ERROR, "Failed to create the server!");
                        return false;   
                    }
                }
                else
                {
                    Logger::write(LogType::ERROR, '{RED}This server does already exist!');
                    return false;
                }
            }
            catch (Exception $e)
            {
                Logger::write(LogType::ERROR, "{RED}An unknown exception occurred. Msg: " . $e->getMessage());
                return false;
            }
            return true;
        }
    }
    
    class CreateCommand extends InstallCommand
    {}
    
    class RemoveCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Remove';
        }

        public function run()
        {
            $id = Args::instance()->getArg(array('i', 'id'), 0);
            if ($id === null)
            {
                Logger::write(LogType::ERROR, 'The name is missing!');
                return false;
            }
            $id = Identifier::parsePartial($id, true);
            if (!ServerManager::instance()->gameserverExists($id))
            {
                Logger::write(LogType::ERROR, 'The server does not even exist!');
                return false;
            }
            if (ServerManager::instance()->isRunning($id))
            {
                if (Args::instance()->exists(array('f', 'force')))
                {
                    ServerManager::instance()->stop($id);
                }
                else
                {
                    Logger::write(LogType::ERROR, 'The server is currently running!');
                    return false;
                }
            }
            if (ServerManager::instance()->remove($id))
            {
                Logger::write(LogType::INFO, 'The server was successfully removed!');
            }
            else
            {
                Logger::write(LogType::ERROR, 'Failed to remove the server!');
                return false;
            }
            
            return true;
        }
    }
    
    class DeleteCommand extends RemoveCommand
    {}
    
    class StartCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Start';
        }

        public function run()
        {
            
        }
    }
    
    class RunCommand extends StartCommand
    {}
    
    class RestartCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Restart';
        }

        public function run()
        {
            $stop = new StopCommand();
            $stop->run();
            $start = new StartCommand();
            $start->run();
        }
    }
    
    class StopCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Stop';
        }

        public function run()
        {
            
        }
    }
    
    class StatusCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Status';
        }

        public function run()
        {
            
        }
    }
    
    class BackupCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Backup';
        }

        public function run()
        {
            
        }
    }
    
    class ListCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'List';
        }

        public function run()
        {
            
        }
    }
    
    class VersionCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Version';
        }

        public function run()
        {
            
        }
    }
    
    class CronCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Cron';
        }

        public function run()
        {
            
        }
    }
    
    class InteractiveCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Interactive';
        }

        public function run()
        {
            
        }
    }
    
    class ViewCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'View';
        }

        public function run()
        {
            
        }
    }
    
    class ForkCommand extends AbstractCommand
    {
        public function getName()
        {
            return 'Fork';
        }

        public function run()
        {
            
        }
    }
    
    class CopyCommand extends ForkCommand
    {}
    
    /*DEV*/
    chdir(__DIR__);
    /*/DEV*/

    CLI::println("{YELLOW}Executing ServerManager");
    if (PHP_SAPI != 'cli')
    {
        Logger::write(LogType::NOTICE, '{YELLOW}I\'m a {RED}{BOLD}{BLINK}CLI{CLEAR}{YELLOW} script, so you should call me as one! :P', LogDest::BOTH);
    }
    try
    {
        ServerManager::instance()->execute();
        CLI::println("{GREEN}Done");
    }
    catch (ServerManagerException $e)
    {
        Logger::write(LogType::WARNING, 'The command failed to execute!');
    }
    catch (Exception $e)
    {
        Logger::write(LogType::ERROR, 'An unknown execption (' . get_class($e) . ') occurred! Msg: ' . $e->getMessage());
    }
    echo "\n\n";
    
    /*
     * | -> nothing done so far
     * / -> in progress
     * - -> (mostly) done, but untestet
     * X -> fully working
     *
     * 
     * - Help                                                                   /
     * - Version                                                                |
     * - Start                                                                  /
     * - Stop                                                                   /
     * - Restart                                                                -
     * - List                                                                   |
     * - Install                                                                /
     * - Remove                                                                 /
     * - Backup                                                                 |
     * - View                                                                   |
     * - Status                                                                 |
     * - Cron                                                                   |
     * - Interactive                                                            |
     * - GUI (ncurses)                                                          | ( <-- killer feature )
     * 
     */

    /*
     * Internal things:
     *
     * - add better exception handling (specific exceptions inherited from ServerManagerException)
     * - improve partial identifiers
     *
     *
     */
    
?>
