<?php

/*
    Web Application Deployment Framework
    (c)2006-2012 Tim Jackson (tim@timj.co.uk)
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of version 3 of the GNU General Public License as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'System.php';

/**
 * Main WADF class
 */
class Tools_WADF {
	
	const SWVERSION = '@package_version@';

	const VCREFTYPE_REV     = 'rev';
	const VCREFTYPE_TAG     = 'tag';
	const VCREFTYPE_BRANCH  = 'branch';
 	const VCREFTYPE_TRUNK   = 'trunk';
 	const VCREFTYPE_UNKNOWN = 'unknown';
	
	const OUTPUT_SILENT     = 0;
	const OUTPUT_NORMAL     = 50;
	const OUTPUT_VERBOSE    = 70;
	const OUTPUT_DEBUG      = 100;
	
	const DEBUG_ERROR       = 10;
	const DEBUG_WARNING     = 20;
	const DEBUG_GENERAL     = 40;
	const DEBUG_INFORMATION = 60;
	const DEBUG_VERBOSE     = 80;
	        
	protected $_debug = self::OUTPUT_NORMAL;
	
	const DEPENDENCY_TYPE_PEAR = 'PEAR';
	
	// the app reference
	public $appref;

	// Macro definitions
	// Array of Tools_WADF_MacroDef objects
	protected $_macro_defs;
	
	// resolved macro values
	protected $_macro_values = array();
	
	// generic class options
	protected $_options = array(
		'master_config' => '@cfg_dir@/Tools_WADF/wadf.conf',
	);
	
	// macros which we don't manage to resolve during deployment
	protected $_unresolved_macros = array();
	
	/**
	 * Macros which have "specific" versions based on enumerated entities
	 * (vhosts, databases) and which fall back to a more generic version if
	 * the more specific version does not exist
	 */
	protected $_macro_fallbacks = array(
		'vhost(\d+)_name' => 'vhost_name',
		'vhost(\d+)_interface' => 'vhost_interface',
		'vhost(\d+)_config_template' => 'vhost_config_template',
		'vhost(\d+)_config_prepend' => 'vhost_config_prepend',
		'vhost(\d+)_config_append' => 'vhost_config_append',
		'db(\d+)_type' => 'db_type',
		'db(\d+)_name' => 'db_name',
		'db(\d+)_host' => 'db_host',
		'db(\d+)_user' => 'db_user',
		'db(\d+)_user_host' => 'db_user_host',
		'db(\d+)_pass' => 'db_pass',
		'db(\d+)_schema' => 'db_schema',
		'db(\d+)_deploy' => 'db_deploy',
		'db(\d+)_deploy_user' => 'db_deploy_user',
		'db(\d+)_deploy_pass' => 'db_deploy_pass'
	 );
	
	/**
	 * @var Tools_WADF_VCDriver_Interface  Version control driver in use
	 */
	protected $_vc = null;
	
	/**
	 * Macros that were passed from the command line. Only used for writing
	 * instance files.
	 */
	private $_cmdline_macros = array();

	public static $vc_drivers = null;
	
	public function __construct($appref, $options=null, $cmdline_macros=null, $initial_output_level=self::OUTPUT_NORMAL)
	{
		$this->setDebugLevel($initial_output_level);
	
		// Strip trailing slash from appref, if present
		if (substr($appref, -1, 1) == '/') {
			$appref = substr($appref, 0, strlen($appref)-1);
		}
		
		$valid_options = array('master_config');
		if (is_array($options)) {
			foreach ($options as $option => $value) {
				if (in_array($option, $valid_options)) {
					$this->_options[$option] = $value;
				} else {
					throw new Exception("Invalid config option '$option' passed to constructor");
				}
			}
		}
		
		$this->_setInternalMacros();
		
		// set default options
		$this->_appendMacroDefs($this->_options);
		
		// set appref
		$this->appref = $appref;
		$options['appref'] = $appref;
		
		// set user-supplied options
		$this->_appendMacroDefs($options);

		if (!is_array($cmdline_macros)) {
			$cmdline_macros = array();
		} else {
			$this->_cmdline_macros = $cmdline_macros;
		}
		$this->processConfigs($cmdline_macros);

		// Process macros
		$this->resolveAllMacros();
		$this->_setPEARMacros();
		
		// Load version control plugin
		$vc_type = strtolower($this->resolveMacro('vc_type'));
		$vc_class = self::loadVCDriver($vc_type);
		if (isset($vc_class)) {
			$this->_vc = new $vc_class($this);
		}
	}

	/**
	 * Load a version control driver for the specified type.
	 *
	 * The type is essentially the vc name but lowercased.
	 *
	 * @throws Exception If not driver found for the specified type
	 * @return string|null Class name for the version control driver or null if the type is undefined
	 */
	public static function loadVCDriver($vc_type)
	{
		if ($vc_type == 'none' || $vc_type == '@vc_type@') {
			return null;
		}
		
		$drivers = self::getAllVCDrivers();

		foreach ($drivers as $driver => $class) {
			if (strtolower($driver) == strtolower($vc_type)) {
				return $class;
			}
		}
		
		throw new Exception("Version control plugin '$vc_type' is not supported");
	}

	/**
	 * Get an array of all available version control drivers.
	 *
	 * For each driver the array contains the driver name, the class name and
	 * path to the driver file.
	 *
	 * @return array
	 */
	public static function getAllVCDrivers()
	{
		if (!isset(self::$vc_drivers)) {
			self::$vc_drivers = array();

			$driver_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'WADF' . DIRECTORY_SEPARATOR . 'VCDriver';

			$files = scandir($driver_path);
			foreach ($files as $file) {
				if (!in_array($file, array('.', '..', 'Interface.php')) && preg_match('@^(.*)\.php$@', $file, $matches)) {
					$driver = $matches[1];
					require_once $driver_path . DIRECTORY_SEPARATOR . $file; //require all as it's needed by _getFilesToIgnore()
					self::$vc_drivers[$driver] = 'Tools_WADF_VCDriver_' . $driver;
				}
			}
		}

		return self::$vc_drivers;
	}
	
	public static function getFilesToIgnore()
	{
		$ignore = array('.', '..');
		
		foreach (self::getAllVCDrivers() as $driver => $class) {
			$ignore = array_merge($ignore, call_user_func(array($class, "getVCFilesToIgnore")));
		}
		
		return $ignore;
	}
	
	protected function _setInternalMacros()
	{
		$hostname = getenv('HOSTNAME');
		if (!empty($hostname)) {
			$macros['hostname'] = $hostname;
		} else {
			// TODO there is presumably a better way of doing this
			$macros['hostname'] = gethostbyaddr('127.0.0.1');
		}
		$macros['cwd'] = getcwd();
		$user_env_vars = array('USER', 'USERNAME', 'LOGNAME');
		foreach ($user_env_vars as $var) {
			if (!isset($macros['user'])) {
				$user = getenv($var);
				if (!empty($user)) {
					$macros['user'] = $user;
				}
			}
		}
		if (!isset($macros['user'])) {
			if (function_exists('posix_getuid')) {
				$details = posix_getpwuid(posix_getuid());
				$macros['user'] = $details['name'];
			} else {
				$macros['user'] = 'UNKNOWN';
			}
		}
		$home = getenv('HOME');
		if (!empty($home)) {
			$macros['home'] = $home;
		}
		$this->_appendMacroDefs($macros);
	}
	
	/**
	 * Perform a deployment
	 * 
	 * @param string $revtype  Revision type - branch, trunk, tag
	 * @param string $rev_translated The revision control version. For branches this is the branch name, for tags the tag name.
	 * @param string $raw_rev  Raw version control revision, if appropriate (not for revtype=tag). Can be HEAD.
	 * @param bool $db_deploy  Whether or not to deploy databases to go with the application
	 * @return bool Whether deployment succeeded
	 */
	public function deploy($revtype, $rev_translated, $raw_rev=null, $db_deploy=false)
	{
		// TODO: check if there is already a deployed instance of the app!
	
		$dir = $this->resolveMacro('deploy_path');
		$this->checkout($dir, $revtype, $rev_translated, $raw_rev);
		
		// The local config might be stored in the checkout, so check the local
		// config again
		$this->processLocalConfig();
		
		// Get list of macros from files we just checked out, and force-resolve
		// any with fallbacks. This is so that they get in the list of resolved
		// macros and the user will then correctly be prompted for any values 
		// required in checkOptionsRequiringInput
		$this->resolveMacrosWithFallbacksInDir($dir, $db_deploy);
		
		// Check for any config options that require user input
		if (!$this->checkOptionsRequiringInput()) {
			return false;
		}
		
		$this->deployDependencies($dir);
		$this->processTemplatesInDir($dir);
		
		// TODO: handled unresolved macros? or just let UI do it?
		
		if ($db_deploy) {
			$this->deployDatabase();
		}
		$this->deployVhost();
		$this->deployDNS();
		$this->runKickstart($db_deploy);
		$this->deployScheduledJobs();
		$this->postDeploy();
		$this->cleanupFiles();
		$this->restartWebserver();
		return true;
	}
	
	public function getUnresolvedMacros()
	{
		return $this->_unresolved_macros;
	}

	/**
	 * Remove a deployment
	 * 
	 * @param bool $remove_db  Whether or not to remove databases that are part of the deployment
	 * @return void
	 */
	public function undeploy($remove_db=false)
	{
		$dir = $this->resolveMacro('deploy_path');
		if ($remove_db) {
			$this->undeployDatabase();
		}
		$this->undeployScheduledJobs();
		$this->undeployDNS();
		$this->undeployVhost();
		$this->undeployDependencies($dir);
		$this->restartWebserver();
		
		$this->_debugOutput("Removing directory $dir...", self::DEBUG_GENERAL);
		$this->_rmDir($dir);
		return true;
	}
	
	public function enumerateMultipleEntities()
	{
		$dir = $this->resolveMacro('deploy_path');
	
		$macros_in_templates = $this->extractMacrosFromDir($dir);
		// we need to enumerate the db and vhost numbers
		$in_use = array();
		foreach ($macros_in_templates as $macro) {
			if (preg_match('/^(db|vhost)(\d+)_/', $macro, $matches)) {
				// array like
				// in_use = array (
				//   db => array (1=>true, 2=>true, 3=>true)
				//   vhost => array (1=>true, 2=>true)
				$in_use[$matches[1]][$matches[2]] = true;
			}
		}
		// now turn the array into db => array(1,2,3) etc.
		foreach ($in_use as $type => $list_of_valid) {
			$in_use[$type] = array_keys($list_of_valid);
		}
		
		return $in_use;
	}
	
	/**
	 * Deploy database(s) for the application
	 * 
	 * @return void
	 */
	public function deployDatabase()
	{
		$in_use = $this->enumerateMultipleEntities();
		$dir = $this->resolveMacro('deploy_path');
		
		// deploy database
		if (isset($in_use['db'])) {
			if (!function_exists('mysqli_connect')) {
				throw new Exception ("You need to install the mysql extension for PHP");
			}
			foreach ($in_use['db'] as $num) {
				$host = $this->resolveMacro("db${num}_host");
				$user = $this->resolveMacro("db${num}_user");
				$pass = $this->resolveMacro("db${num}_pass");
				$name = $this->resolveMacro("db${num}_name");
				$type = $this->resolveMacro("db${num}_type");
				$schema = $this->resolveMacro("db${num}_schema");
				if ($type != 'mysql') {
					throw new Exception("Unsupported database type '$type' when deploying database db$num");
				}
				$deploy_options = $this->_getDatabaseDeployOptions($num);
				
				// Check that key database configuration options are non-empty
				foreach (array('host','user','name','type') as $var_to_check) {
					$name_of_option = 'db' . $num . '_' . $var_to_check;
					if (empty($$var_to_check)) {
						throw new Exception("The database configuration option $name_of_option is empty");
					}
				}
				$this->_debugOutput("Setting up database $name on host $host...", self::DEBUG_GENERAL);
				// FIXME quote strings!
				$db_deploy_user = $this->resolveMacro("db${num}_deploy_user");
				$db_deploy_pass = $this->resolveMacro("db${num}_deploy_pass");
			
				$db = @mysqli_connect($host, $db_deploy_user, $db_deploy_pass);
				if (!$db) {
					throw new Exception("Could not connect to database (username=$db_deploy_user, password=$db_deploy_pass): ".mysqli_error($db));
				}
			
				if (in_array('create', $deploy_options)) {
					mysqli_query($db, "CREATE DATABASE IF NOT EXISTS $name");
				}
				if (in_array('grant', $deploy_options)) {
					$user_host = $this->resolveMacro("db${num}_user_host");
					if ($db->server_version >= 80000) {
						$this->_debugOutput("\tCreating user '{$user}'@'{$user_host}'");
						mysqli_query($db, "CREATE USER IF NOT EXISTS '{$user}'@'{$user_host}' IDENTIFIED BY '{$pass}'");
						mysqli_query($db, "GRANT ALL on {$name}.* to '{$user}'@'{$user_host}'");
					} else {
						mysqli_query($db, "GRANT ALL on {$name}.* to '{$user}'@'{$user_host}' IDENTIFIED BY '{$pass}'");
					}
				}
				if (in_array('schema', $deploy_options)) {
					// Remove existing database tables
					$this->_removeDatabaseTables($name, $db);
					
					// Deploy schema file if necessary
					if (!empty($schema)) {
						$schema_path = "$dir/$schema";
						if (file_exists($schema_path)) {
							$this->_debugOutput("\tDeploying new schema for database $name as user $db_deploy_user...", self::DEBUG_GENERAL);
							$cmd = "mysql $name -h $host -u $db_deploy_user ".(($db_deploy_pass != null) ? "-p$db_deploy_pass" : '')." < $dir/$schema 2>&1";
							mysqli_close($db);
							exec($cmd, $out, $ret);
							if ($ret != 0) {
								throw new Exception('Error when deploying schema: ' . implode("\n", $out));
							}
						} else {
							$this->_debugOutput("No schema file found to deploy at $schema_path", self::DEBUG_GENERAL);
						}
					}
				}
			}
		} else {
			$this->_debugOutput("No database to deploy", self::DEBUG_GENERAL);
		}
		return true;
	}
	
