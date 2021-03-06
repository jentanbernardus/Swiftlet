<?php
/**
 * @package Swiftlet
 * @copyright 2009 ElbertF http://elbertf.com
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU Public License
 */

if ( !isset($this) ) die('Direct access to this file is not allowed');

class User_Plugin extends Plugin
{
	public
		$version      = '1.0.0',
		$compatible   = array('from' => '1.3.0', 'to' => '1.3.*'),
		$dependencies = array('db', 'session'),
		$hooks        = array('dashboard' => 4, 'init' => 3, 'install' => 3, 'menu' => 999, 'unit_tests' => 1, 'remove' => 1)
		;

	public
		$prefs = array()
		;

	/*
	 * Implement install hook
	 */
	function install()
	{
		if ( !in_array($this->app->db->prefix . 'users', $this->app->db->tables) )
		{
			$this->app->db->sql('
				CREATE TABLE {users} (
					`id`                 INT(10)    UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
					`username`           VARCHAR(255)        NOT NULL UNIQUE,
					`email`              VARCHAR(255)            NULL,
					`owner`              TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
					`date`               DATETIME            NOT NULL,
					`date_edit`          DATETIME            NOT NULL,
					`date_login_attempt` DATETIME NOT            NULL,
					`pass_hash`          VARCHAR(60)         NOT NULL
					) ENGINE = INNODB
				');

			$passHash = $this->make_pass_hash('Admin', $this->app->config['sysPassword']);

			$this->app->db->sql('
				INSERT INTO {users} (
					`username`,
					`email`,
					`owner`,
					`date`,
					`date_edit`,
					`pass_hash`
					)
				VALUES (
					"Admin",
					:email,
					1,
					:owner,
					:date,
					:date_edit,
					:pass_hash
					)
				', array(
					':email'     => $this->app->config['adminEmail'],
					':date'      => gmdate('Y-m-d H:i:s'),
					':date_edit' => gmdate('Y-m-d H:i:s'),
					':pass_hash' => $passHash
					)
				);
		}

		if ( !in_array($this->app->db->prefix . 'user_prefs', $this->app->db->tables) )
		{
			$this->app->db->sql('
				CREATE TABLE {user_prefs} (
					`id`      INT(10) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
					`pref`    VARCHAR(255)     NOT NULL UNIQUE,
					`type`    VARCHAR(255)     NOT NULL,
					`match`   VARCHAR(255)     NOT NULL,
					`options` TEXT                 NULL
					) ENGINE = INNODB
				');
		}

		if ( !in_array($this->app->db->prefix . 'user_prefs_xref', $this->app->db->tables) )
		{
			$this->app->db->sql('
				CREATE TABLE {user_prefs_xref} (
					`id`      INT(10) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
					`user_id` INT(10) UNSIGNED NOT NULL,
					`pref_id` INT(10) UNSIGNED NOT NULL,
					`value`   VARCHAR(255)     NOT NULL,
					FOREIGN KEY (`user_id`) REFERENCES {users}      (`id`) ON DELETE CASCADE,
					FOREIGN KEY (`pref_id`) REFERENCES {user_prefs} (`id`) ON DELETE CASCADE
					) ENGINE = INNODB
				');
		}

	}

	/*
	 * Implement remove hook
	 */
	function remove()
	{
		if ( in_array($this->app->db->prefix . 'user_prefs_xref', $this->app->db->tables) )
		{
			$this->app->db->sql('DROP TABLE {user_prefs_xref}');
		}

		if ( in_array($this->app->db->prefix . 'user_prefs', $this->app->db->tables) )
		{
			$this->app->db->sql('DROP TABLE {user_prefs}');
		}

		if ( in_array($this->app->db->prefix . 'users', $this->app->db->tables) )
		{
			$this->app->db->sql('DROP TABLE {users}');
		}
	}

	/*
	 * Implement menu hook
	 * @params array $params
	 */
	function menu(&$params)
	{
		if ( !$this->app->session->id )
		{
			$params['Log in'] = 'login';
		}
		else
		{
			$params['Account'] = 'account';
			$params['Log out (' .  $this->view->allow_html($this->app->session->get('user username')) . ')']  = 'login/logout';
		}
	}

	/*
	 * Implement init hook
	 */
	function init()
	{
		if ( in_array($this->app->db->prefix . 'user_prefs', $this->app->db->tables) )
		{
			$this->app->db->sql('
				SELECT
					*
				FROM `' . $this->app->db->prefix . 'user_prefs' . '`
				');

			if ( $r = $this->app->db->result )
			{
				foreach ( $r as $d )
				{
					$this->prefs[$d['pref']] = $d;

					$this->prefs[$d['pref']]['options'] = unserialize($d['options']);
				}
			}
		}

		$this->app->session->put('pref_values', $this->get_pref_values($this->app->session->get('user id')));
	}

	/*
	 * Implement dashboard hook
	 * @param array $params
	 */
	function dashboard(&$params)
	{
		$params[] = array(
			'name'        => 'Accounts',
			'description' => 'Add and edit accounts',
			'group'       => 'Users',
			'path'        => 'account'
			);
	}

	/**
	 * Login
	 * @param string $username
	 * @param string $password
	 * @return bool
	 */
	function login($username, $password, $remember)
	{
		$username = $this->view->h($username);

		if ( !$this->app->session->id )
		{
			$this->app->db->sql('
				UPDATE {users} SET
					`date_login_attempt` = :date
				WHERE
					`username` = :username
				LIMIT 1
				', array(
					':date'     => gmdate('Y-m-d H:i:s'),
					':username' => $username
					)
				);

			if ( $this->validate_password($username, $password) )
			{
				$this->app->db->sql('
					SELECT
						*
					FROM {users}
					WHERE
						`username` = :username
					LIMIT 1
					', array(
						':username' => $username
						), FALSE
					);

				if ( !empty($this->app->db->result[0]) && $r = $this->app->db->result[0] )
				{
					$lifeTime = $remember ? 60 * 60 * 24 * 14 : $this->app->session->sessionLifeTime;

					$this->app->session->create();

					$this->app->session->put(array(
						'user id'       => $r['id'],
						'user username' => $r['username'],
						'user email'    => $r['email'],
						'user is owner' => $r['owner'],
						'lifetime'      => $lifeTime
						));

					return TRUE;
				}
			}
		}
	}

	/**
	 * Logout
	 * @return bool
	 */
	function logout()
	{
		$this->app->session->destroy();
	}

	/**
 	 * Validate password
	 * @param string $username
	 * @param string $password
	 * @return bool
	 */
	function validate_password($username, $password)
	{
		$this->app->db->sql('
			SELECT
				`pass_hash`
			FROM {users}
			WHERE
				`username` = :username
			LIMIT 1
			', array(
				':username' => $username
				), FALSE
			);

		if ( !empty($this->app->db->result[0]) && $r = $this->app->db->result[0] )
		{
			return crypt($password, $r['pass_hash']) == $r['pass_hash'];
		}
	}

