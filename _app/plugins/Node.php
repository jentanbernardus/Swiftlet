<?php
/**
 * @package Swiftlet
 * @copyright 2009 ElbertF http://elbertf.com
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU Public License
 */

if ( !isset($this) ) die('Direct access to this file is not allowed');

class Node_Plugin extends Plugin
{
	public
		$version      = '1.0.0',
		$compatible   = array('from' => '1.3.0', 'to' => '1.3.*'),
		$dependencies = array('db'),
		$hooks        = array('init_after' => 1, 'install' => 1, 'remove' => 1)
		;

	const
		ROOT_ID = 1
		;

	private
		$paths = array()
		;

	/*
	 * Implement install hook
	 */
	function install()
	{
		if ( !in_array($this->app->db->prefix . 'nodes', $this->app->db->tables) )
		{
			$this->app->db->sql('
				CREATE TABLE {nodes} (
					`id`        INT(10)      UNSIGNED NOT NULL PRIMARY kEY AUTO_INCREMENT,
					`left_id`   INT(10)      UNSIGNED NOT NULL,
					`right_id`  INT(10)      UNSIGNED NOT NULL,
					`type`      VARCHAR(255)          NOT NULL,
					`title`     VARCHAR(255)          NOT NULL,
					`home`      TINYINT(1)   UNSIGNED NOT NULL DEFAULT 0,
					`path`      VARCHAR(255)          NOT NULL,
					`date`      DATETIME              NOT NULL,
					`date_edit` DATETIME              NOT NULL,
					INDEX `left_id`  (`left_id`),
					INDEX `right_id` (`right_id`),
					INDEX `type`     (`type`),
					INDEX `home`     (`home`),
					INDEX `path`     (`path`)
					) ENGINE = INNODB
				');

			$this->app->db->sql('
				INSERT INTO {nodes} (
					`left_id`,
					`right_id`,
					`type`,
					`title`,
					`date`,
					`date_edit`
					)
				VALUES (
					:left_id,
					:right_id,
					:type,
					:title,
					:date,
					:date_edit
				', array(
					':left_id'   => 0,
					':right_id'  => 1,
					':type'      => 'root',
					':title'     => 'ROOT',
					':date'      => gmdate('Y-m-d H:i:s'),
					':date_edit' => gmdate('Y-m-d H:i:s')
					)
				);
		}
	}

	/*
	 * Implement remove hook
	 */
	function remove()
	{
		if ( in_array($this->app->db->prefix . 'nodes', $this->app->db->tables) )
		{
			$this->app->db->sql('DROP TABLE {nodes}');
		}
	}

	/*
	 * Implement init_after hook
	 * See if there is a node associated with the current path
	 */
	function init_after()
	{
		if ( $this->view->request )
		{
			$this->app->db->sql('
				SELECT
					`type`
				FROM {nodes}
				WHERE
					' . ( $this->view->controller == 'Node' && !empty($this->app->input->args[0]) ? '
					`id`   = :id   OR' : '' ) . '
					`path` = :path
				LIMIT 1
				', array(
					':id'   => !empty($this->app->input->args[0]) ? ( int ) $this->app->input->args[0] : NULL,
					':path' => $this->view->request
					)
				);

			if ( $r = $this->app->db->result )
			{
				$params = array(
					'type'       => $r[0]['type'],
					'controller' => 'Err404'
					);

				$this->app->hook('display_node', $params);

				$this->view->controller = $params['controller'];
			}
		}
	}