	public function undeployDatabase()
	{
		$in_use = $this->enumerateMultipleEntities();
		if (isset($in_use['db'])) {
			foreach ($in_use['db'] as $num) {
				$host = $this->resolveMacro("db${num}_host");
				$name = $this->resolveMacro("db${num}_name");
				$db_deploy_user = $this->resolveMacro("db${num}_deploy_user");
				$db_deploy_pass = $this->resolveMacro("db${num}_deploy_pass");
				
				$deploy_options = $this->_getDatabaseDeployOptions($num);
				
				$db = @mysqli_connect($host, $db_deploy_user, $db_deploy_pass);
				if (!$db) {
					throw new Exception("Could not connect to database (username=$db_deploy_user, password=$db_deploy_pass): " . mysqli_error($db));
				}
				
				if (in_array('grant', $deploy_options)) {
					mysqli_query($db, "REVOKE ALL ON $name.*");
				}
				if (in_array('create', $deploy_options)) {
					$this->_debugOutput("Dropping database $name...", self::DEBUG_GENERAL);
					$res = mysqli_query($db, "DROP DATABASE $name");
					if ($res === false) {
						$this->_debugOutput("Could not drop database $name", self::DEBUG_ERROR);
					}
				} else if (in_array('schema', $deploy_options)) {
					$this->_removeDatabaseTables($name, $db);
				}

				mysqli_close($db);
				// TODO remove DB users? But what if other site uses them?
			}
		}
		return true;
	}
	
	/**
	 * 
	 * @param $num  The database number to get the options for
	 * @return array|false
	 */
	protected function _getDatabaseDeployOptions($num)
	{
		$deploy = $this->resolveMacro("db${num}_deploy");
		$deploy_options = array();
		if (!empty($deploy) && $deploy != "@db${num}_deploy@") {
			$valid_deploy_options = array('create','grant','schema');
			$deploy_options = explode(',', $deploy);
			foreach ($deploy_options as $i => $option) {
				$option = trim($option);
				if (in_array($option, $valid_deploy_options)) {
					$deploy_options[$i] = $option;
				} else {
					throw new Exception("Invalid database deployment option in db${num}_deploy: $option");
				}
			}
		}
		return $deploy_options;
	}
	
	protected function _removeDatabaseTables($dbname, $dbconn)
	{
		$this->_debugOutput("\tDeleting existing tables in database $dbname...", self::DEBUG_GENERAL);
		$res = mysqli_select_db($dbconn, $dbname);
		if ($res === false) {
			throw new Exception("Could not select database $dbname: " . mysqli_error($dbconn));
		}
		mysqli_query($dbconn, 'SET FOREIGN_KEY_CHECKS=0');
		// MySQL 5.0+
		$res = mysqli_query($dbconn, "SELECT table_name,table_type FROM information_schema.tables WHERE table_schema='$dbname' AND table_type IN ('VIEW', 'BASE TABLE') ORDER BY table_name");
		if ($res === false) {
			// MySQL 3.x/4.x
			$res = mysqli_query($dbconn, 'SHOW TABLES');
			if ($res === false) {
				throw new Exception("Could not discover tables in database $dbname - SHOW TABLES failed");
			} else {
				while ($table = mysqli_fetch_row($res)) {
					$this->_debugOutput("\t\tDropping table " . $table[0], self::DEBUG_INFORMATION);
					$res2 = mysqli_query($dbconn, "DROP TABLE `" . $table[0] . "`");
					if ($res2 === false) {
						throw new Exception("Error dropping table $dbname.$table[0] :" . mysqli_error($dbconn));
					}
				}
			}
		} else {
			while ($table = mysqli_fetch_row($res)) {
				if ($table['1'] == 'VIEW') {
					$this->_debugOutput("\t\tDropping view " . $table[0], self::DEBUG_INFORMATION);
					$res2 = mysqli_query($dbconn, "DROP VIEW `" . $table[0] . "`");
					if ($res2 === false) {
						throw new Exception("Error dropping view $dbname.$table[0] :" . mysqli_error($dbconn));
					}
				} else {
					$this->_debugOutput("\t\tDropping table " . $table[0], self::DEBUG_INFORMATION);
					$res2 = mysqli_query($dbconn, "DROP TABLE `" . $table[0] . "`");
					if ($res2 === false) {
						throw new Exception("Error dropping table $dbname.$table[0] :" . mysqli_error($dbconn));
					}
				}
			}
		}
		return true;
	}
	
	// if mode=undeploy, then undeploy(!)
	public function deployDNS($mode='deploy')
	{
		$deploy_type = $this->resolveMacro('deploy_dns');

		// NB that the string "none" is transparently converted to the empty
		// string by the PHP INI file parser
		if ($deploy_type == '') {
			return;
		}

		$in_use = $this->enumerateMultipleEntities();
		if (isset($in_use['vhost'])) {
			foreach ($in_use['vhost'] as $vhost_id) {
				$hosts[] = $this->resolveMacro("vhost${vhost_id}_name");
			}

			// TODO other ways of deploying DNS?
			switch ($deploy_type) {
				case 'hosts':
					if ($mode == 'deploy') {
						$this->addHostsToHostsFile($hosts);
					} else {
						$this->removeHostsFromHostsFile($hosts);
					}
					break;
				default:
					throw new Exception("Unknown DNS deployment method '$deploy_type'");
					break;
			}
		}
		return true;
	}
	
	public function undeployDNS()
	{
		$this->deployDNS('undeploy');
	}
	
	public function deployScheduledJobs()
	{
		$crontab_file = $this->resolveMacro('crontab');
		if (file_exists($crontab_file)) {
			$this->_debugOutput("Deploying scheduled jobs from $crontab_file...", self::DEBUG_GENERAL);
			$instance = $this->resolveMacro('instance');
			$crontab_entries = trim(file_get_contents($crontab_file));
			exec('crontab -l 2>&1', $current_crontab, $ret);

			// The special markers we put at the start and end of the crontab			
			$wadf_deploy_begin = "# wadf-deployment-begin: $instance - DO NOT REMOVE THIS";
			$wadf_deploy_end = "# wadf-deployment-end: $instance - DO NOT REMOVE THIS";
			
			// The new entries we want to add
			$crontab_entries_new = "$wadf_deploy_begin\n$crontab_entries\n$wadf_deploy_end";

			if ($ret == 1 || (count($current_crontab) == 1 && preg_match('/^no crontab for/i', $current_crontab[0]))) {
				// No current crontab
				$this->_debugOutput("\tDeploying new crontab", self::DEBUG_GENERAL);
				$current_crontab = '';
				$new_crontab = $crontab_entries_new . "\n";
			} else {
				$current_crontab = implode("\n", $current_crontab) . "\n";
				
				$regex = "/# wadf-deployment-begin: $instance.+# wadf-deployment-end: $instance - DO NOT REMOVE THIS/ms";
				if (preg_match($regex, $current_crontab)) {
					$this->_debugOutput("\tFound $instance in existing crontab, replacing", self::DEBUG_GENERAL);
					$new_crontab = preg_replace($regex, $crontab_entries_new, $current_crontab);
				} else {
					$this->_debugOutput("\tCould not find $instance in existing crontab", self::DEBUG_GENERAL);
					$new_crontab = $current_crontab . "\n" . $crontab_entries_new . "\n";
				}
			}
			$tmp_file = tempnam('/tmp', 'wadfcron');
			$fp = fopen($tmp_file, 'w');
			fputs($fp, $new_crontab);
			fclose($fp);
			exec("crontab $tmp_file");
			unlink($tmp_file);
		}
		return true;
	}
	
	public function undeployScheduledJobs()
	{
		$instance = $this->resolveMacro('instance');
		exec('crontab -l 2>&1', $current_crontab, $ret);
		$current_crontab = implode("\n", $current_crontab);
		$regex = "/# wadf-deployment-begin: $instance.+# wadf-deployment-end: $instance - DO NOT REMOVE THIS/ms";
		if (preg_match($regex, $current_crontab)) {
			$this->_debugOutput("Removing scheduled jobs for $instance in existing crontab", self::DEBUG_GENERAL);
			$new_crontab = preg_replace($regex, '', $current_crontab);
			
			$tmp_file = tempnam('/tmp', 'wadfcron');
			$fp = fopen($tmp_file, 'w');
			fputs($fp, $new_crontab);
			fclose($fp);
			exec("crontab $tmp_file");
			unlink($tmp_file);
		} else {
			$this->_debugOutput("No scheduled jobs to remove for $instance", self::DEBUG_GENERAL);
		}
	}
	
	public function addHostsToHostsFile($hosts)
	{
		$hosts_file = $this->resolveMacro('deploy_dns_hosts_file');
		$deploy_ip = $this->resolveMacro('deploy_dns_hosts_ip');
		$existing_hostnames = array();
		$file_contents = file($hosts_file);
		$line_num = 0;
		$localhost_line_num = 0;
		$base_host = ''; //the first host found
		foreach ($file_contents as $line) {
			if (substr($line, 0, strlen($deploy_ip)) == $deploy_ip) {
				if (preg_match_all('/[\t\s]+(\S+)/', $line, $matches)) {
					foreach ($matches[1] as $host) {
						$existing_hostnames[] = trim($host);
						if (!$base_host) $base_host = trim($host);
					}
				}
				$localhost_line_num = $line_num;
			}
			$line_num++;
		}
		foreach ($hosts as $host) {
			$hosts_file_format = $this->resolveMacro('deploy_dns_hosts_file_format');
			if (!$hosts_file_format || $hosts_file_format == '@deploy_dns_hosts_file_format@') {
				$hosts_file_format = 1;
			}
			if (!in_array($host, $existing_hostnames)) {
				switch ($hosts_file_format) {
					case 1:
						array_splice($file_contents, $localhost_line_num + 1, 0, "$deploy_ip\t$base_host\t$host\n");
						break;
					case 2:
						array_splice($file_contents, $localhost_line_num + 1, 0, "$deploy_ip\t$host\n");
						break;
					default:
						throw new Exception("Unknown value '$hosts_file_format' for deploy_dns_hosts_file_format option");
				}
				
			}
		}
		$fp = @fopen($hosts_file, 'w');
		if (is_resource($fp)) {
			fputs($fp, implode('', $file_contents));
			fclose($fp);
		} else {
			$this->_debugOutput("WARNING: Could not update list of local hostnames in hosts file '$hosts_file'. Check permissions.", self::DEBUG_WARNING);
		}
	}
	
	protected function removeHostsFromHostsFile($hosts)
	{
		$this->_debugOutput("Removing hosts from DNS hosts file", self::DEBUG_GENERAL);
		$hosts_file = $this->resolveMacro('deploy_dns_hosts_file');
		$old_file_contents = file($hosts_file);
		$new_file_contents = array();
		foreach ($old_file_contents as $line) {
			if (preg_match('/\s(\S+)$/', $line, $matches)) {
				if (!in_array($matches[1], $hosts)) {
					$new_file_contents[] = $line;
				}
			}
		}
		$new_file_contents = implode('', $new_file_contents);
		$fp = @fopen($hosts_file, 'w');
		if (is_resource($fp)) {
			fputs($fp, $new_file_contents);
			fclose($fp);
		} else {
			$this->_debugOutput("WARNING: Could not update list of local hostnames in hosts file '$hosts_file'. Check permissions.", self::DEBUG_WARNING);
		}
	}
	
