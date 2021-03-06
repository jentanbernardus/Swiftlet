<?php
/**
 * @package Swiftlet
 * @copyright 2009 ElbertF http://elbertf.com
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU Public License
 */

if ( !isset($this) ) die('Direct access to this file is not allowed');

/**
 * Account
 * @abstract
 */
class Account_Controller extends Controller
{
	public
		$pageTitle    = 'Account settings',
		$dependencies = array('db', 'session', 'user', 'input')
		;

	function init()
	{
		if ( $this->action == 'create' && !$this->app->session->get('user is owner') )
		{
			header('Location: ' . $this->view->route('login?ref=' . $this->request, FALSE));

			$this->app->end();
		}

		// Get preferences
		$prefsValidate = array();

		if ( $this->app->user->prefs )
		{
			foreach ( $this->app->user->prefs as $d )
			{
				$prefsValidate['pref-' . $d['id']] = $d['match'];
			}
		}

		$this->app->input->validate(array(
			'form-submit'          => 'bool',
			'username'             => 'string, empty',
			'password'             => 'string, empty',
			'new_password'         => 'string, empty',
			'new_password_confirm' => 'string, empty',
			'email'                => 'email,  empty',
			'owner'                => 'bool'
			) + $prefsValidate);

		if ( !$this->app->session->id )
		{
			header('Location: ' . $this->view->route('login?ref=' . $this->request, FALSE));

			$this->app->end();
		}

		if ( $this->id && $this->app->session->get('user is owner') )
		{
			$this->app->db->sql('
				SELECT
					*
				FROM {users}
				WHERE
					`id` = :id
				LIMIT 1
				', array(
					':id' => ( int ) $this->id
					)
				);

			if ( $r = $this->app->db->result )
			{
				$user = array(
					'id'       => $r[0]['id'],
					'username' => $r[0]['username'],
					'email'    => $r[0]['email'],
					'owner'    => $r[0]['owner']
					);
			}
		}

		if ( !isset($user) )
		{
			if ( $this->action == 'create' )
			{
				$user = array(
					'id'       => '',
					'username' => '',
					'email'    => '',
					'owner'    => ''
					);
			}
			else
			{
				$user = array(
					'id'       => $this->app->session->get('user id'),
					'username' => $this->app->session->get('user username'),
					'email'    => $this->app->session->get('user email'),
					'owner'    => $this->app->session->get('user is owner')
					);
			}
		}

		// Get user's preferences
		foreach ( $this->app->user->prefs as $pref )
		{
			$user['pref-' . $pref['id']] = '';
		}

		if ( $user['id'] )
		{
			$userprefs = $this->app->user->get_pref_values($user['id']);

			if ( $userprefs )
			{
				foreach ( $userprefs as $k => $v )
				{
					$user['pref-' . $this->app->user->prefs[$k]['id']] = $v;
				}
			}
		}

		if ( $this->app->input->POST_valid['form-submit'] )
		{
			if ( $this->action == 'create' )
			{
				if ( !$this->app->input->POST_valid['new_password'] )
				{
					$this->app->input->errors['new_password'] = $this->view->t('Please provide a password');
				}
			}
			else if ( !$this->app->session->get('user is owner') && $this->app->session->get('user id') == $this->id || !$this->id )
			{
				if ( !$this->app->user->validate_password($this->app->session->get('user username'), $this->app->input->POST_raw['password']) )
				{
					$this->app->input->errors['password'] = $this->view->t('Incorrect password, try again');
				}
			}

			if ( $this->app->input->POST_valid['new_password'] || $this->app->input->POST_valid['new_password_confirm'] )
			{
				if ( $this->app->input->POST_valid['new_password'] != $this->app->input->POST_valid['new_password_confirm'] )
				{
					$this->app->input->errors['new_password_repeat'] = $this->view->t('Passwords do not match');
				}
			}

			if ( $this->app->session->get('user is owner') )
			{
				if ( !$this->app->input->POST_valid['username'] )
				{
					$this->app->input->errors['username'] = $this->view->t('Please provide a username');
				}

				if ( strtolower($this->app->input->POST_html_safe['username']) != strtolower($user['username']) )
				{
					$this->app->db->sql('
						SELECT
							`id`
						FROM {users}
						WHERE
							`username` = :username
						LIMIT 1
						', array(
							':username' => $this->app->input->POST_raw['username']
							)
						);

					if ( $this->app->db->result )
					{
						$this->app->input->errors['username'] = $this->view->t('Username has already been taken');
					}
				}
			}

