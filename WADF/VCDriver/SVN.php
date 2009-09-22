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
		if ($raw_rev !== null && $raw_rev != 'HEAD') {
			throw new Exception("Don't pass a raw revision when checking out a tag");
		}
		if ($raw_rev === null) $svn_path .= "@$raw_rev";
		$this->_runSVN("checkout $svn_path $dest_path");
	}
	
	public function checkoutFromPath($src_path, $version, $dest_path)
	{
		$this->_runSVN("checkout $src_path@$version $dest_path");
	}
	
	public function switchVer($revtype, $rev_translated, $raw_rev, $dest_path)
	{
		$this->_runSVN("switch -r $version $src_path $dest_path");
	}
	
	public function listTags()
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
		
		if (preg_match('|/trunk$|', $info->url)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_TRUNK;
		} else if (preg_match('|/tags/([^/]+)$|', $info->url, $matches)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_TAG;
			$info->rev_translated = $matches[1];
		} else if (preg_match('|/branches/([^/]+)$|', $info->url, $matches)) {
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

}