	public function deployVhost($dir=null)
	{
		if ($dir === null) {
			$dir = $this->resolveMacro('deploy_path');
		}

		// deploy vhost config
		$source_file = $dir.'/'.$this->resolveMacro('vhost_config_template');
		
		if (!file_exists($source_file)) {
			$this->_debugOutput("No webserver configuration to deploy (looked for $source_file)", self::DEBUG_GENERAL);
			return;
		}
		
		$this->_debugOutput("Deploying webserver configuration...", self::DEBUG_GENERAL);
		$vhost_config_path = $this->resolveMacro('vhost_config_path');
		if (!file_exists($vhost_config_path)) {
			$this->_debugOutput("Creating webserver config file path $vhost_config_path...", self::DEBUG_GENERAL);
			System::mkdir(array('-p', $vhost_config_path));
		}
		$dest_file = $vhost_config_path . '/' . $this->resolveMacro('instance') . '.conf';
		$this->_debugOutput("Copying $source_file to $dest_file", self::DEBUG_VERBOSE);
		
		$config = file_get_contents($source_file);
		
		// If we are using mod_php, insert the PHP config options
		if ($this->resolveMacro('php_type') == 'mod_php') {
			$php_ini = $dir . '/' . $this->resolveMacro('php_config_location');
			$directives = $this->_convertPhpIniToApacheModPhp($php_ini);
			
			if (count($directives) > 0) {
				// Stick the PHP directives at the end of the vhost
				$php_directives = implode("\n\t", $directives);
				$config = str_replace("</VirtualHost>", "\n\t# PHP directives processed from $php_ini\n\t" . $php_directives . "\n" . '</VirtualHost>', $config);
			}
				
			// Process local PHP override ini file, if it exists
			$php_ini_local = trim($this->resolveMacro('php_config_location_extra'));
			if ($php_ini_local != '@php_config_location_extra@' && !empty($php_ini_local)) {
				$directives_local = $this->_convertPhpIniToApacheModPhp($php_ini_local);
				if (count($directives_local) > 0) {
					$php_directives = implode("\n\t", $directives_local);
					$config = str_replace("</VirtualHost>", "\n\t# PHP directives processed from $php_ini_local\n\t" . $php_directives . "\n" . '</VirtualHost>', $config);
				}
			}
		} else {
			$this->_deployPHPConfig($dir);
		}
		
		$deploy_version = $this->resolveMacro('deploy_version');
		if ($deploy_version == '@deploy_version@') $deploy_version = 'unknown';
		$config = "# wadf-working-copy: $dir\n# wadf-deploy-version: $deploy_version\n$config";
		
		// Append/prepend virtual host configs
		$prepend = $this->resolveMacro("vhost_config_prepend");
		if (!empty($prepend) && $prepend != "vhost_config_prepend") {
			$prepend = str_replace('\n', "\n", $prepend);
			$prepend = str_replace('\t', "\t", $prepend);
			$config = preg_replace('/(<VirtualHost[^>]+>)/', '\1' . "\n$prepend", $config);
		}
		
		$append = $this->resolveMacro("vhost_config_append");
		if (!empty($append) && $append != "vhost_config_append") {
			$append = str_replace('\n', "\n", $append);
			$append = str_replace('\t', "\t", $append);
			$config = str_replace('</VirtualHost>', $append . "\n" . '</VirtualHost>', $config);
		}
		
		$fp = fopen($dest_file, 'w');
		if (is_resource($fp)) {
			fputs($fp, $config);
			fclose($fp);
		} else {
			throw new Exception("Could not open vhost config destination file '$dest_file'");
		}
		return true;
	}
	
	public function undeployVhost($dir=null)
	{
		if ($dir === null) {
			$dir = $this->resolveMacro('deploy_path');
		}

		$vhost_config = $this->resolveMacro('vhost_config_path') . '/' . $this->resolveMacro('instance').'.conf';
		$this->_debugOutput("Removing vhost config $vhost_config", self::DEBUG_VERBOSE);
		@unlink($vhost_config);
		
		$php_type = $this->resolveMacro('php_type');
		if (preg_match('/^cgi:(.+)$/', $php_type, $matches)) {
			$php_ini_dest = trim($matches[1]);
			@unlink($php_ini_dest);
		}
	}
		
	public function postDeploy()
	{
		$cmd = $this->resolveMacro('post_deploy_script');
		if ($cmd != '@post_deploy_script@' && !empty($cmd)) {		
			// Set environment variable to show verbosity level
			if ($this->_debug >= self::DEBUG_INFORMATION) {
				putenv('DEPLOY_VERBOSITY=1');
			} else {
				putenv('DEPLOY_VERBOSITY=0');
			}
			$this->_debugOutput("Running post-deploy script \"$cmd...\"", self::DEBUG_GENERAL);
			$this->_debugOutput('---------- OUTPUT BELOW IS FROM POST DEPLOY SCRIPT, NOT WADF ----------', self::DEBUG_GENERAL);
			passthru($cmd);
			$this->_debugOutput('------------------ END OF POST DEPLOY SCRIPT OUTPUT -------------------', self::DEBUG_GENERAL);
		}
	}
	
	public function cleanupFiles()
	{
		$files = $this->resolveMacro('post_deploy_cleanup_files');
		if (!empty($files) && $files != '@post_deploy_cleanup_files@') {
			$deploy_path = $this->resolveMacro('deploy_path');
			$this->_debugOutput("Cleaning up special files...", self::DEBUG_INFORMATION);
			$this->_debugOutput("Files to clean up are $files", self::DEBUG_VERBOSE);
			foreach(explode(' ', $files) as $file) {
				$force_remove = false;
				$file = trim($file);
				if (strlen($file) > 0) {
					if ($file{0} == '+') { // force removal even if template doesn't exist
						$file = substr($file, 1);
						$force_remove = true;
					}
					chdir($deploy_path);
					if (file_exists($file)) {
						if ($force_remove || file_exists("$file.template")) {
							$this->_debugOutput("  Removing $file", self::DEBUG_INFORMATION);
							unlink($file);
						}
					}
				}
			}
		}	
	}
	
	public function restartWebserver()
	{
		$restart_cmd = $this->resolveMacro('webserver_restart_cmd');
		if ($restart_cmd != '@webserver_restart_cmd@' && !empty($restart_cmd)) {
			$this->_debugOutput("Restarting webserver...", self::DEBUG_GENERAL);
			passthru($restart_cmd);
		}
	}
	
	/**
	 * @param string $file Filename to read. If file does not exist, no error will be thrown.
	 * @return array Array of Apache mod_php config rules
	 */
	protected function _convertPhpIniToApacheModPhp($file)
	{
		$directives_out = array();
		if (file_exists($file)) {
			$this->_debugOutput("\tProcessing PHP config file $file...", self::DEBUG_INFORMATION);
			$php_ini_directives = file($file);
			$php_ini_all = ini_get_all();
			if (!isset($php_ini_all['engine'])) { // for some reason this isn't defined in at least PHP 5.2.12
				$php_ini_all['engine'] = array('access' => INI_SYSTEM);
			}
			$directives_out = array();
			foreach ($php_ini_directives as $directive) {
				$directive = trim($directive);
				if (empty($directive)) continue;
				if (preg_match('/^\s*;(.+)$/', $directive, $matches)) {
					// Comment; convert php.ini-style semicolons to hash signs
					$directives_out[] = '#' . $matches[1];
				} else if (preg_match('/^([a-z0-9_\.]+)\s*=\s*(.+)$/', $directive, $matches)) {
					$directive_name = strtolower(trim($matches[1]));
					$directive_value = trim($matches[2]);
					
					if (isset($php_ini_all[$directive_name])) {
						$directive_type = 'value'; // flag or value
						// See if it looks like a flag or a value
						if (in_array(strtolower($directive_value), array('0','1','on','off'))) {
							$directive_type = 'flag';
						} else {
							if (strpos($directive_value, ' ') && $directive_value{0} != '"') {
								$directive_value = '"' . $directive_value . '"';
							}
						}
						
						if ($php_ini_all[$directive_name]['access'] & INI_ALL & INI_PERDIR) {
							$prefix = 'php_';
						} else {
							$prefix = 'php_admin_';
						}
						$directives_out[] = $prefix . $directive_type . " $directive_name $directive_value";
					} else {
						$directives_out[] = "# WADF: ignored unknown configuration option: $directive";
						$this->_debugOutput("WARNING: Unknown PHP configuration option '$directive_name' in $file", self::DEBUG_WARNING);
					}
				} else {
					$this->_debugOutput("WARNING: Could not parse PHP ini config line from $file:\n\t$directive", self::DEBUG_WARNING);
				}
			}
		}
		return $directives_out;
	}
	
	// Not needed for mod_php; it is done in deployVhost();
	protected function _deployPHPConfig($dir)
	{
		$php_type = $this->resolveMacro('php_type');
		if ($php_type == 'mod_php') {
			return; // do nothing
		} else if (preg_match('/^cgi:(.+)$/', $php_type, $matches)) {
			$php_ini_dest = trim($matches[1]);
			$php_ini = $this->resolveMacro('php_config_location');
			$php_ini_source = $dir . '/' . $php_ini;
			if (file_exists($php_ini_source)) {
				$this->_debugOutput("Deploying PHP config file to $php_ini_dest...", self::DEBUG_GENERAL);
				
				$source = file_get_contents($php_ini_source);
				$php_ini_local = trim($this->resolveMacro('php_config_location_extra'));
				if ($php_ini_local != '@php_config_location_extra@' && !empty($php_ini_local)) {
					$extras = file_get_contents($php_ini_local);
					$source .= "\n; PHP directives processed from $php_ini_local\n" . $extras;
				}
				$dest_dir = dirname($php_ini_dest);
				if (!file_exists($dest_dir)) {
					$this->_debugOutput("Creating directory $dest_dir for deployment of PHP config file...", self::DEBUG_GENERAL);
					System::mkdir(array('-p', $dest_dir));
				}
				file_put_contents($php_ini_dest, $source);
			}
		} else {
			throw new Exception("Unknown PHP type '$php_type'");
		}
		return true;
	}
	
	/**
	 * Check out something from version control system
	 * @param string $destdir         The destination to check a working copy out to
	 * @param string $revtype         One of the Tools_WADF::VCREFTYPE_* constants
	 * @param string $rev_translated  The "translated" revision which makes sense according to $revtype. For tags, this is the tag. For branches, the branch name. For trunk, pass NULL.
	 * @param string $raw_rev         Raw changeset number. Not valid for tags. Defaults to HEAD.
	 */
	public function checkout ($destdir, $revtype, $rev_translated, $raw_rev=null)
	{
		// MODULE is appref
		// BASE URL is vc_base
		
		// See if we already have a checked-out copy
		// We could just do a file_exists() on .wadf-instance, but actually
		// checking for version control metadata is safer
		$vc_info = $this->_vc->readVCInfoFromDir($destdir);
		
		$action = 'checkout';
		if (is_object($vc_info)) {
			$action = 'switch';
		}
		
		if ($action == 'checkout') {
			$this->_rmDir($destdir);
		} else {
			$this->cleanGeneratedFiles($destdir);
		}

		$checkout_desc = $this->_vc->getLabel($revtype, $rev_translated, $raw_rev);
		$this->_debugOutput("Checking out $checkout_desc...", self::DEBUG_GENERAL);
		
		if ($action == 'checkout') {
			$this->_vc->checkout($revtype, $rev_translated, $raw_rev, $destdir);
		} else {
			$this->_vc->switchVer($revtype, $rev_translated, $raw_rev, $destdir);
		}
		
		$this->_writeInstanceFile("$destdir/.wadf-instance");
		
		$this->setVCVersionMacro($destdir);
		return true;
	}
		
	/**
	 * Write a WADF instance file
	 *
	 * @param string $file  The file to write
	 */
	protected function _writeInstanceFile($file)
	{
		$file_already_exists = false;
		if (file_exists($file)) {
			$file_already_exists = true;
			$existing_instance_file = file($file);
		}
		
		$fp = @fopen($file, 'w');
		if (!is_resource($fp)) {
			throw new Exception("Could not open instance file $file for writing");
		}
			
		// Write the instance name to the instance file
		fputs($fp, $this->resolveMacro('instance'));
		fputs($fp, "\n");
		
		if ($file_already_exists) {
			// File already exists, so we just need to make sure the appref is
			// set correctly and add any forced params from the command line
			unset($existing_instance_file[0]);
			fputs($fp, implode('', $existing_instance_file));
		}
		
		// Add any command line macros
		if (count($this->_cmdline_macros) > 0) {
			foreach ($this->_cmdline_macros as $name => $value) {
				if ($name != 'instance') { // instance is special; it's already been written on the first line of the file
					fputs($fp, "$name = $value\n");
				}
			}
		}
		
		fclose($fp);
	}
	
