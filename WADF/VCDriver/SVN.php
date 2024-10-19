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

/**
 * WADF version control driver for the SVN system
 */
require_once 'Tools/WADF/VCDriver/Interface.php';

class Tools_WADF_VCDriver_SVN implements Tools_WADF_VCDriver_Interface
{
	/**
	 * @var Tools_WADF  Reference to main WADF class
	 */
	protected $_wadf = null;
	
	protected $_svnroot = null;
	
	protected $_appref = null;

	public function __construct(&$wadf)
	{
		$this->_wadf = $wadf;
		$this->_svnroot = $wadf->resolveMacro('vc_base');
		$this->_appref = $wadf->appref;
	}
	
	public function checkout($revtype, $rev_translated, $raw_rev, $dest_path)
	{
		$svn_path = $this->_getSVNPath($revtype, $rev_translated);
		if ($revtype == Tools_WADF::VCREFTYPE_TAG && ($raw_rev !== null && $raw_rev != 'HEAD')) {
			throw new Exception("Don't pass a raw revision when checking out a tag");
		}
		if ($raw_rev !== null) $svn_path .= "@$raw_rev";
		$this->_runSVN("checkout $svn_path $dest_path");
	}
	
	protected function checkoutFromPath($src_path, $version, $dest_path)
	{
		$this->_runSVN("checkout $src_path@$version $dest_path");
	}
	
	public function switchVer($revtype, $rev_translated, $raw_rev, $dest_path)
	{
		$src_path = $this->_getSVNPath($revtype, $rev_translated);
		$this->switchVerFromPath($src_path, $raw_rev, $dest_path);
	}
	
	public function switchVerFromPath($src_path, $raw_rev, $dest_path)
	{
		$cwd = getcwd();
		chdir($dest_path);

		$info = $this->readVCInfoFromDir($dest_path);

		if ($info === false) {
			throw new Exception("Tried to switch the version of a non-working copy ($dest_path)");
		}

		if ($info->url == $src_path) {
			$this->_runSVN("up -r  $raw_rev");
		} else {
			$ver = $this->_getSVNVer();
			if (version_compare($ver, '1.5.0', '>=')) {
				// most reliable as we can use peg revisions
				$this->_runSVN("switch $src_path@$raw_rev");
			} else {
				// do the best we can without peg revisions

				// try to switch with -r
				$out = $this->_runSVN("switch -r $raw_rev $src_path", false);
				if ($out != 0) {
					// switching with -r didn't work
					chdir($cwd);
					$bak_dir = $dest_path . '.bak';
					if (file_exists($bak_dir)) {
						throw new Exception("Tried to deploy SVN dependency $src_path@$raw_rev by doing a fresh checkout to $dest_path, but can't back up to $bak_dir as this directory already exists");
					} else {
						rename($dest_path, $bak_dir);
						$this->checkoutFromPath($src_path, $raw_rev, $dest_path);
					}
				} else {
					// TODO some output here, but we don't have access to WADF's _debugOutput() method
				}
			}
		}

		chdir($cwd);
	}
	
