<?php

/*
    Web Application Deployment Framework
    (c)2006-2009 Tim Jackson (tim@timj.co.uk)
    
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
		'db(\d+)_pass' => 'db_pass',
		'db(\d+)_schema' => 'db_schema',
		'db(\d+)_deploy' => 'db_deploy',
		'db(\d+)_deploy_user' => 'db_deploy_user',
		'db(\d+)_deploy_pass' => 'db_deploy_pass'
	 );
	 
	/**
	 * Macros that were passed from the command line. Only used for writing
	 * instance files.
	 */
	private $_cmdline_macros = array();
	
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
	}
	
	protected function _setInternalMacros()
	{
		if (isset($_ENV['HOSTNAME'])) {
			$macros['hostname'] = $_ENV['HOSTNAME'];
		} else {
			$macros['hostname'] = gethostbyaddr('127.0.0.1'); // FIXME this can't be the right way of doing it
		}
		$macros['cwd'] = getcwd();
		if (function_exists('posix_getuid')) {
			$details = posix_getpwuid(posix_getuid());
			$macros['user'] = $details['name'];
		} else {
			$macros['user'] = 'UNKNOWN';
		}
		if (isset($_ENV['HOME'])) {
			$macros['home'] = $_ENV['HOME'];
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
	 * @return void
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
		$this->checkOptionsRequiringInput();
		
		$this->deployDependencies($dir);
		$this->processTemplatesInDir($dir);
		
		// TODO: handled unresolved macros? or just let UI do it?
		
		if ($db_deploy) {
			$this->deployDatabase();
		}
		$this->deployVhost();
		$this->deployDNS();
		$this->deployScheduledJobs();
		$this->runKickstart($db_deploy);
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
			if (!function_exists('mysql_connect')) {
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
				if (!empty($schema)) {
					$schema_path = "$dir/$schema";
					if (file_exists($schema_path)) {
						// FIXME quote strings!
						$db_deploy_user = $this->resolveMacro("db${num}_deploy_user");
						$db_deploy_pass = $this->resolveMacro("db${num}_deploy_pass");
					
						$db = @mysql_connect($host, $db_deploy_user, $db_deploy_pass);
						if (!is_resource($db)) {
							throw new Exception("Could not connect to database (username=$db_deploy_user, password=$db_deploy_pass): ".mysql_error());
						}
					
						if (in_array('create', $deploy_options)) {
							mysql_query("CREATE DATABASE IF NOT EXISTS $name", $db);
						}
						if (in_array('grant', $deploy_options)) {
							mysql_query("GRANT ALL on $name.* to $user IDENTIFIED BY '$pass'", $db);
						}
						if (in_array('schema', $deploy_options)) {
							// Remove existing database tables
							$this->_removeDatabaseTables($name, $db);
						
							// Deploy new schema
							$this->_debugOutput("\tDeploying new schema for database $name as user $db_deploy_user...", self::DEBUG_GENERAL);
							$cmd = "mysql $name -h $host -u $db_deploy_user ".(($db_deploy_pass != null) ? "-p$db_deploy_pass" : '')." < $dir/$schema 2>&1";
						}
						mysql_close($db);
						exec($cmd, $out, $ret);
						if ($ret != 0) {
							throw new Exception('Error when deploying schema: ' . implode("\n", $out));
						}
					} else {
						throw new Exception("Schema file $schema_path not found");
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
				
				$db = @mysql_connect($host, $db_deploy_user, $db_deploy_pass);
				if (!is_resource($db)) {
					throw new Exception("Could not connect to database (username=$db_deploy_user, password=$db_deploy_pass): " . mysql_error());
				}
				
				if (in_array('grant', $deploy_options)) {
					mysql_query("REVOKE ALL ON $name.*", $db);
				}
				if (in_array('create', $deploy_options)) {
					$this->_debugOutput("Dropping database $name...", self::DEBUG_GENERAL);
					$res = mysql_query("DROP DATABASE $name", $db);
					if ($res === false) {
						$this->_debugOutput("Could not drop database $name", self::DEBUG_ERROR);
					}
				} else if (in_array('schema', $deploy_options)) {
					$this->_removeDatabaseTables($name, $db);
				}
				
				mysql_close($db);
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
		$res = mysql_select_db($dbname, $dbconn);
		if ($res === false) {
			throw new Exception("Could not select database $dbname: " . mysql_error($dbconn));
		}
		$res = mysql_query('SHOW TABLES', $dbconn);
		if ($res === false) {
			throw new Exception("Could not discover tables in database $dbname - SHOW TABLES failed");
		}
		mysql_query('SET FOREIGN_KEY_CHECKS=0');
		while ($table = mysql_fetch_row($res)) {
			$this->_debugOutput("\t\tDropping table " . $table[0], self::DEBUG_INFORMATION); 
			$res2 = mysql_query("DROP TABLE `" . $table[0] . "`", $dbconn);
			if ($res2 === false) {
				throw new Exception("Error dropping table $dbname.$table[0] :" . mysql_error($dbconn));
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
			if (!in_array($host, $existing_hostnames)) {
				array_splice($file_contents, $localhost_line_num + 1, 0, "$deploy_ip\t$base_host\t$host\n");
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
		$dest_file = $this->resolveMacro('vhost_config_path').'/'.$this->resolveMacro('instance').'.conf';
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
			$this->_debugOutput("Running post-deploy script $cmd...", self::DEBUG_GENERAL);
			passthru($cmd);
		}
	}
	
	public function cleanupFiles()
	{
		$files = $this->resolveMacro('post_deploy_cleanup_files');
		if (!empty($files) && $files != '@post_deploy_cleanup_files@') {
			$deploy_path = $this->resolveMacro('deploy_path');
			$this->_debugOutput("Cleaning up special files...", self::DEBUG_INFORMATION);
			foreach(explode(' ', $files) as $file) {
				$file = trim($file);
				chdir($deploy_path);
				if (file_exists($file) && file_exists("$file.template")) {
					$this->_debugOutput("  Removing $file", self::DEBUG_INFORMATION);
					unlink($file);
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
			$directives_out = array();
			foreach ($php_ini_directives as $directive) {
				$directive = trim($directive);
				if (empty($directive)) continue;
				if (preg_match('/^\s*;(.+)$/', $directive, $matches)) {
					// Comment; convert php.ini-style semicolons to hash signs
					$directives_out[] = '#' . $matches[1];
				} else if (preg_match('/^([a-z0-9_\.]+)\s*=\s*(.+)$/', $directive, $matches)) {
					$directive_name = trim($matches[1]);
					$directive_value = trim($matches[2]);
					
					// See if it looks like a flag or a value
					if (in_array(strtolower($directive_value), array('0','1','on','off'))) {
						$directives_out[] = "php_admin_flag $directive_name $directive_value";
					} else {
						$directives_out[] = "php_admin_value $directive_name $directive_value";
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
				$this->_debugOutput("Deploying PHP config file $php_ini...", self::DEBUG_GENERAL);
				
				$source = file_get_contents($php_ini_source);
				$php_ini_local = trim($this->resolveMacro('php_config_location_extra'));
				if ($php_ini_local != '@php_config_location_extra@' && !empty($php_ini_local)) {
					$extras = file_get_contents($php_ini_local);
					$source .= "\n; PHP directives processed from $php_ini_local\n" . $extras;
				}

				file_put_contents($php_ini_dest, $source);
			}
		} else {
			throw new Exception("Unknown PHP type '$php_type'");
		}
		return true;
	}
	
	// FIXME abstract
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
		
		switch ($revtype) {
			case Tools_WADF::VCREFTYPE_TRUNK:
				$svn_path = 'trunk';
				break;
			case Tools_WADF::VCREFTYPE_BRANCH:
				$svn_path = "branches/$rev_translated";
				break;
			case Tools_WADF::VCREFTYPE_TAG:
				$svn_path = "tags/$rev_translated";
				if ($raw_rev !== null && $raw_rev != 'HEAD') {
					throw new Exception("Don't pass a raw revision when checking out a tag");
				}
				break;
		}
		
		$options = '';
		if ($raw_rev !== null) {
			$options = "-r $raw_rev";
		}
		
		$vc_base = $this->resolveMacro('vc_base');
		
		// See if we already have a checked-out copy
		// We could just do a file_exists() on .wadf-instance, but actually
		// checking for SVN metadata is safer
		$vc_info = $this->readVCInfoFromDir($destdir);
		
		$svn_action = 'checkout';
		if (is_object($vc_info)) {
			$svn_action = 'switch';
		}
		
		if ($svn_action == 'checkout') {
			$this->_rmDir($destdir);
		} else {
			$this->cleanGeneratedFiles($destdir);
		}
		
		$svn_full_path = "$vc_base/$this->appref/$svn_path";
		$this->_debugOutput("Checking out $svn_full_path...", self::DEBUG_GENERAL);
		$cmd = "$options $svn_action $svn_full_path $destdir";
		$this->_runSVN($cmd);
		
		$this->_writeInstanceFile("$destdir/.wadf-instance");
		
		$this->setVCVersionMacro($destdir);
		return true;
	}
	
	/**
	 * Execute an external SVN function
	 *
	 * @param string $params  The parameters to use on the command line
	 * @param bool $do_output  Whether to send SVN command output to _debugOutput()
	 * @return array|bool  Array of output lines from SVN, or FALSE if there was an error
	 */
	protected function _runSVN($params, $do_output=true)
	{
		$cmd = "svn $params";
		$this->_debugOutput("Running $cmd", self::DEBUG_INFORMATION);
		exec($cmd, $output, $ret);
		if ($do_output) {
			$this->_debugOutput(implode("\n",$output), self::DEBUG_VERBOSE);
		}
		if ($ret != 0) {
			throw new Exception("Error running SVN command '$cmd'");
		}
		return $output;
	}
	
	/**
	 * List tags available in version control for the current app
	 *
	 * @return array  List of tags
	 */
	protected function _listTags()
	{
		$vc_base = $this->resolveMacro('vc_base');
		
		$cmd = "list -R $vc_base/$this->appref/tags 2>/dev/null";
		$output = $this->_runSVN($cmd, false);
		if ($output === false) {
			throw new Exception('Error when listing tags - perhaps there aren\'t any?');
		}
		
		$tags = array();
		foreach ($output as $line) {
			if (substr($line, -1) == '/') {
				$tags[] = substr($line, 0, -1);
			}
		}
		return $tags;
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
		$live_vc_info = $this->readVCInfoFromDir($dir);
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
	 * Read version control info from deployed copy
	 * @return Tools_WADF_VCInfo|false
	 */
	public static function readVCInfoFromDir($dir)
	{
		// FIXME: support more than just SVN
		$cmd = "svn info $dir 2>/dev/null";
		exec($cmd, $output);
		
		$info = new Tools_WADF_VCInfo();
		$info->modifications = false;
		
		foreach ($output as $line)
		{
			if (preg_match('/^URL: (.+)$/', $line, $matches)) {
				$info->url = trim($matches[1]);
			} else if (preg_match('/^Last Changed Rev: (\d+)$/', $line, $matches)) {
				$info->rev_raw = trim($matches[1]);
			}
		}
		
		if (empty($info->url) || empty($info->rev_raw)) {
			return false;
		}
		
		if (preg_match('|/trunk$|', $info->url)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_TRUNK;
		} else if (preg_match('|/tags/([^/]+)$|', $info->url, $matches)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_TAG;
			$info->rev_translated = $matches[1];
		} else if (preg_match('|/branches/([^/]+)$|', $info->url, $matches)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_BRANCH;
			$info->rev_translated = $matches[1];
		} else {
			$this->_debugOutput("Could not work out VC rev type from URL ". $info->url, self::DEBUG_WARNING);
			$info->rev_type = Tools_WADF::VCREFTYPE_UNKNOWN;
			$info->rev_translated = 'unknown';
		}
		
		// Check for modifications
		$cmd = "svn st $dir 2>/dev/null";
		unset($output);
		exec($cmd, $output); // Don't use _runSVN as this is a static function
		foreach ($output as $line) {
			if (preg_match('/^\s*M/', $line)) {
				$info->modifications = true;
			}
		}
		
		return $info;
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
						$tags = $this->_listTags();
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
				
				$cmd = "pear ".$this->_getPEARVerbosity()." -c $pear_config_file install --onlyreqdeps pear.php.net/PEAR";
				$this->_runPEAR($cmd);
				$this->_runPEAR("channel-update pear", false, false);
			}
			
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
					$this->_debugOutput("Discovering PEAR channel $channel and installing base packages...", self::DEBUG_GENERAL);
					
					// With PEAR 1.6.x+ the base_channel can include username and
					// password in the format username:password@channel
					$this->_runPEAR("channel-discover $base_channel", false, false);
					$this->_runPEAR("channel-update $channel", false, false);
					if (isset($username)) {
						$this->_debugOutput("Logged into PEAR channel $channel as user $username", self::DEBUG_INFORMATION);
					}
				}
			}
		} else {
			$this->_runPEAR($this->_getPEARCmd() . ' config-get bin_dir', $output);
			$pear_path = $output[0];
			$this->_debugOutput("Using existing PEAR installation in $pear_path", self::DEBUG_INFORMATION);
		}
		
		$this->_installPEARBaseRoles();
		$this->_configurePEARBaseRoles($pear_path, $dir, $standalone_pear);
	
		return $standalone_pear;
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
		
		// If a "dependency tag file" is found, force-install everything in it.
		$dep_tag_file = $this->resolveMacro('dep_tags_file');
		if ($dependency_tags_enabled && !empty($dep_tag_file) && $dep_tag_file != '@dep_tags_file@' && file_exists($dep_tag_file)) {
			$this->_debugOutput("Force-installing tagged versions of dependencies from $dep_tag_file...", self::DEBUG_GENERAL);
			$dep_tags = @file_get_contents($dep_tag_file);
			if ($dep_tags !== false) {
				$deps = $this->processDepTagFile($dep_tags);
				foreach ($deps as $dep) {
					if ($dep->type == Tools_WADF_Dependency::TYPE_PEAR) {
						if (!$pear_setup) {
							$standalone_pear = $this->_setupPEAR($dir);
							$pear_setup = true;
						}
						// we do this on every iteration in case a previous install has
						// changed the list
						$pkgs = $this->_getInstalledPearPackages();
						// dep->name includes the channel name
						$pkg_to_install = $dep->name . '-' . $dep->version;
						if (!in_array($pkg_to_install, $pkgs)) {
							$this->_debugOutput("Installing $pkg_to_install", self::DEBUG_INFORMATION);
							$this->_runPEAR('install -f -n ' . $pkg_to_install);
						} else {
							$this->_debugOutput("Skipping installation of $pkg_to_install; already installed", self::DEBUG_INFORMATION);
						}
					} else if ($dep->type == Tools_WADF_Dependency::TYPE_SVN) {
						// dep->name is the SVN path to check out
						// dep->metadata contains relative path
						$path = $this->resolveMacro('deploy_path') . DIRECTORY_SEPARATOR . $dep->metadata;
						if (is_dir($path)) {
							if (is_dir($path . DIRECTORY_SEPARATOR . '.svn')) {
								exec("svn status $path", $out, $ret);
								if (count($out) == 0) {
									$this->_debugOutput("Deploying SVN dependency $dep->name to existing working copy $path", self::DEBUG_INFORMATION);
									unset($out);
									$this->_runSVN("switch -r $dep->version $dep->name $path");
									if (file_exists("$path/package.xml")) {
										if (!$pear_setup) {
											$standalone_pear = $this->_setupPEAR($dir);
											$pear_setup = true;
										}
										$this->_runPEAR("install -f $path/package.xml");
									}
								} else {
									$this->_debugOutput("Cannot deploy SVN dependency $dep->name; $path is not a clean working copy", self::DEBUG_ERROR);
								}
							} else {
								$this->_debugOutput("Cannot deploy SVN dependency $dep->name; $path already exists but is not a working copy", self::DEBUG_ERROR);
							}
						} else {
							$this->_debugOutput("Deploying SVN dependency $dep->name to $path", self::DEBUG_INFORMATION);
							$this->_runSVN("checkout -r $dep->version $dep->name $path");
							if (file_exists("$path/package.xml")) {
								if (!$pear_setup) {
									$standalone_pear = $this->_setupPEAR($dir);
									$pear_setup = true;
								}
								$this->_runPEAR("install -f $path/package.xml");
							}
						}
					}
				}
			} else {
				$this->_debugOutput("Could not open dependency tag file $dep_tag_file", self::DEBUG_WARNING);
			}
		} else {
			// See if we need PEAR for this deployment
			if (file_exists("$dir/package.xml")) {
				$standalone_pear = $this->_setupPEAR($dir);
		
				$this->_debugOutput("Installing PEAR dependencies...", self::DEBUG_GENERAL);
				$application_dir = '';
				if (!$standalone_pear) {
					// Deploy to the same directory. Is !$standalone_pear really the
					// right criteria to use here?
					$application_dir = '-d application_dir=' . $this->resolveMacro('application_dir');
				}
		
				$this->_runPEAR("$application_dir upgrade --onlyreqdeps -f $dir/package.xml", true, true, true);
			}
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
					$packagelist[] = $package['channel'] . '/' . $package['name'] . '-' . $package['version']['release'];
				} else {
					// old package.xml v1
					$packagelist[] = "pear.php.net/" . $package['package'] . '-' . $package['version'];
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
				// lines are in format "deptype:depname-depversion"
				// e.g. PEAR deps are "PEAR:channel/package_name-x.y.z"
				if (preg_match("/^([a-z0-9]{2,10}):(.+)$/i", $line, $matches)) {
					if (in_array($matches[1], array_keys(Tools_WADF_Dependency::$types))) {
						$dep = new Tools_WADF_Dependency;
						$dep->type = $matches[1];
						switch ($matches[1]) {
							case Tools_WADF_Dependency::TYPE_PEAR:
								list($dep->name, $dep->version) = explode('-', $matches[2]);
								break;
							case Tools_WADF_Dependency::TYPE_SVN:
								$parts = explode(' ', $matches[2]);
								if (count($parts) > 1) {
									if (preg_match('/^(.+)@(\d+)$/', $parts[0], $svnmatches)) {
										$dep->name = $svnmatches[1];
										$dep->version = $svnmatches[2];
										$dep->metadata = $parts[1];
									} else {
										$this->_debugOutput("Unknown SVN dependency syntax in '$parts[0]'", self::DEBUG_WARNING);
									}
								} else {
									$this->_debugOutput("Unknown SVN dependency syntax in '$matches[2]'", self::DEBUG_WARNING);
								}
								break;
						}
						$deps[] = clone $dep;
					} else {
						$this->_debugOutput("Unknown dependency type '$matches[1]' in dep tags file - line was '$line'", self::DEBUG_WARNING);
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
	
	protected function _runPEAR($cmd, $output=true, $fail_on_error=true, $workaround_pear_bugs=false)
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
				} else if ($workaround_pear_bugs && preg_match('#^([^/]+/.+) requires package "([^/]+/.+)" \(version <= (.+)\), downloaded version is (.+)$#', $m[1], $m2)) {
					// channelshortname/App_Name requires package "channelshortname/Other_App" ([version >= a.b.c, ]version <= 1.2.3), downloaded version is 3.4.5
					$wrongly_upgraded_packages[$m2[2]] = array('installed_ver' => $m2[4], 'downgrade_to_ver' => $m2[3], 'dependent_app' => $m2[1]);
				} else {
					$warnings[] = $m[1];
				}
			} else if ($workaround_pear_bugs && preg_match('#^Duplicate package (channel://[^/]+/[^-]+)-(.+) found#', $line, $m)) {
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
				$this->_debugOutput("\t ###### PEAR WARNINGS FOLLOW:", self::DEBUG_WARNING);
				foreach($warnings as $line) {
					$this->_debugOutput("\t\t" . $line, self::DEBUG_WARNING);
				}
				$this->_debugOutput("\t###### END OF PEAR WARNINGS", self::DEBUG_WARNING);
			}
		}
		
		if ($workaround_pear_bugs) {
			$re_run_pear = false;
			// workaround for PEAR bug #13425
			if (count($duplicate_packages) > 0) {
				foreach ($duplicate_packages as $pkg => $vers) {
					$vers = $this->_sortVersions($vers);
					// Let's be conservative and pick the OLDEST version that was listed, especially because of PEAR bug #13427
					// (<max> does not completely exclude dep versions from consideration)
					$ver_picked = $vers[0];
					$this->_debugOutput("\tWARNING: Multiple PEAR dependencies on a specific version of $pkg (" . implode(', ', $vers) . ") - probable cause is PEAR bug #13425", self::DEBUG_WARNING);
					$this->_debugOutput("\tForce-installing $pkg-$ver_picked", self::DEBUG_WARNING);
					$this->_runPEAR("install -f $pkg-$ver_picked", true, true, true);
				}
				$this->_debugOutput("\tRe-running PEAR deployment with newly-installed dependencies...", self::DEBUG_GENERAL);
				$ret = $this->_runPEAR($cmd, $output, $fail_on_error, true);
			}

			// workaround for PEAR bug #13427
			if (count($wrongly_upgraded_packages) > 0) {
				foreach ($wrongly_upgraded_packages as $pkg => $pkginfo) {
					$this->_debugOutput("\tWARNING: $pkg-" . $pkginfo['installed_ver'] . ' was installed, but ' . $pkginfo['dependent_app'] . ' requires version <= ' . $pkginfo['downgrade_to_ver'] . ' (possible cause: PEAR bug #13427 or bad dependency chain)', self::DEBUG_WARNING);
					$this->_debugOutput("\tAttempting to force-install $pkg-" . $pkginfo['downgrade_to_ver'] . ' to compensate', self::DEBUG_WARNING);
					// Set fail to FALSE as we don't really care
					$this->_runPEAR("install -f $pkg-" . $pkginfo['downgrade_to_ver'], true, false, true);
				}
			}
		}
		
		if($fail_on_error && $ret != 0) {
			$this->_debugOutput("\t###### FAILED when installing PEAR dependencies", self::DEBUG_ERROR);
			exit;
		}
		return $ret;
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
	}
	
	/**
	 * Remove a directory. Recurses to remove all files in the given directory,
	 * as well as the directory itself
	 * 
	 * @param string $dir Path to directory, without trailing slash
	 */
	protected static function _rmDir($dir)
	{
		if (is_dir($dir)) {
			foreach(scandir($dir) as $dir_entry) {
				if ($dir_entry == '..' || $dir_entry == '.') continue;
				
				// Make dir_entry an absolute path
				$dir_entry = $dir . DIRECTORY_SEPARATOR . $dir_entry;

				// Delete dir_entry
				if (is_dir($dir_entry) && !is_link($dir_entry)) {
					self::_rmDir($dir_entry);
			    } else {
					unlink($dir_entry);
			    }
			}
			rmdir($dir);
		}
	}
	
	public function processTemplatesInDir($dir)
	{
		$files = $this->listTemplateFiles($dir);
		foreach ($files as $file) {
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
					}
				}
			}
			
			unset($new_macros);
		}
		
		return array_unique($macros);
	}
	
	public static function listTemplateFiles($dir)
	{
		$template_files = array();
		if (!file_exists($dir) || !is_dir($dir)) return $template_files;
		$dh = dir($dir);
		while ($file = $dh->read()) {
			if ($file == '..' || $file == '.' || $file == '.svn' || $file == 'CVS') continue;
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
		if (isset($_ENV['HOME'])) {
			$wadfrc = $_ENV['HOME'].'/.wadf/config';
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
					@exec('stty -echo 2>&1');
					$input = trim(fgets(STDIN));
					@exec('stty echo 2>&1');
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
	
	protected function _debugOutput($string, $level=self::DEBUG_GENERAL)
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
			$this->_macro_values[$macro->name] = $macro->value;
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
		return "WADF version " . Tools_WADF::SWVERSION . " copyright 2006-2009 Tim Jackson (tim@timj.co.uk)\n\n".
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
		$this->consoleOutput($e->getMessage());
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

class Tools_WADF_Dependency
{
	const TYPE_PEAR = 'PEAR';
	const TYPE_SVN  = 'SVN';
	
	public static $types = array(self::TYPE_PEAR => 'PEAR package', self::TYPE_SVN => 'SVN checkout');

	public $type;
	public $name;
	public $version;
	public $metadata;
}