	/**
	 * Get a unique identifying version string and set it as a macro
	 * @return string The version control identifier
	 */
	public function setVCVersionMacro($dir)
	{
		$live_vc_info = $this->_vc->readVCInfoFromDir($dir);
		if ($live_vc_info === false) {
			// The version control identifier could not be read (perhaps the site
			// hasn't been deployed yet?) so return an "unknown" id
			$vc_id = $this->getVCIdentifier(Tools_WADF::VCREFTYPE_UNKNOWN, null, null, false);
		} else {
			$vc_id = $this->getVCIdentifier($live_vc_info->rev_type, $live_vc_info->rev_translated, $live_vc_info->rev_raw, $live_vc_info->modifications);
		}
		$this->_appendMacroDefs(array('deploy_version' => $vc_id));
		return $vc_id;
	}
	
	/**
	 * @param $revtype       const A Tools_WADF::VCREFTYPE_* const
	 * @param $rev_translated The "human readable" version control identifier - for branches/tags this is the branch/tag name
	 * @param $rev_raw       string The raw version control system changeset number or similar
	 * @param $modifications bool  Whether or not manual modifications have been made to the site
	 */
	public static function getVCIdentifier($revtype, $rev_translated, $rev_raw, $modifications)
	{
		switch ($revtype) {
			case Tools_WADF::VCREFTYPE_TRUNK:
				$output = 'DEVTR' . ':' . $rev_raw;
				break;
			case Tools_WADF::VCREFTYPE_BRANCH:
				$output = 'DEVBR' . '/' . $rev_translated . ':' . $rev_raw;
				break;
			case Tools_WADF::VCREFTYPE_TAG:
				$output = $rev_translated;
				break;
			case Tools_WADF::VCREFTYPE_UNKNOWN:
				$output = 'unknown';
				break;
		}
		if ($modifications) {
			$output .= ' (with modifications)';
		}
		return $output;
	}

	/**
	 * 
	 * @param $string
	 * @param $rev
	 * @return array|string  If a string is returned, there was an error and the string contains the error message
	 */
	public function processVCVersionString($string, $rev='HEAD')
	{	
		$out['rev_type'] = Tools_WADF::VCREFTYPE_TRUNK;
		$out['rev_translated'] = null;
		
		if (preg_match('#^(trunk|tag|branch)(/(.+))?$#', $string, $matches)) {
			if (isset($matches[3])) {
				$out['rev_translated'] = trim($matches[3]);
			}
			
			switch ($matches[1]) {
				case 'trunk':
					if (isset($matches[2])) {
						return 'trunk does not take a parameter';
					}
					break;
				case 'tag':
					$out['rev_type'] = Tools_WADF::VCREFTYPE_TAG;
					if ($rev != 'HEAD') {
						return "Don't pass a revision number when deploying from a tag";
					}
					
					if ($out['rev_translated'] == 'LATEST') {
						$tags = $this->_vc->listTags();
						if ($tags === false) return 'Listing tags failed';
						$tags = $this->_sortVersions($tags);
						if (count($tags) == 0) {
							return 'Could not find any tags in version control';
						}
						$out['rev_translated'] = array_pop($tags);
						$this->_debugOutput("Using latest tagged version: " . $out['rev_translated'], self::DEBUG_GENERAL);
					}
					
					if (empty($out['rev_translated'])) {
						return "Missing tag number";
					}
					break;
				case 'branch':
					$out['rev_type'] = Tools_WADF::VCREFTYPE_BRANCH;
					if (empty($out['rev_translated'])) {
						return "Missing branch name";
					}
					break;
			}
		} else {
			return "Could not parse revision type '$string'";
		}
		return $out;
	}
	
	/**
	 * Sort an array of software version numbers according to a modified
	 * natural sort algorithm, where "beta" and other character-suffixed versions
	 * come before the non-suffixed equivalents
	 *
	 * @param array $versions  Array of version numbers to sort
	 * @return array  Sorted array of version numbers
	 */
	protected function _sortVersions($versions)
	{
		$versions_with_suffixes = array();

		foreach ($versions as $version) {
			// Look for tags ending in a number followed by one or more letters (i.e. "b", "beta", "alpha" etc.)
			// We want to later sort those differently (prior to the "base" version)
			if (preg_match('/^(.+\d)([a-z]+)$/i', $version, $matches)) {
				// We record the length because we later need to pad all versions ending in suffixes to the longest length
				$len = strlen($version);
				if (!isset($versions_with_suffixes[$matches[1]]) || $versions_with_suffixes[$matches[1]] < $len) {
					$versions_with_suffixes[$matches[1]] = $len;
				}
			}
		}
		
		// Pad all base versions which have at least one suffixed equivalent
		// with "z"s so they will sort last (i.e. after the suffixed ones)
		$output = array();
		$nasty_replacement = array();
		foreach ($versions as $version) {
			if (isset($versions_with_suffixes[$version])) {
				$hack = str_pad($version, $versions_with_suffixes[$version], 'z', STR_PAD_RIGHT);
				$output[] = $hack;
				$nasty_replacement[$hack] = $version;
			} else {
				$output[] = $version;
			}
		}
		$versions = $output;
		
		natsort($versions);
		
		// Undo the "zzz" hacked tags
		$output = array();
		foreach ($versions as $version) {
			if (isset($nasty_replacement[$version])) {
				$output[] = $nasty_replacement[$version];
			} else {
				$output[] = $version;
			}
		}
		return $output;
	}
	
	/**
	 * Set up a PEAR installation
	 *
	 * @param string $dir  Directory containing site deployment
	 * @return bool        Whether or not the PEAR installation was "standalone" (site-specific)
	 */
	protected function _setupPEAR($dir)
	{
		$pear_path = $this->resolveMacro('dep_pear_deploy_path');
		$standalone_pear = false;
		// if empty, we want to use already-installed PEAR
		if (!empty($pear_path)) {
			$pear_config_file = $this->resolveMacro('dep_pear_config_file');
			if (empty($pear_config_file)) {
				throw new Exception("No dep_pear_config_file option specified");
			}
			$standalone_pear = true;
			
			if (!file_exists($pear_config_file)) {
				$cmd = "pear ".$this->_getPEARVerbosity()." -c $pear_config_file ".
					"-d php_dir=$pear_path/php ".
					"-d data_dir=$pear_path/data ".
					"-d ext_dir=$pear_path/ext ".
					"-d doc_dir=$pear_path/docs ".
					"-d test_dir=$pear_path/tests ".
					"-d cache_dir=$pear_path/cache ".
					"-d download_dir=$pear_path/downloads ".
					"-d temp_dir=$pear_path/temp ".
					"-d bin_dir=$pear_path ".
					"-s";
				$this->_runPEAR($cmd);//exec($cmd);
					
				$this->_debugOutput("PEAR dependencies found; creating a PEAR installation in $pear_path (config file is $pear_config_file)...", self::DEBUG_GENERAL);
				
				$pear_version = $this->resolveMacro('dep_pear_restrict_version');
				$pear_package = empty($pear_version) ?  'pear.php.net/PEAR' : "pear.php.net/PEAR-{$pear_version}";
				$cmd = "pear {$this->_getPEARVerbosity()} -c {$pear_config_file} install --onlyreqdeps {$pear_package}";
				$this->_runPEAR($cmd);
				$this->_runPEAR("channel-update pear", false, false);
			}
		} else {
			$output = $this->_runPEAR($this->_getPEARCmd() . ' config-get bin_dir');
			$pear_path = $output[0];
			$this->_debugOutput("Using existing PEAR installation in $pear_path", self::DEBUG_INFORMATION);
		}

		$this->_configurePEARBaseChannel();		
		$this->_installPEARBaseRoles();
		$this->_configurePEARBaseRoles($pear_path, $dir, $standalone_pear);
	
		return $standalone_pear;
	}
	
	/**
	 * Configure the base channel that may be used when installing PEAR dependencies
	 * 
	 * @return void
	 */
	protected function _configurePEARBaseChannel()
	{
		$base_channel = $this->resolveMacro('dep_pear_base_channel');
		if (!empty($base_channel)) {
			if (preg_match('/^(.+):(.+)@(.+)$/',$base_channel, $matches)) {
				$username = $matches[1];
				$channel = $matches[3];
			} else {
				$channel = $base_channel;
			}
			if (empty($channel)) throw new Exception('dep_pear_base_channel option is empty');
			$ret = $this->_runPEAR("channel-info $channel", false, false);
			if ($ret != 0) {
				$this->_debugOutput("Discovering PEAR channel $channel...", self::DEBUG_GENERAL);
				
				// With PEAR 1.6.x+ the base_channel can include username and
				// password in the format username:password@channel
				$this->_runPEAR("channel-discover $base_channel", false, false);
				$this->_runPEAR("channel-update $channel", false, false);
				if (isset($username)) {
					$this->_debugOutput("Logged into PEAR channel $channel as user $username", self::DEBUG_INFORMATION);
				}
			} else {
				$logout_channel = $this->resolveMacro('dep_pear_base_channel_logout_after_deploy');
				if ($logout_channel == '1') {
					// If this option is set, we assume that a channel login is required
					$pearcmd = $this->_getPEARCmd();
					passthru("$pearcmd login $channel");
				}
			}
		}
	}
	
	/**
	 * Set up and deploy dependencies
	 *
	 * @param string $dir Directory that the site has been deployed to
	 */
	public function deployDependencies($dir, $dependency_tags_enabled=true)
	{
		$pear_setup = false;
		$standalone_pear = false;
		
		$local_pear_packages_to_install = array();
		$pear_deps_to_force_install = array();
		$install_pear_deps_from_packagexml = false;

		// If a "dependency tag file" is found, force-install everything in it.
		$dep_tag_file = $this->resolveMacro('dep_tags_file');
		if ($dependency_tags_enabled && !empty($dep_tag_file) && $dep_tag_file != '@dep_tags_file@' && file_exists($dep_tag_file)) {
			// See if we need PEAR for this deployment; if so, force install without dependencies so
			// that there are no bogus errors about mismatching dependency requirements from the client site package.xml
			if (file_exists("$dir/package.xml")) {
				$standalone_pear = $this->_setupPEAR($dir);
				$pear_setup = true;
				$this->_debugOutput("Installing site package.xml for dependency tracking...", self::DEBUG_GENERAL);
				$application_dir = '';
				if (!$standalone_pear) {
					// Deploy to the same directory. Is !$standalone_pear really the
					// right criteria to use here?
					$application_dir = '-d application_dir=' . $this->resolveMacro('application_dir');
				}
				$this->_runPEAR("$application_dir upgrade --nodeps --force $dir/package.xml", false, true, false);
			}
			
			$this->_debugOutput("Force-installing tagged versions of dependencies from $dep_tag_file...", self::DEBUG_GENERAL);
			$dep_tags = @file_get_contents($dep_tag_file);
			if ($dep_tags !== false) {
				$deps = $this->processDepTagFile($dep_tags);
				foreach ($deps as $dep) {
					if ($dep->type == Tools_WADF::DEPENDENCY_TYPE_PEAR) {
						$pear_deps_to_force_install[] = $dep->name . '-' . $dep->version;
					} else {
						if (class_exists($dep->type)) {
							$vc = new $dep->type($this);
							$path = $vc->installSingleDependency($dep);

							if (isset($path) && file_exists("$path/package.xml")) {
								$this->_debugOutput("Marking $path/package.xml as a PEAR package to install...", Tools_WADF::DEBUG_INFORMATION);
								$local_pear_packages_to_install[] = "$path/package.xml";
							}
						} else {
							$this->_debugOutput("Unrecognised dependency type '$dep->type'", self::DEBUG_WARNING);
						}
					}
				}
			} else {
				$this->_debugOutput("Could not open dependency tag file $dep_tag_file", self::DEBUG_WARNING);
			}
		} else {
			// See if we need PEAR for this deployment
			if (file_exists("$dir/package.xml")) {
				$install_pear_deps_from_packagexml = true;
			}
		}
		
		// Check for local PEAR packages
		$local_pear_package_dirs = $this->resolveMacro('dep_pear_local_package_dirs');
		if ($local_pear_package_dirs && !$local_pear_package_dirs != '@dep_pear_local_package_dirs@') {
			$package_dirs = explode(',', $local_pear_package_dirs);
			foreach ($package_dirs as $package_dir) {
				$package_dir = trim($package_dir);
				if (!empty($package_dir)) {
					$dir_to_check = $dir . DIRECTORY_SEPARATOR . trim($package_dir);
					$this->_debugOutput("Looking for local PEAR packages in $dir_to_check...", self::DEBUG_INFORMATION);
					$files = self::listAllFiles($dir_to_check);
					foreach ($files as $file) {
						$basename = basename($file);
						if ($basename == 'package.xml' || $basename == 'package2.xml') {
							if (!in_array($file, $local_pear_packages_to_install)) {
								$this->_debugOutput("Marking $file as a PEAR package to install...", self::DEBUG_INFORMATION);
								$local_pear_packages_to_install[] = $file;
							} else {
								$this->_debugOutput("Not marking $file as a PEAR package to install; already listed...", self::DEBUG_INFORMATION);
							}
						}
					}
				}
			}
		}
		
		// Set up a PEAR installation if we need it and it hasn't already been set up
		if ($install_pear_deps_from_packagexml || count($pear_deps_to_force_install) > 0 || count($local_pear_packages_to_install) > 0) {
			if (!$pear_setup) {
				$standalone_pear = $this->_setupPEAR($dir);
				$pear_setup = true;
			}
		}
		
		// Install local PEAR packages
		if (count($local_pear_packages_to_install) > 0) {
			$list_of_packages = implode(' ', $local_pear_packages_to_install);
			foreach ($local_pear_packages_to_install as $pkg) {
				$short_list_of_packages[] = substr($pkg, strlen($dir)+1);
			}
			$short_list_of_packages = implode(' ', $short_list_of_packages);
			$this->_debugOutput("Installing local PEAR packages $short_list_of_packages...", self::DEBUG_GENERAL);
			// First install without dependencies (saves some potential dependency problems)
			$this->_runPEAR("upgrade --nodeps --force $list_of_packages", false, false, false);
			// Then install again, pulling in dependencies
			// We enable PEAR bug workarounds here as they can just as well strike for local packages
			// We install the packages individually to avoid the PEAR issue described in bug 271 (installing old versions from channel in favour of new ones from local pkgs)
			foreach ($local_pear_packages_to_install as $pkg) {
				$this->_runPEAR("upgrade --onlyreqdeps -f $pkg", true, true, true);
			}
		}
		
		// Force-install tagged PEAR dependencies
		if (count($pear_deps_to_force_install) > 0) {
			$final_list_of_deps_to_force_install = array();
			foreach ($pear_deps_to_force_install as $pkg_to_install) {
				// we do this on every iteration in case a previous install has
				// changed the list
				$pkgs = $this->_getInstalledPearPackages();
				$pkgvers = array();
				foreach ($pkgs as $name => $ver)
				{
					$pkgvers[] = $name . '-' . $ver;
				}
				// dep->name includes the channel name
				if (!in_array($pkg_to_install, $pkgvers)) {
					$final_list_of_deps_to_force_install[] = $pkg_to_install;
				}
			}
			if (count($final_list_of_deps_to_force_install) > 0) {
				$list_of_deps = implode(' ', $final_list_of_deps_to_force_install);
				$this->_debugOutput("Force-installing $list_of_deps", self::DEBUG_INFORMATION);
				$this->_runPEAR('upgrade --force --nodeps ' . $list_of_deps, true, false, false);
			}
		}
		
		// Install the client site package.xml to pull in the PEAR dependencies
		// if dep tags are not in use
		if ($install_pear_deps_from_packagexml) {
			$this->_debugOutput("Installing PEAR dependencies...", self::DEBUG_GENERAL);
			$application_dir = '';
			if (!$standalone_pear) {
				// Deploy to the same directory. Is !$standalone_pear really the
				// right criteria to use here?
				$application_dir = '-d application_dir=' . $this->resolveMacro('application_dir');
			}
			$this->_runPEAR("$application_dir upgrade --onlyreqdeps -f $dir/package.xml", true, true, true);
		}
		
		$this->_cleanupPEAR($standalone_pear);
		
		$this->_setPearMacros();
		
	}
	