	/**
 	 * Create a password hash
	 * @param string $username
	 * @param string $password
	 * @return string
	 */
	function make_pass_hash($username, $password)
	{
		if ( CRYPT_BLOWFISH == 1 )
		{
			$salt     = '$2a$13$' . substr(hash('sha256', uniqid(mt_rand(), TRUE) . 'swiftlet' . strtolower($username)), 0, 22);
			$passHash = $salt . $password;

			$passHash = crypt($password, $salt);

			return $passHash;
		}
	}

	/**
	 * Save a preference
	 * @param array $params
	 */
	function save_pref($params)
	{
		$params = array_merge(array(
			'pref'    => '',
			'type'    => 'text',
			'match'   => '/.*/',
			'options' => array()
			), $params);

		$this->app->db->sql('
			INSERT INTO {user_prefs} (
				`pref`,
				`type`,
				`match`,
				`options`
				)
			VALUES (
				:pref,
				:type,
				:match,
				:options
				)
			ON DUPLICATE KEY UPDATE
				`options` = :options
			', array(
				':pref'    => $params['pref'],
				':type'    => $params['type'],
				':match'   => $params['match'],
				':options' => serialize($params['options'])
				)
			);
	}

	/**
	 * Delete a preference
	 * @param string $pref
	 */
	function delete_pref($pref)
	{
		$this->app->db->sql('
			DELETE
				up, upx
			FROM      {user_prefs}      AS  up
			LEFT JOIN {user_prefs_xref} AS upx ON up.`id` = upx.`pref_id`
			WHERE
				up.`pref` = :pref
			', array(
				':pref' => $pref
				)
			);
	}

	/**
	 * Save a preference value
	 * @param array $params
	 */
	function save_pref_value($params)
	{
		$this->app->db->sql('
			INSERT INTO {user_prefs_xref} (
				`user_id`,
				`pref_id`,
				`value`
				)
			VALUES (
				:user_id,
				:pref_id,
				:value
				)
			ON DUPLICATE KEY UPDATE
				`value` = :value
			', array(
				':user_id' => ( int ) $params['user_id'],
				':pref_id' => ( int ) $this->prefs[$params['pref']]['id'],
				':value'   => $params['value']
				)
			);

		if ( $this->app->db->result )
		{
			$params = array(
				'pref'  => $params['pref'],
				'value' => $params['value'],
				);
		}
	}

	/**
	 * Get a user's preferences
	 * @param int $id
	 */
	function get_pref_values($userId)
	{
		$prefs = array();

		if ( ( int ) $userId )
		{
			$this->app->db->sql('
				SELECT
					uo.`pref`,
					uox.`value`
				FROM      {user_prefs}      AS uo
				LEFT JOIN {user_prefs_xref} AS uox ON uo.`id` = uox.`pref_id`
				WHERE
					uox.`user_id` = :user_id
				', array(
					':user_id' => ( int ) $userId
					)
				);

			if ( $r = $this->app->db->result )
			{
				foreach ( $r as $d )
				{
					$prefs[$d['pref']] = $d['value'];
				}
			}
		}

		return $prefs;
	}

	/*
	 * Implement unit_tests hook
	 * @params array $params
	 */
	function unit_tests(&$params)
	{
		/**
		 * Creating a user account
		 */
		$post = array(
			'username'             => 'Unit_Test',
			'new_password'         => '123',
			'new_password_confirm' => '123',
			'owner'                => '0',
			'form-submit'          => 'Submit',
			'auth-token'           => $this->app->input->authToken
			);

		$r = $this->app->test->post_request('http://' . $_SERVER['SERVER_NAME'] . $this->view->rootPath . 'account/create', $post);

		$this->app->db->sql('
			SELECT
				*
			FROM {users}
			WHERE
				`username` = "Unit_Test"
			LIMIT 1
			', FALSE);

		$user = isset($this->app->db->result[0]) ? $this->app->db->result[0] : FALSE;

		$params[] = array(
			'test' => 'Creating a user account in <code>/account</code>.',
			'pass' => ( bool ) $user['id']
			);

		/**
		 * Editing a user account
		 */
		if ( $user['id'] )
		{
			$post = array(
				'username'    => $user['username'],
				'password'    => '123',
				'owner'       => $user['owner'],
				'email'       => 'unit@test.com',
				'form-submit' => 'Submit',
				'auth-token'  => $this->app->input->authToken
				);

			$r = $this->app->test->post_request('http://' . $_SERVER['SERVER_NAME'] . $this->view->rootPath . 'account/edit/' . ( int ) $user['id'], $post);
		}

		$this->app->db->sql('
			SELECT
				`email`
			FROM {users}
			WHERE
				`id` = :id
			LIMIT 1
			', array(
				':id' => ( int ) $user['id']
				), FALSE
			);

		$email = isset($this->app->db->result[0]) ? $this->app->db->result[0]['email'] : FALSE;

		$params[] = array(
			'test' => 'Editing a user account in <code>/account</code>.',
			'pass' => $email == 'unit@test.com'
			);

		/**
		 * Deleting a user account
		 */
		if ( $user['id'] )
		{
			$post = array(
				'get_data'   => serialize(array(
					'id'     => ( int ) $user['id'],
					'action' => 'delete'
					)),
				'confirm'    => '1',
				'auth-token' => $this->app->input->authToken
				);

			$r = $this->app->test->post_request('http://' . $_SERVER['SERVER_NAME'] . $this->view->rootPath . 'account/delete/' . ( int ) $user['id'], $post);
		}

		$this->app->db->sql('
			SELECT
				`id`
			FROM {users}
			WHERE
				`id` = :id
			LIMIT 1
			', array(
				':id' => ( int ) $user['id']
				), FALSE
			);

		$params[] = array(
			'test' => 'Deleting a user account <code>/account</code>.',
			'pass' => !$this->app->db->result
			);

		/**
		 * Creating a user preference
		 */
		$this->app->user->save_pref(array(
			'pref'    => 'Unit Test',
			'type'    => 'text',
			'match'   => '/.* /'
			));

		$this->app->db->sql('
			SELECT
				`id`
			FROM {user_prefs}
			WHERE
				`pref` = "Unit Test"
			LIMIT 1
			', FALSE);

		$params[] = array(
			'test' => 'Creating a user preference.',
			'pass' => $this->app->db->result
			);

		/**
		 * Deleting a user preference
		 */
		$this->app->user->delete_pref('Unit Test');

		$this->app->db->sql('
			SELECT
				`id`
			FROM {user_prefs}
			WHERE
				`pref` = "Unit Test"
			LIMIT 1
			', FALSE);

		$params[] = array(
			'test' => 'Deleting a user preference.',
			'pass' => !$this->app->db->result
			);
	}
}