	public function listTags()
	{
		$cmd = 'list -R ' . $this->_svnroot . '/' . $this->_appref . '/tags 2>/dev/null';
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
	
	public function getLabel($revtype, $rev_translated, $raw_rev=null)
	{
		$label = $this->_getSVNPath($revtype, $rev_translated);

		if ($raw_rev !== null) {
			$label .= ' rev ' . $raw_rev;
		}
		return $label;
	}

	/**
	 * Read version control info from deployed copy
	 * @return Tools_WADF_VCInfo|false
	 */
	public static function readVCInfoFromDir($dir)
	{
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
		
		if (preg_match('|/trunk/|', $info->url) || preg_match('|/trunk$|', $info->url)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_TRUNK;
		} else if (preg_match('|/tags/([^/]+)$|', $info->url, $matches)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_TAG;
			$info->rev_translated = $matches[1];
		} else if (preg_match('|/branches/([^/]+)|', $info->url, $matches)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_BRANCH;
			$info->rev_translated = $matches[1];
		} else {
			// Could not work out VC rev type from URL
			/*
			Used to return:
			$info->rev_type = Tools_WADF::VCREFTYPE_UNKNOWN;
			$info->rev_translated = 'unknown';
			*/
			return false;
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
	 * Execute an external SVN function
	 *
	 * @param string $params  The parameters to use on the command line
	 * @param bool $do_output  Whether to send SVN command output to _debugOutput()
	 * @return array|bool  Array of output lines from SVN, or FALSE if there was an error
	 */
	protected function _runSVN($params, $do_output=true)
	{
		$cmd = "svn $params";
		return $this->_wadf->runCmd($cmd, $do_output);
	}
	
	protected function _getSVNPath($revtype, $rev_translated)
	{
		switch ($revtype) {
			case Tools_WADF::VCREFTYPE_TRUNK:
				$svn_path = 'trunk';
				break;
			case Tools_WADF::VCREFTYPE_BRANCH:
				$svn_path = "branches/$rev_translated";
				break;
			case Tools_WADF::VCREFTYPE_TAG:
				$svn_path = "tags/$rev_translated";
				break;
			default:
				throw new Exception("Bad revtype: $revtype");
		}
		
		$svn_full_path = $this->_svnroot . '/' . $this->_appref . '/' . $svn_path;
		
		return $svn_full_path;
	}

	protected function _getSVNVer()
	{
		exec("svn --version", $out, $ret);
		if ($ret == 0 && preg_match('/svn, version (\S+)\s/i', $out[0], $matches)) {
			return $matches[1];
		} else {
			throw new Exception("Could not determine SVN version from 'svn --version'; return code was $ret, output was " . $out[0]);
		}
	}

	/**
	 * Get the details of an SVN dependency
	 *
	 * @see Tools_WADF_VCDriver_Interface::getDependencyDetails()
	 * @param string $dependency_line The dependency line from the deptags file (excluding the type)
	 * @return Tools_WADF_Dependency_SVN|null
	 */
	public static function getDependencyDetails($dependency_line)
	{
		if (preg_match('/^(.+)@(\d+)\s+(.+)$/', $dependency_line, $parts)) {
			$dep = new Tools_WADF_Dependency_SVN();
			$dep->type = get_class();
			$dep->full_svn_url = $parts[1];
			$dep->revision = $parts[2];
			$dep->local_relative_path = $parts[3];
			
			// Strip leading slashes; dep tags are always relative to the site root
			if (substr($dep->local_relative_path,0,1) == '/') {
				$dep->local_relative_path = substr($dep->local_relative_path, 1);
			}

			return $dep;
		}

		return null;
	}

	/**
	 * Install a single dependency from SVN, based on an object that was
	 * returned earlier from getDependencyDetails().
	 *
	 * @see Tools_WADF_VCDriver_Interface::installSingleDependency()
	 * @param Tools_WADF_Dependency_SVN $dep
	 * @return string|null The full path to the installed dependency or null on failure
	 */
	public function installSingleDependency(Tools_WADF_Dependency $dep)
	{
		if (!($dep instanceof Tools_WADF_Dependency_SVN)) {
			$this->_wadf->_debugOutput("\tDependency $dep->url is not an SVN dependency", Tools_WADF::DEBUG_ERROR);
		}
		
		// dep->full_svn_url is the SVN path to check out
		// dep->local_relative_path contains relative path
		$path = $this->_wadf->resolveMacro('deploy_path') . DIRECTORY_SEPARATOR . $dep->local_relative_path;
		// trim trailing slash from path, if it exists
		if (substr($path, -1) == DIRECTORY_SEPARATOR) {
			$path = substr($path, 0, -1);
		}
		if (is_dir($path)) {
			if (is_dir($path . DIRECTORY_SEPARATOR . '.svn')) {
				$out = $ret = null;
				exec("svn status $path", $out, $ret);
				if (count($out) == 0) {
					$this->_wadf->_debugOutput("\tDeploying SVN dependency $dep->full_svn_url to existing working copy $path", Tools_WADF::DEBUG_INFORMATION);
					$this->switchVerFromPath($dep->full_svn_url, $dep->revision, $path);

					return $path;
				} else {
					$this->_wadf->_debugOutput("\tCannot deploy SVN dependency $dep->full_svn_url; $path is not a clean working copy", Tools_WADF::DEBUG_ERROR);
					$this->_wadf->_debugOutput("\tSVN status output was:\n" . implode("\n", $out), Tools_WADF::DEBUG_INFORMATION);
				}
			} else {
				$this->_wadf->_debugOutput("\tCannot deploy SVN dependency $dep->full_svn_url; $path already exists but is not a working copy", Tools_WADF::DEBUG_ERROR);
			}
		} else {
			$this->_wadf->_debugOutput("\tDeploying SVN dependency $dep->full_svn_url to $path", Tools_WADF::DEBUG_INFORMATION);
			$this->checkoutFromPath($dep->full_svn_url, $dep->revision, $path);

			return $path;
		}

		return null;
	}
	
	
	/**
	 * Get files/dirs to ignore - For SVN it is only the .svn directory
	 *
	 * @see Tools_WADF_VCDriver_Interface::getVCFilesToIgnore()
	 * @return array An array of files/directories that will be ignored by WADF
	 */
	public static function getVCFilesToIgnore()
	{
		return array('.svn');
	}

}

class Tools_WADF_Dependency_SVN extends Tools_WADF_Dependency
{
	public $full_svn_url;
	public $revision;
	public $local_relative_path;
}