	/**
	 * Return a list of packages installed in the deployed sites' PEAR directory
	 *
	 * @return array  List of packages in "channel://package-version" format
	 */
	protected function _getInstalledPearPackages()
	{
		require_once 'PEAR/Registry.php';
		$pear_path = $this->resolveMacro('dep_pear_deploy_path');
		$pear = new PEAR_Registry($pear_path . DIRECTORY_SEPARATOR . 'php');
		$installed = $pear->packageInfo(null,null,null);
		$packagelist = array();
		foreach ($installed as $channel => $packages) {
			foreach ($packages as $package) {
				if ($package['xsdversion'] >= 2) {
					$pkgname = $package['channel'] . '/' . $package['name'];
					$packagelist[$pkgname] = $package['version']['release'];
				} else {
					// old package.xml v1
					$pkgname = "pear.php.net/" . $package['package'];
					$packagelist[$pkgname] = $package['version'];
				}
			}
		}
		asort($packagelist);
		return $packagelist;
	}
	
	/**
	 * Process a dependency tags file and return a list of dependencies from it
	 *
	 * @param string  Contents of a dep tags file
	 * @return array  Array of Tools_WADF_Dependency objects
	 */
	public function processDepTagFile($contents)
	{
		$deps = array();
		$lines = explode("\n", $contents);
		foreach ($lines as $line) {
			$line = trim($line);
			if (!empty($line) && $line{0} != '#' && $line{0} != ';') { // comments
				// lines are in format "deptype:depdetails" (see docs/wadf.txt for format details)
				if (preg_match("/^([a-z0-9]{2,10}):(.+)$/i", $line, $matches)) {
					if ($matches[1] == Tools_WADF::DEPENDENCY_TYPE_PEAR) {
						$dep = new Tools_WADF_Dependency_PEAR();
						list($dep->name, $dep->version) = explode('-', $matches[2]);
					} else {
						try {
							$vc_class = self::loadVCDriver($matches[1]);
						} catch (Exception $e) {}
						if (!isset($vc_class)) {
							$this->_debugOutput("Unknown dependency type '$matches[1]' in dep tags file - line was '$line'", self::DEBUG_WARNING);
						}

						$dep = call_user_func(array($vc_class, "getDependencyDetails"), $matches[2]);
						if (!isset($dep)) {
							$this->_debugOutput("Unknown dependency syntax in '$matches[2]'", self::DEBUG_WARNING);
						}
					}

					if (isset($dep)) {
						$deps[] = clone $dep;
					}
				} else {
					$this->_debugOutput("Unknown dependency syntax in dep tags file - line was '$line'", self::DEBUG_WARNING);
				}
			}
		}
		return $deps;
	}
	
	public function undeployDependencies($dir)
	{
		// See if we actually need PEAR for this deployment
		if (!file_exists("$dir/package.xml")) {
			return;
		}
		
		$pear_path = $this->resolveMacro('dep_pear_deploy_path');
		if (!empty($pear_path)) {
			// standalone PEAR
			$pear_config_file = $this->resolveMacro('dep_pear_config_file');
			$this->_debugOutput("Removing PEAR config file $pear_config_file...", self::DEBUG_INFORMATION);
			@unlink($pear_config_file);
			$this->_debugOutput("Removing PEAR installation at $pear_path...", self::DEBUG_INFORMATION);
			$this->_rmDir($pear_path);
		}
	}

	protected function _getPEARVerbosity() {
		// We are not using the "quiet" option - stripping the output instead
		if ($this->_debug < self::DEBUG_INFORMATION) {
			return ''; 
		} else { // DEBUG_INFORMATION is set
			return ' -v '; 
		}
	}

	protected function _installPEARBaseRoles()
	{
		$base_roles = $this->resolveMacro('dep_pear_base_roles');
		
		if (!empty($base_roles)) {
			// The command line binary to run
			$pear = $this->_getPEARCmd();
			
			$roles = explode(' ', $base_roles);
			foreach ($roles as $package) {
				exec("$pear list $package", $out, $ret);
				if ($ret != 0) {
					$this->_debugOutput("Installing base role package $package...", self::DEBUG_INFORMATION);
					$this->_runPEAR("install --soft --onlyreqdeps $base_roles");
				}
			}
		}
	}
	
	protected function _configurePEARBaseRoles($pear_path, $dir, $standalone_pear=false)
	{
		if (empty($pear_path)) throw new Exception('Empty pear_path passed to Tools_WADF::_configurePEARBaseRoles()');
	
		$vars = array();
		
		if ($standalone_pear) {
			$vars['application_dir'] = $this->resolveMacro('application_dir');
		}
		
		$base_channel = $this->_getPEARBaseChannelName();
		
		// The command line binary to run
		$pear = $this->_getPEARCmd();
		
		// Set the variables if needed
		foreach ($vars as $var => $value) {
			unset($out);
			unset($ret);
			exec("$pear config-get -c $base_channel $var", $out, $ret);
			if (empty($out[0]) || $standalone_pear) {
				$this->_debugOutput("Setting $var = $value", self::DEBUG_INFORMATION);
				if (!empty($base_channel)) {
					$this->_runPEAR("config-set -c $base_channel $var \"$value\"\n", false);
				} else {
					$this->_runPEAR("config-set $var \"$value\"\n", false);
				}
			} else {
				$this->_debugOutput("Base role config variable $var is already set ($out[0]) - not changing", self::DEBUG_INFORMATION);
			}
		}
	}
	
	/**
	 * Returns base channel name *without* any optional username/pass
	 */
	protected function _getPEARBaseChannelName()
	{
		$channel = null;
		$base_channel = $this->resolveMacro('dep_pear_base_channel');
		if (!empty($base_channel)) {
			if (preg_match('/^(.+):(.+)@(.+)$/',$base_channel, $matches)) {
				$channel = $matches[3];
			} else {
				$channel = $base_channel;
			}
			if (empty($channel)) throw new Exception('dep_pear_base_channel is empty');
		}
		return $channel;
	}
	
	protected function _runPEAR($cmd, $output=true, $fail_on_error=true, $request_bug_workarounds=false)
	{
		$pear_stability = $this->resolveMacro('dep_pear_preferred_stability');
		if ($pear_stability) {
			$pear_stability = "-d preferred_state=$pear_stability";
		}
		if (preg_match('/^pear/',$cmd)) {
			$runcmd = $cmd; 
		} else {
			$runcmd = $this->_getPEARCmd() . " $pear_stability " . $cmd;			
		}
		
		$this->_debugOutput("Running: $runcmd", self::DEBUG_INFORMATION);
		$this->_debugOutput("Output: $output, fail on error: $fail_on_error, bug workarounds: $request_bug_workarounds", self::DEBUG_VERBOSE);

		exec($runcmd, $exec_output, $ret);

		if ($this->_debug < self::DEBUG_GENERAL) {
			$output = false;
			$strip = '/install|upgrade|optional|^\.+done\:|Starting to download|downloading|channel "pear.php.net" has updated its protocols/i';
		} elseif ($this->_debug < self::DEBUG_INFORMATION) {
			$strip = '/upgrade failed|optional|^\.+done\:|Starting to download|downloading|channel "pear.php.net" has updated its protocols/i';
		} elseif ($this->_debug < self::DEBUG_VERBOSE) {
			$strip = '/^\.+done\:|Starting to download|downloading|channel "pear.php.net" has updated its protocols/i';
		} else {//DEBUG_VERBOSE
			$strip = false;
		}
		if ($fail_on_error && $ret != 0) {
			$output = true;
		}
		
		$duplicate_packages = array(); // workaround for PEAR bug #13425
		$wrongly_upgraded_packages = array(); // workaround for PEAR bug #13427
		$warnings = array();
		$deprecated = array();
		foreach ($exec_output as $line) {
			unset($m);
			unset($m2);
			$line = trim($line);
			if (preg_match("/WARNING: (.*)/i", $line, $m) && !strstr($line, 'failed to download') && !strstr($line, 'channel "pear.php.net" has updated its protocols')) {
				if (preg_match("/deprecated/", $line)) {
					$deprecated[] = $m[1];
				} else if ($request_bug_workarounds && preg_match('#^([^/]+/.+) requires package "([^/]+/.+)" \(version <= (.+)\), downloaded version is (.+)$#', $m[1], $m2)) {
					// channelshortname/App_Name requires package "channelshortname/Other_App" ([version >= a.b.c, ]version <= 1.2.3), downloaded version is 3.4.5
					$wrongly_upgraded_packages[$m2[2]] = array('installed_ver' => $m2[4], 'downgrade_to_ver' => $m2[3], 'dependent_app' => $m2[1]);
				} else {
					$warnings[] = $m[1];
				}
			} else if ($request_bug_workarounds && preg_match('#^Duplicate package channel://([^/]+/[^-]+)-(.+) found#', $line, $m)) {
				$duplicate_packages[$m[1]][] = $m[2];
			} elseif (!$strip || !preg_match($strip, $line)) {
				if ($output) $this->_debugOutput("\t" . $line, self::DEBUG_GENERAL);
			}
		}
		
		if ($output) {
			if (count($deprecated) > 0 && $this->_debug >= self::DEBUG_INFORMATION) {
				foreach($deprecated as $line) {
					$this->_debugOutput('Deprecated: ' . $line, self::DEBUG_INFORMATION);
				}
			}			
	
			if (count($warnings) > 0 && $this->_debug >= self::DEBUG_WARNING) {
				$this->_debugOutput("\t###### PEAR WARNINGS FOLLOW:", self::DEBUG_WARNING);
				foreach($warnings as $line) {
					$this->_debugOutput("\t\t" . $line, self::DEBUG_WARNING);
				}
				$this->_debugOutput("\t###### END OF PEAR WARNINGS", self::DEBUG_WARNING);
			}
		}
		
		if ($request_bug_workarounds) {
			$workarounds = array();
			$workaround_options = $this->resolveMacro('dep_pear_bug_workarounds');
			if (!empty($workaround_options) && $workaround_options != '@dep_pear_bug_workarounds@') {
				$valid_workarounds = array('bug13425','bug13427');
				$tmp = explode(',', $workaround_options);
				foreach ($tmp as $opt) {
					$opt = trim($opt);
					if (in_array($opt, $valid_workarounds)) {
						$workarounds[] = $opt;
					} else {
						throw new Exception("Invalid value in dep_pear_bug_workarounds: '$opt'");
					}
				}
			}
			// workaround for PEAR bug #13425
			if (in_array('bug13425', $workarounds) && count($duplicate_packages) > 0) {
				foreach ($duplicate_packages as $pkg => $vers) {
					$vers = $this->_sortVersions($vers);
					// Let's be conservative and pick the OLDEST version that was listed, especially because of PEAR bug #13427
					// (<max> does not completely exclude dep versions from consideration)
					$ver_picked = $vers[0];
					$this->_debugOutput("\tWARNING: Multiple PEAR dependencies on a specific version of $pkg (" . implode(', ', $vers) . ") - probable cause is PEAR bug #13425", self::DEBUG_WARNING);
					
					// Make sure we don't downgrade an existing installed package, or this can cause loops
					$installed_ver = $this->_getInstalledPearPkgVersion($pkg);
					if (!$installed_ver || version_compare($ver_picked, $installed_ver, '>')) {
						$this->_debugOutput("\tForce-installing $pkg-$ver_picked", self::DEBUG_WARNING);
						$this->_runPEAR("upgrade -f $pkg-$ver_picked", true, true, true);
					} else {
						$this->_debugOutput("\tNot installing $pkg-$ver_picked; $pkg-$installed_ver is already installed");
					}
				}
				$this->_debugOutput("\tRe-running PEAR deployment with newly-installed dependencies...", self::DEBUG_GENERAL);
				$ret = $this->_runPEAR($cmd, $output, $fail_on_error, true);
			}

			// workaround for PEAR bug #13427
			if (in_array('bug13427', $workarounds) && count($wrongly_upgraded_packages) > 0) {
				foreach ($wrongly_upgraded_packages as $pkg => $pkginfo) {
					$this->_debugOutput("\tWARNING: $pkg-" . $pkginfo['installed_ver'] . ' was installed, but ' . $pkginfo['dependent_app'] . ' requires version <= ' . $pkginfo['downgrade_to_ver'] . ' (possible cause: PEAR bug #13427)', self::DEBUG_WARNING);
					$this->_debugOutput("\tAttempting to force-install $pkg-" . $pkginfo['downgrade_to_ver'] . ' to compensate', self::DEBUG_WARNING);
					// Set fail to FALSE as we don't really care
					$this->_runPEAR("upgrade -f $pkg-" . $pkginfo['downgrade_to_ver'], true, false, true);
				}
			}
		}
		
		if($fail_on_error && $ret != 0) {
			$this->_debugOutput("\t###### FAILED when installing PEAR dependencies", self::DEBUG_ERROR);
			$this->_debugOutput("\tPEAR return code was $ret)", self::DEBUG_INFORMATION);
			exit;
		}
		return $ret;
	}
	
