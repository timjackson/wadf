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
	 * @param string $dest_path Destination path to check out to
	 */
	public function checkout($revtype, $rev_translated, $raw_rev, $dest_path);

	/**
	 * Check out a specified version from a version control system-specific path
	 * and version
	 *
	 * @param string $src_path  The path to check out from
	 * @param string $raw_rev   Raw revision. A raw (absolute) revision number or identifier e.g. SVN revision number
	 * @param string $dest_path Destination path to check out to
	 */
	public function checkoutFromPath($src_path, $raw_rev, $dest_path);
	
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

}