			if ( $this->app->input->errors )
			{
				$this->view->error = $this->view->t('Please correct the errors below.');
			}
			else
			{
				$username = $user['username'];
				$owner    = $user['owner'];

				if ( $this->app->session->get('user is owner') )
				{
					$username = $this->app->input->POST_html_safe['username'];

					if ( $this->app->session->get('user id') != $user['id'] )
					{
						$owner = $this->app->input->POST_valid['owner'];
					}
				}

				$password = $this->app->input->POST_valid['new_password'] ? $this->app->input->POST_valid['new_password'] : $this->app->input->POST_raw['password'];

				$passHash = $this->app->user->make_pass_hash($username, $password);

				$email = $this->app->input->POST_valid['email'] ? $this->app->input->POST_db_safe['email'] : FALSE;

				switch ( $this->action )
				{
					case 'create':
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
								:username,
								:email,
								:owner,
								:date,
								:date_edit,
								:pass_hash
								)
							', array(
								':username'  => $$username,
								':email'     => $email,
								':owner'     => ( int ) $owner,
								':date'      => gmdate('Y-m-d H:i:s'),
								':date_edit' => gmdate('Y-m-d H:i:s'),
								':pass_hash' => $passHash
								)
							);

						if ( $newId = $this->app->db->result )
						{
							foreach ( $this->app->user->prefs as $pref )
							{
								$this->app->user->save_pref_value(array(
									'user_id' => ( int ) $newId,
									'pref'    => $pref['pref'],
									'value'   => $this->app->input->POST_db_safe['pref-' . $pref['id']]
									));
							}

							header('Location: ' . $this->view->route($this->path . '/edit/' . $this->app->db->result . '&notice=created', FALSE));

							$this->app->end();
						}

						break;
					default:
						$this->app->db->sql('
							UPDATE {users} SET
								`username`  = :username,
								`email`     = :email,
								`owner`     = :owner,
								`date_edit` = :date_edit,
								`pass_hash` = :pass_hash
							WHERE
								`id` = :id
							LIMIT 1
							', array(
								':username'  => $username,
								':email'     => $email,
								':owner'     => ( int ) $owner,
								':date_edit' => gmdate('Y-m-d H:i:s'),
								':pass_hash' => $passHash,
								':id'        => ( int ) $user['id']
								)
							);

						if ( $this->app->db->result )
						{
							if ( $this->app->session->get('user id') == $user['id'] )
							{
								$this->app->session->put(array(
									'user username' => $username,
									'user email'    => $email,
									'user is owner' => ( int ) $owner
									));
							}

							foreach ( $this->app->user->prefs as $pref )
							{
								$this->app->user->save_pref_value(array(
									'user_id' => ( int ) $user['id'],
									'pref'    => $pref['pref'],
									'value'   => $this->app->input->POST_db_safe['pref-' . $pref['id']]
									));
							}

							header('Location: ' . $this->view->route($this->path . '/edit/' . $this->id . '&notice=saved', FALSE));

							$this->app->end();
						}
						else
						{
							$this->view->notice = $this->view->t('There were no changes.');
						}
				}
			}
		}
		else
		{
			/**
			 * Default form values
			 */
			$this->app->input->POST_html_safe['username'] = $user['username'];
			$this->app->input->POST_html_safe['email']    = $user['email'];
			$this->app->input->POST_html_safe['owner']    = ( int ) $user['owner'];

			foreach ( $this->app->user->prefs as $d )
			{
				$this->app->input->POST_html_safe['pref-' . $d['id']] = $user['pref-' . $d['id']];
			}
		}

		if ( $this->id )
		{
			switch ( $this->action )
			{
				case 'delete':
					if ( $user && $this->app->session->get('user is owner') )
					{
						if ( !$this->app->input->POST_valid['confirm'] )
						{
							$this->app->input->confirm($this->view->t('Are you sure you wish to delete this account?'));
						}
						else
						{
							// Delete account
							$this->app->db->sql('
								DELETE
								FROM {users}
								WHERE
									`id` = :id
								LIMIT 1
								', array(
									':id' => ( int ) $this->id
									)
								);

							if ( $this->app->db->result )
							{
								$this->app->db->sql('
									DELETE
									FROM {user_prefs_xref}
									WHERE
										`user_id` = :user_id
									', array(
										':user_id' => ( int ) $this->id
										)
									);

								header('Location: ' . $this->view->route($this->path . '?notice=deleted', FALSE));

								$this->app->end();
							}
						}
					}

					break;
			}
		}

		if ( isset($this->app->input->GET_raw['notice']) )
		{
			switch ( $this->app->input->GET_raw['notice'] )
			{
				case 'saved':
					$this->view->notice = $this->view->t('Your changes have been saved.');

					break;
				case 'created':
					$this->view->notice = $this->view->t('The account has been created.');

					break;
				case 'deleted':
					$this->view->notice = $this->view->t('The account has been deleted.');

					break;
			}
		}

		$this->view->userId       = $user['id'];
		$this->view->userUsername = $user['username'];

		if ( $this->app->session->get('user is owner') )
		{
			$this->app->db->sql('
				SELECT
					COUNT(`id`) as `count`
				FROM {users}
				');

			if ( $r = $this->app->db->result )
			{
				$usersPagination = $this->view->paginate('users', $r[0]['count'], 25);

				$this->app->db->sql('
					SELECT
						`id`,
						`username`
					FROM {users}
					ORDER BY `username`
					LIMIT :from, 25
					', array(
						':from' => ( int ) $usersPagination['from']
						)
					);

				if ( $r = $this->app->db->result )
				{
					$this->view->users = array();

					foreach ( $r as $i => $d )
					{
						$this->view->users[$d['id']] = $d['username'];
					}

					asort($this->view->users);
				}

				$this->view->usersPagination = $usersPagination;
			}
		}

		$this->view->prefs = $this->app->user->prefs;

		$this->view->load('account.html.php');
	}
}