	/**
	 * @param $pkg Package name, including a channel prefix
	 */
	protected function _getInstalledPearPkgVersion($pkg)
	{
		$pkglist = $this->_getInstalledPearPackages();
		if (isset($pkglist[$pkg])) {
			return $pkglist[$pkg];
		}
		return false;
	}
	
	protected function _getPEARCmd()
	{
		$verbosity = $this->_getPEARVerbosity();
		$pear_path = $this->resolveMacro('dep_pear_deploy_path');
		if (empty($pear_path)) {
			exec('pear '.$verbosity.' config-get bin_dir', $output, $ret);
			$pear_path = $output[0];
			$runcmd = "$pear_path/pear $verbosity";
		} else {
			$pear_config_file = $this->resolveMacro('dep_pear_config_file');
			if (file_exists("$pear_path/pear")) {
				$runcmd = "$pear_path/pear $verbosity -c $pear_config_file";
			} else {
				// There is a special case where the PEAR binary might not exist, when
				// we are just initialising the object, and the deployment wants
				// a sandboxed PEAR install, but that's not happened yet.
				// In that case, we call the system PEAR. 
				$runcmd = "pear $verbosity";
			}
		}
		return $runcmd;
			
	}
	
	/**
	 * Add a selection of PEAR config options to the macro list
	 */
	protected function _setPearMacros()
	{
		$macro_defs = array();
	
		// Technically we should have an option here to decide whether we are 
		// going to use PEAR's value for application_dir (i.e. the 
		// directory that PEAR installs the actual end client app into), or use
		// the actual working copy as the "real" live application. However,
		// for the time being we force it to be deploy_path (the working copy
		// checked out from version control) for simplicity.
		$macro_defs['application_dir'] = $this->resolveMacro('deploy_path');
	
		$pear_opts_as_macros = $this->resolveMacro('dep_pear_opts_as_macros');
		if (!empty($pear_opts_as_macros) && $pear_opts_as_macros != '@dep_pear_opts_as_macros@') {
			$pear_macros = explode(' ', $pear_opts_as_macros);
			$base_channel = $this->_getPEARBaseChannelName();
		
			foreach ($pear_macros as $macro) {
				// If this is the system PEAR, the "bad" macros
				// will get overridden later by a call to this 
				// method (_setPearMacros()) from
				// deployDependencies();
				$pear = $this->_getPEARCmd();
				
				unset($out);
				exec("$pear config-get -c $base_channel $macro", $out, $ret);
				if ($ret == 0 && !empty($out[0])) {
					$macro_defs[$macro] = trim($out[0]);
				}
			}
		}
		$this->_appendMacroDefs($macro_defs);
	}
	
	/**
	 * Clean up temporary PEAR files which just eat space
	 *
	 * @param  bool $standalone_pear Whether or not the PEAR installation is managed by WADF
	 * @return void
	 */
	protected function _cleanupPEAR($standalone_pear=false)
	{
		$pearcmd = $this->_getPEARCmd();
		exec("$pearcmd config-get download_dir", $output, $ret);
		if ($ret == 0) {
			$download_dir = $output[0];
			$this->_debugOutput("Cleaning up PEAR temporary download files from $download_dir...", self::DEBUG_INFORMATION);
			$this->_rmDir($download_dir);
		}
		
		// Remove the PEAR package documentation if this is a standalone 
		// installation and the dep_pear_deploy_docs option is off 
		if ($standalone_pear) {
			$install_docs = $this->resolveMacro('dep_pear_deploy_docs');
			if ($install_docs == 0) {
				unset($output);
				exec("$pearcmd config-get doc_dir", $output, $ret);
				if ($ret == 0) {
					$doc_dir = $output[0];
					$this->_debugOutput("Cleaning up PEAR document directory $doc_dir...", self::DEBUG_INFORMATION);
					$this->_rmDir($doc_dir);
				}
			}
		}
		
		// Remove the PEAR cache if relevant
		$clear_cache = $this->resolveMacro('dep_pear_clear_cache');
		if ($clear_cache != '@dep_pear_clear_cache@' && $clear_cache) {
			$this->_debugOutput("Clearing PEAR cache...", self::DEBUG_INFORMATION);
			exec("$pearcmd clear-cache");
		}
		
		// Log out of the PEAR base channel if relevant
		$logout_channel = $this->resolveMacro('dep_pear_base_channel_logout_after_deploy');
		if ($logout_channel == '1') {
			$base_channel = $this->resolveMacro('dep_pear_base_channel');
			if (!empty($base_channel)) {
				if (preg_match('/^(.+):(.+)@(.+)$/',$base_channel, $matches)) {
					$username = $matches[1];
					$channel = $matches[3];
				} else {
					$channel = $base_channel;
				}
				$this->_debugOutput("Logging out of PEAR channel $channel...", self::DEBUG_INFORMATION);
				$pearcmd = $this->_getPEARCmd();
				// The below is extremely clumsy due to PEAR bug #16387
				exec("$pearcmd config-get default_channel", $out, $ret);
				$default_channel = $out[0];
				$this->_runPEAR("-d default_channel=$channel logout");
				$this->_runPEAR("config-set default_channel $default_channel", false);
			}
		} 
	}
	
	/**
	 * Remove a directory. Recurses to remove all files in the given directory,
	 * as well as the directory itself
	 * 
	 * @param string $dir Path to directory, without trailing slash
	 */
	protected function _rmDir($dir)
	{
		if (file_exists($dir) && is_dir($dir)) {
			if (!System::rm(array('-r', $dir))) {
				self::_debugOutput("Could not fully delete $dir", self::DEBUG_WARNING);
			}
		}
	}
	
	public function processTemplatesInDir($dir)
	{
		$files = $this->listTemplateFiles($dir);
		$files_filtered = self::filterTemplateFileListAgainstExcluded($dir, $files);
		
		foreach ($files_filtered as $file) {
			// Process template file
			$content = file_get_contents($file);
			$content = $this->resolveString($content, null, "file:$file");
			$output_file = preg_replace('/^(.+)\.template$/','\1',$file);
			$source_permissions = fileperms($file);
			if (file_exists($output_file)) {
				// make sure it's writeable
				chmod($output_file, 0600);
			}
			$fout_fh = fopen($output_file, 'w+');
			fputs($fout_fh, $content);
			fclose($fout_fh);
			chmod($output_file, $source_permissions);
			$writeable = $this->resolveMacro('generated_files_writeable');
			if ($writeable == false) {
				$non_writeable_permissions = $source_permissions & octdec('100555');
				chmod($output_file, $non_writeable_permissions);
			}
		}
	}
	
	/**
	 * Take a list of template files and filter them against the template_exclude_paths option
	 * 
	 * @param array $dir  Base directory from which list of template files comes
	 * @param array $files Array of filenames
	 * @return array Array of filenames, containing only those NOT excluded by template_exclude_paths
	 */
	public function filterTemplateFileListAgainstExcluded($dir, $files)
	{
		$exclude_paths_macro = $this->resolveMacro('template_exclude_paths');
		$exclude_paths = array();
		if (!empty($exclude_paths_macro) && !$exclude_paths_macro != '@template_exclude_paths@') {
			$exclude_paths_tmp = explode(',', $exclude_paths_macro);
			foreach ($exclude_paths_tmp as $path) {
				$exclude_paths[] = trim($path);
			}
			unset($exclude_paths_tmp);
		}
		$files_filtered = array();
		foreach ($files as $file) {
			$files_filtered[$file] = $file;
			// Check if file is excluded
			$file_relative = substr($file, strlen($dir)+1);
			foreach ($exclude_paths as $exclude_path) {
				$test_file_path = substr($file_relative, 0, strlen($exclude_path));
				if ($test_file_path == $exclude_path) {
					// File matches exclude pattern; skip
					unset($files_filtered[$file]);
					$this->_debugOutput("Excluding file $file from templating (matches excluded path '$exclude_path')", self::DEBUG_INFORMATION);
					continue 2;
				}
			}
		}
		return $files_filtered;
	}
	
	public function resolveMacrosWithFallbacksInDir($dir, $deploy_database=false)
	{
		$macros_in_dir = $this->extractMacrosFromDir($dir, $deploy_database);
		$db_nums = array();
		foreach ($macros_in_dir as $macro) {
			// TODO: run through the list of $this->_macro_fallbacks instead of hardcoding
			if (preg_match('/^(db|vhost)(\d+)_/', $macro, $matches)) {
				// Force resolution of macro
				$this->resolveMacro($macro);
			}
		}
	}
		
	/**
	 * Go through all template files in a directory and find out which macros 
	 * are used in them
	 * 
	 * @param  string $dir  Directory to search
	 * @param  bool   $deploy_database Whether to assume a database is currently being deployed or not (if so, adds dbX_deploy_* macros to output)
	 * @return array        Flat list of unique macros
	 */
	public function extractMacrosFromDir($dir, $deploy_database=false)
	{
		$macros = array();
		$files = $this->listTemplateFiles($dir);
		foreach ($files as $file) {
			$this->_debugOutput("extracting macros from file '$file'", self::DEBUG_VERBOSE);
			$contents = file_get_contents($file);
			$new_macros = $this->extractMacrosFromString($contents);
			$this->_debugOutput("macros in file '$file' = ".implode(',',$new_macros), self::DEBUG_VERBOSE);
			$macros = array_merge($macros,$new_macros);
			
			
			// Add macros which are implicit
			foreach ($new_macros as $macro) {
				// TODO: run through the list of $this->_macro_fallbacks instead of hardcoding
				if (preg_match('/^db(\d+)_/', $macro, $matches)) {
					$num = $matches[1];
					$macros[] = "db${num}_deploy";
					$macros[] = "db${num}_deploy_user";
					if ($deploy_database) {
						$macros[] = "db${num}_deploy_pass";
						$macros[] = "db{$num}_user_host";
					}
				}
			}
			
			unset($new_macros);
		}
		
		return array_unique($macros);
	}
	
