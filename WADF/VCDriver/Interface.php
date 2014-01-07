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
 * WADF version control driver prototype interface
 */
interface Tools_WADF_VCDriver_Interface
{

	/**
	 * Constructor
	 * 
	 * @param Tools_WADF $wadf  A reference to the main WADF object
	 */
	public function __construct(&$wadf);
	
	/**
	 * Check out a specified version
	 *
	 * @param string  $revtype         Revision type. One of the Tools_WADF::REVTYPE_* constants
	 * @param string  $rev_translated  Revision, as appropriate for the specified revision type. i.e. tag name, branch name
	 * @param string  $raw_rev         Raw revision. A raw (absolute) revision number or identifier e.g. SVN revision number
	 * @param string  $dest_path       Destination path to check out to
	 */
	public function checkout($revtype, $rev_translated, $raw_rev, $dest_path);
	
	/**
	 * Switch an existing working copy to a specified revision
	 * 
	 * @param string  $revtype         Revision type. One of the Tools_WADF::REVTYPE_* constants
	 * @param string  $rev_translated  Revision, as appropriate for the specified revision type. i.e. tag name, branch name
	 * @param string  $raw_rev         Raw revision. A raw (absolute) revision number or identifier e.g. SVN revision number
	 * @return string                  Label
	 */
	public function switchVer($revtype, $rev_translated, $raw_rev, $dest_path);
	
	/**
	 * List available tags in the version control system
	 *
	 * @return array  List of tags
	 */
	public function listTags();
	
	/**
	 * Get a human-readable label for a specified path/revision
	 * 
	 * @param string  Revision type. One of the Tools_WADF::REVTYPE_* constants
	 * @param string  Revision, as appropriate for the specified revision type. i.e. tag name, branch name
	 * @param string  Raw revision. A raw (absolute) revision number or identifier e.g. SVN revision number
	 * @return string  Label
	 */
	public function getLabel($revtype, $rev_translated, $raw_rev=null);

	/**
	 * Read version control info from deployed copy
	 * @return Tools_WADF_VCInfo|false
	 */
	public static function readVCInfoFromDir($dir);

	

	/**
	 * Parse the details of a dependency string as read from the dependency tags
	 * file. The $dependency_line string contains the entire line that is
	 * specific for this type (the type itself is not included).
	 *
	 * If the parsing is successfull then this function returns an object of
	 * a class extending Tools_WADF_Dependency. Otherwise it returns null.
	 *
	 * @see docs/wadf.txt for available formats
	 * @param string $dependency_line The dependency line from the deptags file (excluding the type)
	 * @return Tools_WADF_Dependency|null
	 */
	public static function getDependencyDetails($dependency_line);


	/**
	 * Install a single dependency from VC, based on an object that was
	 * returned earlier from getDependencyDetails().
	 *
	 * If a dependency already exisits in the destination folder and is
	 * unmodified then it will be updated to the version as defined in the
	 * current dependency.
	 *
	 * When the install is completed this returns the full path of the current
	 * dependency. In case that is a PEAR package (has the package.xml file
	 * inside) then WADF will install it into the local PEAR.
	 *
	 * @param Tools_WADF_Dependency $dep
	 * @return string|null The full path to the installed dependency or null on failure
	 */
	public function installSingleDependency(Tools_WADF_Dependency $dep);
	
	
	/**
	 * Get an array of files/directories that contain internal metadata specific
	 * to the particular version control system.
	 *
	 * @return array An array of files/directories that will be ignored by WADF
	 */
	public static function getVCFilesToIgnore();

}
