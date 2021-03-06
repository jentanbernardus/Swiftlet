<?php
/**
 * @package Swiftlet
 * @copyright 2009 ElbertF http://elbertf.com
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU Public License
 */

if ( !isset($this) ) die('Direct access to this file is not allowed');

class Doc_Plugin extends Plugin
{
	public
		$version    = '1.0.0',
		$compatible = array('from' => '1.3.0', 'to' => '1.3.*'),
		$hooks      = array('dashboard' => 999)
		;

	/*
	 * Implement dashboard hook
	 * @params array $params
	 */
	function dashboard(&$params)
	{
		$params[] = array(
			'name'        => 'Documentation',
			'description' => 'Source code documentation and guides',
			'group'       => 'Developer',
			'path'        => 'doc',
			);
	}
}