	/**
	 * Create a node
	 * @param string $title
	 * @param int $parentId
	 * @param bool $move
	 * @return int
	 */
	function create($title, $type, $parentId)
	{
		if ( !$parentId )
		{
			return FALSE;
		}

		$this->app->db->sql('
			SELECT
				`left_id`,
				`right_id`
			FROM {nodes}
			WHERE
				`id` = ' . ( int ) $parentId . '
			LIMIT 1
			');

		if ( $this->app->db->result )
		{
			$parentNode = $this->app->db->result[0];

			$this->app->db->sql('
				UPDATE {nodes} SET
					`left_id`   = `left_id` + 2,
					`date_edit` = "' . gmdate('Y-m-d H:i:s') . '"
				WHERE
					`left_id` > ' . ( int ) $parentNode['left_id'] . '
				');

			$this->app->db->sql('
				UPDATE {nodes} SET
					`right_id`  = `right_id` + 2,
					`date_edit` = "' . gmdate('Y-m-d H:i:s') . '"
				WHERE
					`right_id` > ' . ( int ) $parentNode['left_id'] . '
				');

			$this->app->db->sql('
				INSERT INTO {nodes} (
					`left_id`,
					`right_id`,
					`type`,
					`title`,
					`date`,
					`date_edit`
					)
				VALUES (
					:left_id,
					:right_id,
					:type,
					:title,
					:date,
					:date_edit
					)
				', array(
					':left_id'   => ( int ) $parentNode['left_id'] + 1,
					':right_id'  => ( int ) $parentNode['left_id'] + 2,
					':type'      => $type,
					':title'     => $this->view->h($title),
					':date'      => gmdate('Y-m-d H:i:s'),
					':date_edit' => gmdate('Y-m-d H:i:s')
					));

			return $this->app->db->result;
		}

		return FALSE;
	}

	/**
	 * Move a branch
	 * @param int $id
	 * @param int $parentId
	 * @return bool
	 */
	function move($id, $parentId)
	{
		// Root node can not be moved
		if ( $id == Node_Plugin::ROOT_ID )
		{
			return;
		}

		$node       = $this->get_children($id);
		$parentNode = $this->get($parentId);

		if ( !$node )
		{
			return;
		}

		// Node can not be moved to a decendant of its own
		if ( $node[0]['left_id'] <= $parentNode['left_id'] && $node[0]['right_id'] >= $parentNode['right_id'] )
		{
			return;
		}

		$diff = count($node['all']) * 2;

		// Sync parents
		$this->app->db->sql('
			UPDATE {nodes} SET
				`right_id` = `right_id` - :diff
			WHERE
				`left_id`  < :right_id AND
				`right_id` > :right_id
			', array(
				':diff'     => ( int ) $diff,
				':right_id' => ( int ) $node[0]['right_id']
				)
			);

		// Sync righthand side of tree
		$this->app->db->sql('
			UPDATE {nodes} SET
				`left_id`  = `left_id`  - :diff,
				`right_id` = `right_id` - :diff
			WHERE
				`left_id` > :right_id
			', array(
				':diff'     => ( int ) $diff,
				':right_id' => ( int ) $node[0]['right_id']
				)
			);

		$parentNode = $this->get($parentId);

		// Sync new parents
		$this->app->db->sql('
			UPDATE {nodes} SET
				`right_id` = `right_id` + :diff
			WHERE
				:parent_right_id BETWEEN `left_id` AND `right_id` AND
				`id` NOT IN ( :all )
			', array(
				':diff'            => ( int ) $diff,
				':parent_right_id' => ( int ) $parentNode['right_id'],
				':all'             => $node['all']
				)
			);