	public static function listAllFiles($dir)
	{
		$files = array();
		if (!file_exists($dir) || !is_dir($dir)) return $files;
		$dh = dir($dir);
		while ($file = $dh->read()) {
			if (in_array($file, self::getFilesToIgnore())) continue;
			$file = $dir . DIRECTORY_SEPARATOR . $file; // make into an absolute path
			if (is_file($file)) {
				$files[] = $file;
			} else if (is_dir($file)) {
				$files = array_merge($files, self::listAllFiles($file));
			}
		}
		return $files;
	}
	
	public static function listTemplateFiles($dir)
	{
		$template_files = array();
		if (!file_exists($dir) || !is_dir($dir)) return $template_files;
		$dh = dir($dir);
		while ($file = $dh->read()) {
			if (in_array($file, self::getFilesToIgnore())) continue;
			$file = $dir . DIRECTORY_SEPARATOR . $file; // make into an absolute path
			if (substr($file, -9) == '.template' && is_file($file)) {
				$template_files[] = $file;
			} else if (is_dir($file)) {
				$template_files = array_merge($template_files, self::listTemplateFiles($file));
			}
		}
		return $template_files;
	}
	
	/**
	 * Clean files generated from templates.
	 *
	 * @param string $dir  The base directory from which to clean.
	 * @return array  List of files removed, with TRUE for removed correctly and FALSE for not
	 */ 
	public static function cleanGeneratedFiles($dir)
	{
		$template_files = self::listTemplateFiles($dir);
		$files_removed = array();
		foreach ($template_files as $file) {
			$file = substr($file, 0, -9); // strip .template extension
			if (file_exists($file)) {
				$files_removed[$file] = @unlink($file);
			}
		}
		return $files_removed;
	}
	
	// takes a string like '@underscore:foo@@bar@' and returns array('underscore:foo','bar');
	// if keep_modifiers = false then returns array('foo','bar');
	public function extractMacrosFromString($string, $keep_modifiers = true)
	{
		preg_match_all('/@(([a-z]+:)?-?([a-z0-9_]{3,50}))@/', $string, $matches, PREG_PATTERN_ORDER);
		
		$macros = array_unique($matches[1]);
		if ($keep_modifiers == false) {
			foreach ($macros as $macro) {
				$newmacros[] = $this->getBaseMacroName($macro);
			}
			$macros = $newmacros;
		}
		return $macros;
	}
	
	public function runKickstart($database_was_redeployed=1)
	{
		$kickstart_script = $this->resolveMacro('kickstart_script');
		if (!empty($kickstart_script)) {
			if (file_exists($kickstart_script)) {
				$this->_debugOutput("Running kickstart script $kickstart_script...", self::DEBUG_GENERAL);
				
				// This should not really be necessary as the script should be
				// written to run from anywhere. But just to make things nicer,
				// change directory to the directory where the kickstart script is
				$cwd = getcwd();
				$new_wd = dirname($kickstart_script);
				chdir($new_wd);
				
				// Set environment variable to show whether database was redeployed
				if ($database_was_redeployed) {
					putenv('DEPLOY_INITDB=1');
				} else {
					putenv('DEPLOY_INITDB=0');
				}
				// Set environment variable to show verbosity level
				if ($this->_debug >= self::DEBUG_INFORMATION) {
					putenv('DEPLOY_VERBOSITY=1');
				} else {
					putenv('DEPLOY_VERBOSITY=0');
				}
				
				$this->_debugOutput('----------- OUTPUT BELOW IS FROM KICKSTART SCRIPT, NOT WADF -----------', self::DEBUG_GENERAL);
				
				// Run the script
				passthru($kickstart_script);
				
				$this->_debugOutput('------------------- END OF KICKSTART SCRIPT OUTPUT --------------------', self::DEBUG_GENERAL);

				// Change back to the old working directory
				chdir($cwd);
			} else {
				$this->_debugOutput("\nKickstart script $kickstart_script does not exist; nothing to do.", self::DEBUG_INFORMATION);
			}
		}
	}

	// $options is an array of macros passed as overrides from direct user interface (e.g. command line)
	public function processConfigs($options=null)
	{
		/* get:
		- globals
		- per-user
		- profile
		- app-specific (local_config)
		- instance-specific (.wadf-instance)
		- command line/UI
		in that order. lump all together linearly in above order
		then process line-by-line, with later ones taking precedence.
		
		There are two special options which will influence the reading of configs:
		 - profile
		 - deploy_path
		so these are handled specially in various places
		*/
		
		if ($options === null) $options = array();
		
		// Before we do anything else, we need to check if "profile" is set on the
		// command line as this will affect which profile is used from the WADF
		// configs
		$profile_override = null;
		if (isset($options['profile'])) {
			$profile_override = $options['profile'];
		}
		
		// Read global config
		$this->readConfigFile($this->_options['master_config'], $profile_override);
		
		// Read per-user config
		$home = getenv('HOME');
		if (!empty($home)) {
			$wadfrc = $home . '/.wadf/config';
			if (file_exists($wadfrc)) {
				$this->readConfigFile($wadfrc, $profile_override);
			}
		}
		
		// Read site-specific file
		// This is likely to reference deploy_path, so check that hasn't been
		// overridden - bit of a hack
		if (isset($options['deploy_path'])) {
			$this->_appendMacroDefs(array('deploy_path' => $options['deploy_path']));
		}
		// Further hack - in the default config, deploy_path depends on instance name (bug #463)
		if (isset($options['instance'])) {
			$this->_appendMacroDefs(array('instance' => $options['instance']));
		}
		$this->processLocalConfig($profile_override);
		
		// Read site instance file
		$deploy_path = $this->resolveMacro('deploy_path');
		if (file_exists("$deploy_path/.wadf-instance")) {
			$new_macros = $this->readInstanceFile("$deploy_path/.wadf-instance");
			if (count($new_macros) > 0) {
				$this->_appendMacroDefs($new_macros);
			}
		}
		
		// Append options from command line
		$this->_appendMacroDefs($options);
	}
	
	/**
	 * Read a WADF instance file
	 *
	 * @param string $file  Filename of instance file
	 * @return array        Associative array of macros from instance file. Appref will be in one called "appref"
	 */
	public static function readInstanceFile($file)
	{
		$macros = array();
		$instance_file = file($file);
		$macros['appref'] = trim($instance_file[0]);	// first line is the appref
		unset($instance_file[0]);
		foreach ($instance_file as $line) {
			if (preg_match("/^(\S+)\s*=\s*(.*)$/", $line, $matches)) {
				$macros[$matches[1]] = trim($matches[2]);
			}
		}
		return $macros;
	}
	
	/**
	 * Process the config file from the local_config variable, if it exists
	 *
	 * @param string $profile_override  Override profile name to use (from cmdline etc.)
	 * @return void
	 */
	public function processLocalConfig($profile_override=null)
	{
		$app_conf_file = $this->resolveMacro('local_config');
		if ($app_conf_file == '@local_config@') {
			$this->_debugOutput("No 'local_config' option set; not looking for app config file...", self::DEBUG_INFORMATION);
			return;
		}
		$this->_debugOutput("Looking for $app_conf_file...", self::DEBUG_INFORMATION);
		if (file_exists($app_conf_file)) {
			$this->_debugOutput("\tFound. Loading configs from $app_conf_file", self::DEBUG_INFORMATION);
			$this->readConfigFile($app_conf_file, $profile_override);
		} else {
			$this->_debugOutput("\tNot found", self::DEBUG_INFORMATION);
		}
	}
	
	/**
	 * Check through the entire list of options for any which require user input
	 * 
	 * @return bool  True if everything went OK, False if there was a problem and deployment should be terminated
	 */
	public function checkOptionsRequiringInput()
	{
		$input_values = array();
		
		// "Fallback macros" are the ones (e.g. vhost_name) used as fallbacks
		// if the more specific ones (e.g. vhost1_name) don't exist. We don't
		// need to request user entry of values for these, because if they are
		// needed then they will be requested for the more specific version on
		// a case-by-case basis
		$fallback_macros = array_values($this->_macro_fallbacks);
		foreach (array_keys($this->_macro_values) as $key) {
			if (!in_array($key, $fallback_macros)) {
				$value = $this->resolveMacro($key);
				
				if (substr($value, 0, 2) == '**') {
					$this->_debugOutput("The macro '$key' is set to '**', indicating that it needs to be configured.", self::DEBUG_ERROR);
					$notes = trim(substr($value, 2));
					if (!empty($notes)) {
						$this->_debugOutput("Notes: $notes", self::DEBUG_ERROR);
					}
					return false;
				}
				if (substr($value, 0, 2) == '%%') {
					$notes = trim(substr($value, 2));
					$extra_info = '';
					$key_name = "the value for the config option '$key'"; // what we will show the user
					if (preg_match('/^db(\d+)_deploy_pass$/', $key, $matches)) {
						$dbnum = $matches[1];
						$db_name = $this->resolveMacro("db${dbnum}_name");
						$db_host = $this->resolveMacro("db${dbnum}_host");
						$deploy_user = $this->resolveMacro("db${dbnum}_deploy_user");
						$key_name = 'the database password (for deployment)';
						$extra_info = "to deploy/undeploy database $db_name on $db_host, as user '$deploy_user'";
					} else if (preg_match('/^db(\d+)_pass$/', $key, $matches)) {
						$dbnum = $matches[1];
						$db_name = $this->resolveMacro("db${dbnum}_name");
						$db_host = $this->resolveMacro("db${dbnum}_host");
						$db_user = $this->resolveMacro("db${dbnum}_user");
						$key_name = 'the database password';
						$extra_info = "for database $db_name on $db_host, connecting as user '$db_user'";
					}
					
					$msg = "Please enter $key_name";
					if ($extra_info) $msg .= " ($extra_info)";
					if (!empty($notes)) {
						$msg .= "\nNotes: $notes";
					} else {
						$msg .= ": ";
					}
					$this->consoleOutput($msg);
					if (substr($key, -5) == '_pass' || stristr($key, 'password')) {
						// Looks like we're asking for a password; attempt to suppress console echo
						@exec('stty -echo 2>&1');
					}
					$input = trim(fgets(STDIN));
					@exec('stty echo 2>&1'); // Re-enable console echo
					$input_values[$key] = $input;
					
					// We do this NOW so that the values can cascade through
					$this->_appendMacroDefs(array($key => $input));
				}
			}
		}
		
		if (count($input_values) > 0) {
			$deploy_path = $this->resolveMacro('deploy_path');
			$instance_file = $deploy_path . '/.wadf-instance';
			$fp = fopen($instance_file, 'a');
			if (!is_resource($fp)) {
				throw new Exception("Could not write to local instance file $instance_file");
			}
			foreach ($input_values as $key => $value) {
				fputs($fp, "\n$key = $value");
			}
			fclose($fp);
		}
		return true;
	}
	
	/**
	 * Go through vhost_config_path and analyse each vhost config, returning
	 * a set of information. Currently only works for Apache-format vhosts.
	 * @param string Vhost path (should be vhost_config_path, but that is not read automatically so this function can be called statically)
	 * @return array Array of vhosts, in the format:
	 *               ('appref' => array(), 'appref2' => array())
	 *               where the array for each appref has the following params:
	 *               working_dir
	 *               deploy_version
	 *               vhosts (array of hostname, interface, description[optional])
	 */
	public function getDeployedVhosts($vhost_dir)
	{
		$dir = dir($vhost_dir);
		$vhosts = array();
		while (false !== ($entry = $dir->read())) {
			if (substr($entry, -5) == '.conf') {
				$vhost_details = array(
					'working_dir' => 'unknown',
					'deploy_version' => 'unknown',
					'vhosts' => array()
				);
			
				$string = @file_get_contents("$vhost_dir/$entry");
				if (!empty($string)) {
					$appname = str_replace('.conf','', $entry);
					
					// The dir that the app was deployed from
					if (preg_match('/^\s*#\s*wadf-working-copy:(.+)/m', $string, $matches)) {
						$vhost_details['working_dir'] = trim($matches[1]);
					}
					
					// Version control version
					if (preg_match('/^\s*#\s*wadf-deploy-version:(.+)/m', $string, $matches)) {
						$vhost_details['deploy_version'] = trim($matches[1]);
					}
					
					if (preg_match_all("/^<VirtualHost (.+)>(.+ServerName (.+)\s.+)<\/VirtualHost>/Uism", $string, $matches, PREG_SET_ORDER)) {
						foreach ($matches as $match_set) {
						
							$specific_vhost_details = array(
								'hostname' => $match_set[3],
								'interface' => $match_set[1]
							);
							// Vhost description
							if (preg_match('/^\s*#\s*desc:(.+)/m', $match_set[2], $matches_desc)) {
								$specific_vhost_details['description'] = trim($matches_desc[1]);
							}
							
							$vhost_details['vhosts'][] = $specific_vhost_details;
						}
						$vhosts[$appname] = $vhost_details;
					}
				}
			}
		}
		ksort($vhosts);
		return $vhosts;
	}

