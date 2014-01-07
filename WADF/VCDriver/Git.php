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
 * WADF version control driver for Git.
 */
require_once 'Tools/WADF/VCDriver/Interface.php';

class Tools_WADF_VCDriver_Git implements Tools_WADF_VCDriver_Interface
{
	/**
	 * @var Tools_WADF object  Reference to main WADF class
	 */
	protected $_wadf = null;

	/**
	 * @var string Where are the apps located
	 */
	protected $_gitroot = null;

	/**
	 * @var string The reference (name) of the app deployed
	 */
	protected $_appref = null;

	public function __construct(&$wadf)
	{
		$this->_wadf = $wadf;
		$this->_gitroot = $wadf->resolveMacro('vc_base');
		$this->_appref = $wadf->appref;
	}


	/**
	 * Check out the git repository to a local directory.
	 * 
	 * With git the entire repository has to be cloned (git clone) and then
	 * you can checkout a specific branch/tag (git checkout).
	 * 
	 * Example:
	 * 
	 * 1. Clone first
	 *    git clone git@git.example.com:user/repository.git repository-dir => HEAD
	 * 
	 * 2. Make a checkout
	 *    cd repository-dir
	 *    git checkout --detach commit             //trunk at commit       => detached HEAD
	 *    git checkout release-1.0.1               //tag                   => HEAD
	 *    git checkout release-1.0                 //branch                => HEAD
	 *    git checkout release-1.0 --detach commit //branch at commit      => detached HEAD
	 * 
	 * @param string $revtype        Revision type. One of the Tools_WADF::REVTYPE_* constants (trunk, branch or tag)
	 * @param string $rev_translated Depending on the revision type this will be either the branch name or the tag name
	 * @param string $raw_rev        Git commit
	 * @param string $dest_path      Where to check out into
	 */
	public function checkout($revtype, $rev_translated, $raw_rev, $dest_path)
	{
		//clone the repository and point to HEAD of the master branch
		$this->_runGit("clone " . $this->_gitroot . $this->_appref . ".git $dest_path");

		$this->switchVer($revtype, $rev_translated, $raw_rev, $dest_path);
	}


	/**
	 * Check out the git repository to a local directory from a specific URL.
	 * 
	 * This is used when getting a checkout based on the dependency tags
	 * file (as part of installSingleDependency()) and is almost the same
	 * as the checkout() function.
	 * 
	 * @see Tools_WADF_VCDriver_Git::installSingleDependency()
	 * @see Tools_WADF_VCDriver_Git::checkout()
	 * @param string $url            URL to the repository
	 * @param string $revtype        Revision type. One of the Tools_WADF::REVTYPE_* constants (trunk, branch or tag)
	 * @param string $rev_translated Depending on the revision type this will be either the branch name or the tag name
	 * @param string $raw_rev        Git commit
	 * @param string $dest_path      Where to check out into
	 */
	protected function checkoutFromURL($url, $revtype, $rev_translated, $raw_rev, $dest_path)
	{
		//clone the repository and point to HEAD of the master branch
		$this->_runGit("clone $url $dest_path");

		$this->switchVer($revtype, $rev_translated, $raw_rev, $dest_path);
	}


	/**
	 * Switch the version of the code in a local directory.
	 * 
	 * With git this is done using the git checkout command. Switching to
	 * a specific commit creates a "detached HEAD" in you repository.
	 * 
	 * Note: this function is called from WADF::checkout and if the
	 * clientapp already exisits then wadf can switch to a different
	 * checkout.
	 * 
	 * @param string $revtype        Revision type. One of the Tools_WADF::REVTYPE_* constants (trunk, branch or tag)
	 * @param string $rev_translated Depending on the revision type this will be either the branch name or the tag name
	 * @param string $raw_rev        Git commit
	 * @param string $dest_path      Where to check out into
	 */
	public function switchVer($revtype, $rev_translated, $raw_rev, $dest_path)
	{
		//when deploying an older commit, a tag or a branch
		if ($revtype != Tools_WADF::VCREFTYPE_TRUNK || $raw_rev != 'HEAD') {
			//need to work within the directory containing the git repo
			$cwd = getcwd();
			chdir($dest_path);

			switch ($revtype) {
				case Tools_WADF::VCREFTYPE_TRUNK:
					//an older commit in the master branch - detached HEAD
					if ($raw_rev != 'HEAD') {
						$this->_runGit("checkout --detach $raw_rev");
					}
					break;
				case Tools_WADF::VCREFTYPE_BRANCH:
					if ($raw_rev == 'HEAD') {
						//HEAD of the branch
						$this->_runGit("checkout $rev_translated");
					} else {
						//an older commit in the branch - detached HEAD
						$this->_runGit("checkout $rev_translated --detach $raw_rev");
					}
					break;
				case Tools_WADF::VCREFTYPE_TAG:
					if ($raw_rev != 'HEAD') {
						throw new Exception("Don't pass a specific commit when checking out a tag");
					}
					//HEAD of the tag
					$this->_runGit("checkout tags/$rev_translated"); //using "tags/" just to be safe
					break;
				default:
					throw new Exception("Bad revtype: $revtype");
			}

			chdir($cwd);
		}
	}