		// Sync righthand side of tree
		$this->app->db->sql('
			UPDATE {nodes} SET
				`left_id`  = `left_id`  + :diff,
				`right_id` = `right_id` + :diff
			WHERE
				`left_id` > :parent_right_id AND
				`id` NOT IN ( :all )
			', array(
				':diff'            => ( int ) $diff,
				':parent_right_id' => ( int ) $parentNode['right_id'],
				':all'             => $node['all']
				)
			);

		// Sync moved branch
		$parentNode['right_id'] += $diff;

		if ( $parentNode['right_id'] > $node[0]['right_id'] )
		{
			$diff = '+ ' . ( ( int ) $parentNode['right_id'] - ( int ) $node[0]['right_id'] - 1 );
		}
		else
		{
			$diff = '- ' . abs(( int ) $parentNode['right_id'] - ( int ) $node[0]['right_id'] - 1);
		}

		$this->app->db->sql('
			UPDATE {nodes} SET
				`left_id`  = `left_id`  :diff,
				`right_id` = `right_id` :diff
			WHERE
				`id` IN ( :all )
			', array(
				':diff' => $diff,
				':all'  => $node['all']
				)
			);
	}

	/**
	 * Delete a node
	 * @param int $parentId
	 * @param bool $soft
	 * @return int
	 */
	function delete($id)
	{
		$this->app->db->sql('
			SELECT
				`left_id`,
				`right_id`
			FROM {nodes}
			WHERE
				`id` = :id
			LIMIT 1
			', array(
				':id' => ( int ) $id
				)
			);

		if ( $this->app->db->result )
		{
			$leftId  = ( int ) $this->app->db->result[0]['left_id'];
			$rightId = ( int ) $this->app->db->result[0]['right_id'];

			$this->app->db->sql('
				UPDATE {nodes} SET
					`left_id`   = `left_id` - 2,
					`date_edit` = "' . gmdate('Y-m-d H:i:s') . '"
				WHERE
					`left_id`  > :left_id AND
					`right_id` > :right_id
				', array(
					':left_id'  => ( int ) $leftId,
					':right_id' => ( int ) $rightId
					)
				);

			$this->app->db->sql('
				UPDATE {nodes} SET
					`right_id`  = `right_id` - 2,
					`date_edit` = :date
				WHERE
					`right_id` > :right_id
				', array(
					':date'     => gmdate('Y-m-d H:i:s'),
					':right_id' => ( int ) $rightId
					)
				);

			$this->app->db->sql('
				UPDATE {nodes} SET
					`left_id`   = `left_id`  - 1,
					`right_id`  = `right_id` - 1,
					`date_edit` = :date
				WHERE
					`left_id`  > :left_id  AND
					`right_id` < :right_id
				', array(
					':date'     => gmdate('Y-m-d H:i:s'),
					':left_id'  => ( int ) $leftId,
					':right_id' => ( int ) $rightId
					)
				);

			$this->app->db->sql('
				DELETE
				FROM {nodes}
				WHERE
					`id` = :id
				LIMIT 1
				', array(
					':id' => ( int ) $id
					)
				);

			return $this->app->db->result;
		}
	}

	/**
	 * Get a node
	 * @param int $id
	 * @return array
	 */
	function get($id)
	{
		$node = array();

		$this->app->db->sql('
			SELECT
				*
			FROM {nodes}
			WHERE
				`id` = :id
			LIMIT 1
			', array(
				':id' => ( int ) $id
				)
			);

		if ( isset($this->app->db->result[0]) )
		{
			$node = $this->app->db->result[0];
		}

		return $node;
	}

	/**
	 * Get a node and its parents
	 * @param int $id
	 * @return array
	 */
	function get_parents($id)
	{
		$node = array();

		if ( $node = $this->get($id) )
		{
			$node['parents'] = array();

			$this->app->db->sql('
				SELECT
					*
				FROM {nodes}
				WHERE
					`left_id`  < :left_id  AND
					`right_id` > :right_id
				ORDER BY `left_id` ASC
				', array(
					':left_id'  => ( int ) $node['left_id'],
					':right_id' => ( int ) $node['right_id']
					)
				);

			if ( $this->app->db->result )
			{
				foreach ( $this->app->db->result as $d )
				{
					$node['parents'][] = $d;
				}
			}
		}

		return $node;
	}

	/**
	 * Get a node and its children
	 * @param int $id
	 * @return array
	 */
	function get_children($id, $type = '')
	{
		if ( $node = $this->get($id) )
		{
			$node['children'] = array();

			$this->app->db->sql('
				SELECT
					*
				FROM {nodes}
				WHERE
					`id` = ' . ( int ) $id . ' OR (
						`left_id` BETWEEN :left_id AND :right_id ' . ( $type ? 'AND
						`type` = :type' : '' ) . '
						)
				ORDER BY `left_id` ASC
				', array(
					':id'       => ( int ) $id,
					':left_id'  => ( int ) $node['left_id'],
					':right_id' => ( int ) $node['right_id'],
					':type'     => $this->app->db->escape($type)
					)
				);

			if ( $this->app->db->result )
			{
				$nodes    = array();
				$children = array();

				foreach ( $this->app->db->result as $d )
				{
					$children[] = ( int ) $d['id'];
				}

				foreach ( $this->app->db->result as $d )
				{
					$nodes[] = $d;
				}

				$nodes = $this->tree($nodes);

				$nodes['all'] = $children;

				return $nodes;
			}
		}
	}

	/**
	 * Create a structured array of nodes
	 * @param array $nodes
	 * @return array
	 */
	function tree($nodes)
	{
		$stack = array();

		for ( $i = 0; $i < count($nodes); $i ++ )
		{
			$node = &$nodes[$i];

			while ( count($stack) > 0 && $stack[count($stack) - 1]['right_id'] < $node['right_id'] )
			{
				array_pop($stack);
			}

			$node['level'] = count($stack);

			if ( count($stack) > 0 )
			{
				$stack[count($stack) - 1]['children'][] = &$node;
			}

			$stack[] = &$node;
		}

		return array($stack[0]);
	}

	/**
	 * Turn an array of nodes into a flat list
	 * @param array $nodes
	 * @param array $list
	 */
	function nodes_to_array($nodes, &$list)
	{
		unset($nodes['all']);

		if ( $nodes )
		{
			foreach ( $nodes as $node )
			{
				$list[] = $node;

				if ( !empty($node['children']) )
				{
					$this->nodes_to_array($node['children'], $list);
				}
			}
		}
	}
}