	public function runCmd($cmd, $do_output=true)
	{
		$this->_debugOutput("Running $cmd", self::DEBUG_INFORMATION);
		exec($cmd, $output, $ret);
		if ($do_output) {
			$this->_debugOutput(implode("\n",$output), self::DEBUG_VERBOSE);
		}
		if ($ret != 0) {
			throw new Exception("Error running command '$cmd'");
		}
		return $output;
	}
	
	protected function _appendMacroDefsTop($array)
	{
		$copy = $this->_macro_defs;
		$this->_macro_defs = array();
		foreach ($array as $key => $value) {
			$this->_macro_defs[] = new Tools_WADF_MacroDef($key,$value);
		}
		foreach ($copy as $value) {
			$this->_macro_defs[] = $value;
		}
		$this->_rebuildMacroValues();
	}
	
	protected function _appendMacroDefs($array)
	{
		if (!is_array($array)) return;
		
		foreach ($array as $key => $value) {
			$this->_macro_defs[] = new Tools_WADF_MacroDef($key,$value);
		}
		$this->_rebuildMacroValues();
	}
	
	protected function _rebuildMacroValues()
	{
		if (count($this->_macro_values) > 0) {
			$this->resolveAllMacros();
		}
	}
	
	public function readConfigFile($file, $override_profile=null)
	{
		$config = parse_ini_file($file, TRUE);
		if (isset($config['globals'])) {
			$this->_debugOutput("Setting global configuration options from file $file", self::DEBUG_INFORMATION);
			$this->_appendMacroDefs($config['globals']);
		}
		
		// Update macros, other low-level functions might want to check local_config etc.
		$this->resolveAllMacros();
		
		// We need to do this to ensure "profile" is set.
		// Possible optimisation: treat "profile" specially, as an unexpandable
		// macro, and create a method something like "getLatestMacro" which gets
		// the most recent definition of a given "macro", and use the literal
		// contents of that.
		if ($override_profile) {
			$profile = $override_profile;
			$this->_debugOutput("File $file: Using profile '$profile' from user interface/command line", self::DEBUG_INFORMATION);
		} else {
			$profile = $this->resolveMacro('profile');
			if ($profile == '@profile@') {
				$this->_debugOutput("Cannot resolve global profile when processing $file", self::DEBUG_INFORMATION);
				$profile = null;
			}
		}
		
		// if no profile name passed, see if one was defined in the 'globals'
		// section of the config for the file that we're currently reading
		if ($profile == null) {
			if (isset($config['globals']['profile'])) {
				$profile = $config['globals']['profile'];
				$this->_debugOutput("File $file: Using profile='$profile' from 'globals' section of config", self::DEBUG_INFORMATION);//TODO
			} else {
				$this->_debugOutput("File $file: ignoring as no global profile set", self::DEBUG_INFORMATION);
			}
		}
		
		if (isset($config[$profile])) {
			$this->_debugOutput("File $file: using profile section '$profile'", self::DEBUG_INFORMATION);
			$this->_appendMacroDefs($config[$profile]);
		} else {
			$this->_debugOutput("File $file: no profile section '$profile' exists", self::DEBUG_INFORMATION);
		}
	}
	
	public function getFallbackMacros()
	{
		return array_values($this->_macro_fallbacks);
	}
	
	public function setDebugLevel($level)
	{
		$this->_debug = $level;
	}
	
	public function _debugOutput($string, $level=self::DEBUG_GENERAL)
	{
		if ($level < $this->_debug) {
			$this->consoleOutput($string);
		}
	}
	
	// $string is a string which may contain all sorts of stuff including macros
	public function resolveString ($string, $macro_values_stack = null, $context=null) {
		if ($macro_values_stack === null) {
			$this->_debugOutput("using default stack to resolve string", self::DEBUG_VERBOSE);
			$macro_values_stack = &$this->_macro_values;
		}
		
		// Don't let %% propagate up the stack; leave other macros unresolved
		// on the basis that they will be resolved later
		$new_string = $this->_resolveString($string, $macro_values_stack, $context);
		if (substr($new_string, 0, 2) == '%%') {
			return $string;
		}
		return $new_string;
	}
	
	// $context is an optional context for recording errors
	protected function _resolveString($string, &$macro_values_stack, $context=null)
	{
		$this->_debugOutput("resolving string '$string'", self::DEBUG_VERBOSE);

		// take a copy for debugging
		$origstring = $string;
		
		$oldstring = '';
		while ($string != $oldstring) {
			$oldstring = $string;
			$macros = $this->extractMacrosFromString($string);
			$this->_debugOutput("macros in string '$string' = ".implode(',',$macros), self::DEBUG_VERBOSE);
			foreach ($macros as $macro) {
				$macro_contents = $this->_resolveMacro($macro, $macro_values_stack);
				if ("@$macro@" == $macro_contents) {
					// Didn't manage to resolve macro
					$hash = md5($context . $macro); // prevent duplicates
					$this->_unresolved_macros[$hash] = array('macro' => $macro, 'context' => $context);
				} else {
					$string = str_replace("@$macro@", $macro_contents, $string);
				}
			}
		}
		$this->_debugOutput("resolved string '$origstring' to '$string'", self::DEBUG_VERBOSE);
		return $string;
	}
	
	public function resolveAllMacros()
	{
		$changed = true;
		
		foreach ($this->_macro_defs as $macro) {
			$value = $macro->value;
			if (isset($this->_macro_values[$macro->name])) { // if macro already has a value, check for recursion
				$macros_within_macro = $this->extractMacrosFromString($value);
				foreach ($macros_within_macro as $submacro) {
					if ($submacro == $macro->name) { // recursive macro
						$value = str_replace("@$submacro@", $this->_macro_values[$macro->name], $value);
					}
				}
			}
			$this->_macro_values[$macro->name] = $value;
		}
		
		while ($changed) {
			$this->_debugOutput("resolveAllMacros: iterating", self::DEBUG_VERBOSE);
			$changed = false;
			foreach ($this->_macro_values as $macro_name => $macro_value) {
				$this->_debugOutput("resolveAllMacros: resolving macro '$macro->name' ('$macro->value')", self::DEBUG_VERBOSE);
				$this->_macro_values[$macro_name] = $this->resolveString($macro_value);
			
				// If macro value has changed, we will need to iterate again
				if ($this->_macro_values[$macro_name] != $macro_value) {
					$changed = true;
				}
			
				$this->_debugOutput("resolveAllMacros: resolved $macro->name to '".$this->_macro_values[$macro->name]."'", self::DEBUG_VERBOSE);
			}
		}
		return $this->_macro_values;
	}
	
	// $macro is a SINGLE macro (not a string containing macros) something like "foo" or "hyphen:foo"
	// note "foo" not "@foo@"
	// NB no recursive replace is required here because by this stage ALL MACROS should already have been expanded
	
	public function resolveMacro ($macro, $macro_values_stack = null) {
		if ($macro_values_stack === null) {
			$macro_values_stack = &$this->_macro_values;
		}
		return $this->_resolveMacro($macro, $macro_values_stack);
	}
	
	protected function _resolveMacro ($macro, &$macro_values_stack) {
		$this->_debugOutput("resolve single macro '$macro'", self::DEBUG_VERBOSE);
	
		// make a copy
		$origmacro = $macro;
		
		$output = null;
	
		# separate the modifier if macro is "foo:bar" 
		if (preg_match('/^([a-z]+):(.+)$/',$macro,$matches)) {
			$modifier = $matches[1];
			$macro = $matches[2];
			
			if ($modifier == 'rand') {
				if (preg_match('/^(\-?\d+)_(\-?\d+)$/', $macro, $tmp)) {
					return rand($tmp[1], $tmp[2]);
				} else {
					throw new Exception("Bad format for random number macro: $macro");
				}
			}
		}
		
		if (isset($macro_values_stack[$macro])) {
			$this->_debugOutput("found macro '$macro'", self::DEBUG_VERBOSE);
			$output = $macro_values_stack[$macro];
		} else {
			$fallback_match = false;
			foreach ($this->_macro_fallbacks as $fallback_regex => $fallback_macro) {
				if (!$fallback_match) {
					$this->_debugOutput("trying fallback '$fallback_regex'", self::DEBUG_VERBOSE);
					if (preg_match("/^$fallback_regex\$/", $macro, $matches)) {
						$enum_value = $matches[1];
						$this->_debugOutput("matched fallback '$fallback_regex' - numbered '$enum_value'", self::DEBUG_VERBOSE);
						if ($enum_value == 1) $enum_value = '';
						
						// Now fill in the special enumerated macro with the relevant number
						if (substr($fallback_macro,0,2) == 'db') {
							$special_macro = 'db_number';
						} else {
							$special_macro = 'vhost_number';
						}
						
						// Fork off a new macro values stack
						
						$this->_debugOutput("Forking new macro values stack with $special_macro = $enum_value", self::DEBUG_VERBOSE);
						$macro_values_special = $macro_values_stack;
						
						$macro_values_special[$special_macro] = $enum_value;
						
						$output = $this->_resolveString("@$fallback_macro@", $macro_values_special);
						$fallback_match = true;
					}
				}
			}
		}
		
		$output_unprocessed = $output;
		// if output is still null, output is just macro name
		if ($output === null) {
			$output = "@$origmacro@";
			$output_unprocessed = $output;
		} else {
			// post-process output with modifier if relevant
			if (isset($modifier)) {
				switch($modifier) {
					case 'hyphen':
						$sub = '-';
						break;
					case 'slash':
						$sub = '/';
						break;
					case 'underscore':
						$sub = '_';
						break;
					default:
						throw new Exception("unknown modifier $modifier");
				}
				if (isset($sub)) {
					$output = str_replace($this->_resolveMacro('appref_separator', $macro_values_stack), $sub, $output);
				}
			}
		}
		
		$this->_debugOutput("resolved single macro '$origmacro' to '$output'", self::DEBUG_VERBOSE);
		
		$macro_values_stack[$macro] = $output_unprocessed;
		return $output;
	}
	
	
	/**
	 * Get macro name without modifiers
	 *
	 * @param string $macro_name Macro name, possibly including modifiers
	 */	
	public function getBaseMacroName($macro_name)
	{
		preg_match('/([a-z]+):(.+)/', $macro_name, $matches);
		return $matches[2];
	}
	
	public static function getAboutText()
	{
		return "WADF version " . Tools_WADF::SWVERSION . " copyright 2006-2010 Tim Jackson (tim@timj.co.uk)\n\n".
		"This program is free software: you can redistribute it and/or modify\n".
		"it under the terms of version 3 of the GNU General Public License as\n".
		"published by the Free Software Foundation.\n\n".
		"This program is distributed in the hope that it will be useful,\n".
		"but WITHOUT ANY WARRANTY; without even the implied warranty of\n".
		"MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the\n".
		"GNU General Public License for more details.\n\n".
		"You should have received a copy of the GNU General Public License\n".
		"along with this program.  If not, see <http://www.gnu.org/licenses/>.\n";
	}
	
	// Used by the command line programs
	public static function cmdlineHandleException(Exception $e)
	{
		self::consoleOutput($e->getMessage());
		exit(1);
	}
	
	public static function consoleOutput($msg, $trailing_newline=true)
	{
		$console_width = 80;
		@exec('stty size 2>&1', $out, $ret);
		if ($ret == 0) {
			list($rows, $console_width) = explode(' ', $out[0]);
		}
		print wordwrap(rtrim($msg), $console_width);
		if ($trailing_newline) print "\n";
	}
}

class Tools_WADF_MacroDef
{
	public $name;
	public $value;
	
	public function __construct($name, $value)
	{
		$this->name = $name;
		$this->value = $value;
	}
}

class Tools_WADF_VCInfo
{
	public $url;
	public $rev_raw;
	public $rev_type;
	public $rev_translated;
	public $modifications; //bool
}


abstract class Tools_WADF_Dependency
{
	/**
	 * $type is the only common attribute for dependencies. Will be
	 * Tools_WADF::DEPENDENCY_TYPE_PEAR or the class name of the Version
	 * Control Driver.
	 */
	public $type;
}

/**
 * PEAR Package dependency. WADF is aware of these directly, whereas other
 * dependencies are defined in the relevant Version Control Driver classes.
 */
class Tools_WADF_Dependency_PEAR extends Tools_WADF_Dependency
{
	public $type = Tools_WADF::DEPENDENCY_TYPE_PEAR;
	public $name;
	public $version;
}