	/**
	 * Get a list of tags for the current app (repository).
	 *
	 * When tags are listed the annotated ones display on two lines e.g.:
	 * c39e3671ab4be089026de41644aee83ed5391816	refs/tags/release-1.0.2
	 * 1635509e1234fc1aec1dbe0577db25aca9f26ed4	refs/tags/release-1.0.2^{}
	 *
	 * We are interested in the second line, but without the trailing "^{}"
	 *
	 * @return array
	 */
	public function listTags()
	{
		$cmd = 'ls-remote --tags ' . $this->_gitroot . $this->_appref . '.git 2>/dev/null';
		$output = $this->_runGit($cmd, false);
		if ($output === false) {
			throw new Exception("Error when listing tags - perhaps there aren't any?");
		}

		$tags = array();
		foreach ($output as $line) {
			if (preg_match('/^([a-z0-9]{40})\s+refs\/tags\/(.*)(\^{})?$/U', $line, $matches)) {
				$tags[$matches[2]] = $matches[1];
			}
		}

		return array_flip($tags);
	}


	/**
	 * Get a descriptive name for the version of the checkout
	 *
	 * @return string
	 */
	public function getLabel($revtype, $rev_translated, $raw_rev=null)
	{
		$label = $this->_gitroot . $this->_appref . ".git";

		switch ($revtype) {
			case Tools_WADF::VCREFTYPE_TRUNK:
				$label .= " branch master";
				break;
			case Tools_WADF::VCREFTYPE_BRANCH:
				$label .= " branch $rev_translated";
				break;
			case Tools_WADF::VCREFTYPE_TAG:
				$label .= " tag $rev_translated";
				break;
		}

		if (isset($raw_rev)) {
			if ($raw_rev == 'HEAD') {
				$label .= " at HEAD";
			} else {
				$label .= " at commit $raw_rev";
			}
		}

		return $label;
	}


	/**
	 * Read version control info from deployed copy
	 * 
	 * @param string $dir Directory to read from
	 * @return Tools_WADF_VCInfo|false
	 */
	public static function readVCInfoFromDir($dir)
	{
		//for the case where this is called before the directory is created
		if (!is_dir($dir)) {
			return false;
		}
		
		$cwd = getcwd();
		chdir($dir);
		
		$info = new Tools_WADF_VCInfo();

		$branch_output = $return = null;
		$cmd = "git branch 2>/dev/null";
		exec($cmd, $branch_output, $return);
		if ($return != 0) {
			return false;
		}
		$branch_output = implode("\n", $branch_output);


		$describe_output = $return = null;
		$cmd = "git describe --exact-match --tags HEAD 2>/dev/null";
		exec($cmd, $describe_output, $return);
		if ($return != 0) {
			return false;
		}


		if (preg_match('/^\* master$/m', $branch_output, $matches)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_TRUNK;
		} elseif (preg_match('/^\* \(no branch\)$/m', $branch_output, $matches) &&
			count($describe_output) == 1) {
			$info->rev_type = Tools_WADF::VCREFTYPE_TAG;
			$info->rev_translated = $describe_output[0];
		} elseif (preg_match('/^\* (\S*)$/m', $branch_output, $matches)) {
			$info->rev_type = Tools_WADF::VCREFTYPE_BRANCH;
			$info->rev_translated = $matches[1];
		} else {
			return false;
		}


		$log_output = $return = null;
		$cmd = "git log --pretty=oneline --abbrev-commit -n 1 2>/dev/null";
		exec($cmd, $log_output, $return);
		if ($return != 0 || count($log_output) != 1) {
			return false;
		}

		if (preg_match('/^(\S+)\s.*$/', $log_output[0], $matches)) {
			$info->rev_raw = $matches[1];
		}


		$status_output = $return = null;
		$cmd = "git status 2>/dev/null";
		exec($cmd, $status_output, $return);
		if ($return != 0) {
			return false;
		}
		$status_output = implode("\n", $status_output);

		$info->modifications = true;
		if (preg_match('/^nothing to commit \(working directory clean\)$/m', $status_output, $matches)) {
			$info->modifications = false;
		}


		$ini = parse_ini_file(".git/config");
		$info->url = $ini['url'];

		chdir($cwd);

		return $info;
	}
		
	/**
	 * Execute an external Git function.
	 *
	 * Git outputs a lot of info to STDERR, so that is always
	 * redirected to STDOUT.
	 *
	 * @param string $params  The parameters to use on the command line
	 * @param bool $do_output  Whether to send Git command output to _debugOutput()
	 * @return array|bool  Array of output lines from Git, or FALSE if there was an error
	 */
	protected function _runGit($params, $do_output=true)
	{
		$cmd = "git $params 2>&1";
		return $this->_wadf->runCmd($cmd, $do_output);
	}

	/**
	 * Get the details of a Git dependency
	 *
	 * @see Tools_WADF_VCDriver_Interface::getDependencyDetails()
	 * @param string $dependency_line The dependency line from the deptags file (excluding the type)
	 * @return Tools_WADF_Dependency_Git|null
	 */
	public static function getDependencyDetails($dependency_line)
	{	
		//http://git.example.com/path/to/repo.git|branch|master|123efb5 somedir/path
		if (preg_match('/^(.*)(?:\|(branch|tag)\|(.*)(?:\|(.*))?)?\s(.*)$/U', $dependency_line, $parts)) {
			$dep = new Tools_WADF_Dependency_Git();
			$dep->type = get_class();
			
			$dep->url = $parts[1];
			
			if (empty($parts[2]) && empty($parts[3]) && empty($parts[4])) {
				$dep->revtype = Tools_WADF::VCREFTYPE_TRUNK;
			} elseif (!empty($parts[2]) && !empty($parts[3])) {
				if ($parts[2] == 'branch' && $parts[3] == 'master') {
					$dep->revtype = Tools_WADF::VCREFTYPE_TRUNK;
				} elseif ($parts[2] == 'branch') {
					$dep->revtype = Tools_WADF::VCREFTYPE_BRANCH;
					$dep->rev_translated = $parts[3];
				} elseif ($parts[2] == 'tag') {
					$dep->revtype = Tools_WADF::VCREFTYPE_TAG;
					$dep->rev_translated = $parts[3];
				}
			} else {
				return null;
			}
			
			if (!empty($parts[4])) {
				$dep->raw_rev = $parts[4];
			} else {
				$dep->raw_rev = 'HEAD';
			}
			
			$dep->dest_path = ltrim($parts[5], DIRECTORY_SEPARATOR);
			
			return $dep;
		}

		return null;
	}

	/**
	 * Install a single dependency from Git, based on an object that was
	 * returned earlier from getDependencyDetails().
	 *
	 * @see Tools_WADF_VCDriver_Interface::installSingleDependency()
	 * @param Tools_WADF_Dependency_Git $dep
	 * @return string|null The full path to the installed dependency or null on failure
	 */
	public function installSingleDependency(Tools_WADF_Dependency $dep)
	{
		if (!($dep instanceof Tools_WADF_Dependency_Git)) {
			$this->_wadf->_debugOutput("\tDependency $dep->url is not a Git dependency", Tools_WADF::DEBUG_ERROR);
		}

		$path = $this->_wadf->resolveMacro('deploy_path') . DIRECTORY_SEPARATOR . $dep->dest_path;
		// trim trailing slash from path, if it exists
		if (substr($path, -1) == DIRECTORY_SEPARATOR) {
			$path = substr($path, 0, -1);
		}
		if (is_dir($path)) {
			if (is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
				$cwd = getcwd();
				chdir($path);
				
				$status_output = $return = null;
				$cmd = "git status 2>/dev/null";
				exec($cmd, $status_output, $return);
				chdir($cwd);
				$status_output = implode("\n", $status_output);

				if (preg_match('/^nothing to commit \(working directory clean\)$/m', $status_output) && $return == 0) {
					$this->_wadf->_debugOutput("\tDeploying Git dependency $dep->url to existing working copy $path", Tools_WADF::DEBUG_INFORMATION);
					$this->switchVer($dep->revtype, $dep->rev_translated, $dep->raw_rev, $dep->dest_path);

					return $path;
				} else {
					$this->_wadf->_debugOutput("\tCannot deploy Git dependency $dep->url; $path is not a clean working copy", Tools_WADF::DEBUG_ERROR);
					$this->_wadf->_debugOutput("\tGit status output was:\n" . $status_output, Tools_WADF::DEBUG_INFORMATION);
				}
			} else {
				$this->_wadf->_debugOutput("\tCannot deploy Git dependency $dep->url; $path already exists but is not a working copy", Tools_WADF::DEBUG_ERROR);
			}
		} else {
			$this->_wadf->_debugOutput("\tDeploying Git dependency $dep->url to $path", Tools_WADF::DEBUG_INFORMATION);
			$this->checkoutFromURL($dep->url, $dep->revtype, $dep->rev_translated, $dep->raw_rev, $dep->dest_path);

			return $path;
		}

		return null;
	}
	
	
	/**
	 * Get files/dirs to ignore - For Git it is only the .git directory
	 *
	 * @see Tools_WADF_VCDriver_Interface::getVCFilesToIgnore()
	 * @return array An array of files/directories that will be ignored by WADF
	 */
	public static function getVCFilesToIgnore()
	{
		return array('.git');
	}


}

class Tools_WADF_Dependency_Git extends Tools_WADF_Dependency
{
	public $url;            //the repo url
	public $revtype;        //Revision type. One of the Tools_WADF::REVTYPE_* constants
	public $rev_translated; //Revision, as appropriate for the specified revision type. i.e. tag name, branch name
	public $raw_rev;        //Git commit
	public $dest_path;      //Where to place the dependency relative to the deployed application
}
