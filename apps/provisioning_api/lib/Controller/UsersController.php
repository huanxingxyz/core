<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author michag86 <micha_g@arcor.de>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Tom Needham <tom@owncloud.com>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2020, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Provisioning_API\Controller;

use OC\OCS\Result;
use OC_Helper;
use OCP\API;
use OCP\AppFramework\OCSController;
use OCP\Files\NotFoundException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Util;

class UsersController extends OCSController {

	/** @var IUserManager */
	private $userManager;
	/** @var IGroupManager|\OC\Group\Manager */ // FIXME Requires a method that is not on the interface
	private $groupManager;
	/** @var IUserSession */
	private $userSession;
	/** @var ILogger */
	private $logger;
	/** @var \OC\Authentication\TwoFactorAuth\Manager */
	private $twoFactorAuthManager;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IUserManager $userManager
	 * @param IGroupManager $groupManager
	 * @param IUserSession $userSession
	 * @param ILogger $logger
	 * @param \OC\Authentication\TwoFactorAuth\Manager $twoFactorAuthManager
	 */
	public function __construct($appName,
								IRequest $request,
								IUserManager $userManager,
								IGroupManager $groupManager,
								IUserSession $userSession,
								ILogger $logger,
								\OC\Authentication\TwoFactorAuth\Manager $twoFactorAuthManager) {
		parent::__construct($appName, $request);
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->logger = $logger;
		$this->twoFactorAuthManager = $twoFactorAuthManager;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * returns a list of users
	 *
	 * @return Result
	 */
	public function getUsers() {
		$search = $this->request->getParam('search', '');
		$limit = $this->request->getParam('limit');
		$offset = $this->request->getParam('offset');

		// Check if user is logged in
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		// Admin? Or SubAdmin?
		$uid = $user->getUID();
		$subAdminManager = $this->groupManager->getSubAdmin();
		if ($this->groupManager->isAdmin($uid)) {
			$users = $this->userManager->search($search, $limit, $offset);
		} elseif ($subAdminManager->isSubAdmin($user)) {
			$subAdminOfGroups = $subAdminManager->getSubAdminsGroups($user);
			foreach ($subAdminOfGroups as $key => $group) {
				$subAdminOfGroups[$key] = $group->getGID();
			}

			if ($offset === null) {
				$offset = 0;
			}

			$users = [];
			foreach ($subAdminOfGroups as $group) {
				$users = \array_merge($users, $this->groupManager->displayNamesInGroup($group, $search));
			}

			$users = \array_slice($users, $offset, $limit);
		} else {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}
		$users = \array_keys($users);

		return new Result([
			'users' => $users
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return Result
	 */
	public function addUser() {
		$userId = $this->request->getParam('userid');
		$password = $this->request->getParam('password');
		$groups = $this->request->getParam('groups');
		$user = $this->userSession->getUser();

		$isAdmin = $this->groupManager->isAdmin($user->getUID());
		$subAdminManager = $this->groupManager->getSubAdmin();
		if (!$isAdmin && !$subAdminManager->isSubAdmin($user)) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		if ($this->userManager->userExists($userId)) {
			$this->logger->error('Failed addUser attempt: User already exists.', ['app' => 'ocs_api']);
			return new Result(null, 102, 'User already exists');
		}

		if (\is_array($groups)) {
			foreach ($groups as $group) {
				if (!$this->groupManager->groupExists($group)) {
					return new Result(null, 104, 'group '.$group.' does not exist');
				}
				if (!$isAdmin && !$subAdminManager->isSubAdminofGroup($user, $this->groupManager->get($group))) {
					return new Result(null, 105, 'insufficient privileges for group '. $group);
				}
			}
		} else {
			if (!$isAdmin) {
				return new Result(null, 106, 'no group specified (required for subadmins)');
			}
		}

		try {
			$newUser = $this->userManager->createUser($userId, $password);
			$this->logger->info('Successful addUser call with userid: '.$userId, ['app' => 'ocs_api']);

			if (\is_array($groups)) {
				foreach ($groups as $group) {
					$this->groupManager->get($group)->addUser($newUser);
					$this->logger->info('Added userid '.$userId.' to group '.$group, ['app' => 'ocs_api']);
				}
			}
			return new Result(null, 100);
		} catch (\Exception $e) {
			$this->logger->error('Failed addUser attempt with exception: '.$e->getMessage(), ['app' => 'ocs_api']);
			$message = $e->getMessage();
			if (empty($message)) {
				$message = 'Bad request';
			}
			return new Result(null, 101, $e->getMessage());
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * gets user info
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function getUser($userId) {
		// Check if user is logged in
		$loggedInUser = $this->userSession->getUser();
		if ($loggedInUser === null) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		$data = [];

		// Check if the target user exists
		$targetUserObject = $this->userManager->get($userId);
		if ($targetUserObject === null) {
			return new Result(null, API::RESPOND_NOT_FOUND, 'The requested user could not be found');
		}

		// Admin? Or SubAdmin?
		if ($this->groupManager->isAdmin($loggedInUser->getUID())
			|| $this->groupManager->getSubAdmin()->isUserAccessible($loggedInUser, $targetUserObject)) {
			$data['enabled'] = $targetUserObject->isEnabled() ? 'true' : 'false';
		} else {
			// Check they are looking up themselves
			if (\strcasecmp($loggedInUser->getUID(), $userId) !== 0) {
				return new Result(null, API::RESPOND_UNAUTHORISED);
			}
		}

		// Find the data
		$data['quota'] = $this->fillStorageInfo($userId);
		$data['quota']['definition'] = $targetUserObject->getQuota();
		$data['email'] = $targetUserObject->getEMailAddress();
		$data['displayname'] = $targetUserObject->getDisplayName();
		$data['home'] = $targetUserObject->getHome();
		$data['two_factor_auth_enabled'] = $this->twoFactorAuthManager->isTwoFactorAuthenticated($targetUserObject) ? 'true' : 'false';

		return new Result($data);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * edit users
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function editUser($userId) {
		// Check if user is logged in
		$currentLoggedInUser = $this->userSession->getUser();
		if ($currentLoggedInUser === null) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		$targetUser = $this->userManager->get($userId);
		if ($targetUser === null) {
			return new Result(null, 997);
		}

		if ($userId === $currentLoggedInUser->getUID()) {
			// Editing self (display, email)
			$permittedFields[] = 'display';
			$permittedFields[] = 'displayname';
			$permittedFields[] = 'email';
			$permittedFields[] = 'password';
			$permittedFields[] = 'two_factor_auth_enabled';
			// If admin they can edit their own quota
			if ($this->groupManager->isAdmin($currentLoggedInUser->getUID())) {
				$permittedFields[] = 'quota';
			}
		} else {
			// Check if admin / subadmin
			$subAdminManager = $this->groupManager->getSubAdmin();
			if ($subAdminManager->isUserAccessible($currentLoggedInUser, $targetUser)
			|| $this->groupManager->isAdmin($currentLoggedInUser->getUID())) {
				// They have permissions over the user
				$permittedFields[] = 'display';
				$permittedFields[] = 'displayname';
				$permittedFields[] = 'quota';
				$permittedFields[] = 'password';
				$permittedFields[] = 'email';
				$permittedFields[] = 'two_factor_auth_enabled';
			} else {
				// No rights
				return new Result(null, 997);
			}
		}
		// Check if permitted to edit this field
		$key = $this->request->getParam('key');
		$value = $this->request->getParam('value');
		if (!\in_array($key, $permittedFields)) {
			return new Result(null, 997);
		}
		// Process the edit
		switch ($key) {
			case 'display':
			case 'displayname':
				$targetUser->setDisplayName($value);
				break;
			case 'quota':
				$quota = $value;
				if ($quota !== 'none' && $quota !== 'default') {
					if (\is_numeric($quota)) {
						$quota = \floatval($quota);
					} else {
						$quota = Util::computerFileSize($quota);
					}
					if ($quota === false) {
						return new Result(null, 103, "Invalid quota value $value");
					}
					$quota = Util::humanFileSize($quota);
				}
				$targetUser->setQuota($quota);
				break;
			case 'password':
				try {
					$targetUser->setPassword($value);
				} catch (\Exception $e) {
					return new Result(null, 403, $e->getMessage());
				}
				break;
			case 'two_factor_auth_enabled':
				if ($value === true) {
					$this->twoFactorAuthManager->enableTwoFactorAuthentication($targetUser);
				} else {
					$this->twoFactorAuthManager->disableTwoFactorAuthentication($targetUser);
				}
				break;
			case 'email':
				if (\filter_var($value, FILTER_VALIDATE_EMAIL)) {
					$targetUser->setEMailAddress($value);
				} else {
					return new Result(null, 102);
				}
				break;
			default:
				return new Result(null, 103);
				break;
		}
		return new Result(null, 100);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function deleteUser($userId) {
		// Check if user is logged in
		$loggedInUser = $this->userSession->getUser();
		if ($this->isSubAdmin($loggedInUser) === false) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		$targetUser = $this->userManager->get($userId);

		if ($targetUser === null || $targetUser->getUID() === $loggedInUser->getUID()) {
			return new Result(null, 101);
		}

		// If not permitted
		$subAdminManager = $this->groupManager->getSubAdmin();
		if (!$this->groupManager->isAdmin($loggedInUser->getUID()) && !$subAdminManager->isUserAccessible($loggedInUser, $targetUser)) {
			return new Result(null, 997);
		}

		// Go ahead with the delete
		if ($targetUser->delete()) {
			return new Result(null, 100);
		} else {
			return new Result(null, 101);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function disableUser($userId) {
		return $this->setEnabled($userId, false);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function enableUser($userId) {
		return $this->setEnabled($userId, true);
	}

	/**
	 * @param string $userId
	 * @param bool $value
	 * @return Result
	 */
	private function setEnabled($userId, $value) {
		// Check if user is logged in
		$loggedInUser = $this->userSession->getUser();
		if ($this->isSubAdmin($loggedInUser) === false) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		$targetUser = $this->userManager->get($userId);
		if ($targetUser === null || $targetUser->getUID() === $loggedInUser->getUID()) {
			return new Result(null, 101);
		}

		// If not permitted
		$subAdminManager = $this->groupManager->getSubAdmin();
		if (!$this->groupManager->isAdmin($loggedInUser->getUID()) && !$subAdminManager->isUserAccessible($loggedInUser, $targetUser)) {
			return new Result(null, 997);
		}

		// enable/disable the user now
		$targetUser->setEnabled($value);
		return new Result(null, 100);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function getUsersGroups($userId) {
		// Check if user is logged in
		$loggedInUser = $this->userSession->getUser();
		if ($loggedInUser === null) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		$targetUser = $this->userManager->get($userId);
		if ($targetUser === null) {
			return new Result(null, API::RESPOND_NOT_FOUND);
		}

		if ($targetUser->getUID() === $loggedInUser->getUID() || $this->groupManager->isAdmin($loggedInUser->getUID())) {
			// Self lookup or admin lookup
			return new Result([
				'groups' => $this->groupManager->getUserGroupIds($targetUser, 'management')
			]);
		} else {
			$subAdminManager = $this->groupManager->getSubAdmin();

			// Looking up someone else
			if ($subAdminManager->isUserAccessible($loggedInUser, $targetUser)) {
				// Return the group that the method caller is subadmin of for the user in question
				$getSubAdminsGroups = $subAdminManager->getSubAdminsGroups($loggedInUser);
				foreach ($getSubAdminsGroups as $key => $group) {
					$getSubAdminsGroups[$key] = $group->getGID();
				}
				$groups = \array_intersect(
					$getSubAdminsGroups,
					$this->groupManager->getUserGroupIds($targetUser)
				);
				return new Result(['groups' => $groups]);
			} else {
				// Not permitted
				return new Result(null, 997);
			}
		}
	}

	/**
	 * Returns whether the given user can manage the given group
	 *
	 * @param IUser $user user to check access
	 * @param IGroup|null $group group to check or null
	 *
	 * @return true if the user can manage the group
	 */
	private function canUserManageGroup($user, $group) {
		if ($this->groupManager->isAdmin($user->getUID())) {
			return true;
		}

		if ($group !== null) {
			$subAdminManager = $this->groupManager->getSubAdmin();
			return $subAdminManager->isSubAdminofGroup($user, $group);
		}

		return false;
	}

	/**
	 * @param IUser $user
	 * @return bool
	 */
	private function isSubAdmin(IUser $user) {
		if ($user === null) {
			return false;
		}
		$uid = $user->getUID();
		if (
			$this->groupManager->isAdmin($uid)
			|| $this->groupManager->getSubAdmin()->isSubAdmin($user)
		) {
			return true;
		}

		return false;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function addToGroup($userId) {
		// Check if user is logged in
		$user = $this->userSession->getUser();
		if ($this->isSubAdmin($user) === false) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		$groupId = $this->request->getParam('groupid', null);
		if (($groupId === '') || ($groupId === null) || ($groupId === false)) {
			return new Result(null, 101);
		}

		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return new Result(null, 102);
		}

		if (!$this->groupManager->isAdmin($user->getUID())) {
			return new Result(null, 104);
		}

		$targetUser = $this->userManager->get($userId);
		if ($targetUser === null) {
			return new Result(null, 103);
		}

		// Add user to group
		$group->addUser($targetUser);
		return new Result(null, 100);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function removeFromGroup($userId) {
		// Check if user is logged in
		$loggedInUser = $this->userSession->getUser();
		if ($this->isSubAdmin($loggedInUser) === false) {
			return new Result(null, API::RESPOND_UNAUTHORISED);
		}

		$groupId = $this->request->getParam('groupid', null);
		if (($groupId === '') || ($groupId === null) || ($groupId === false)) {
			return new Result(null, 101);
		}

		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return new Result(null, 102);
		}

		if (!$this->canUserManageGroup($loggedInUser, $group)) {
			return new Result(null, 104);
		}

		$targetUser = $this->userManager->get($userId);
		if ($targetUser === null) {
			return new Result(null, 103);
		}
		// Check they aren't removing themselves from 'admin' or their 'subadmin; group
		if ($userId === $loggedInUser->getUID()) {
			if ($this->groupManager->isAdmin($loggedInUser->getUID())) {
				if ($group->getGID() === 'admin') {
					return new Result(null, 105, 'Cannot remove yourself from the admin group');
				}
			} else {
				// Not an admin, check they are not removing themself from their subadmin group
				$subAdminManager = $this->groupManager->getSubAdmin();
				$subAdminGroups = $subAdminManager->getSubAdminsGroups($loggedInUser);
				foreach ($subAdminGroups as $key => $group) {
					$subAdminGroups[$key] = $group->getGID();
				}

				if (\in_array($group->getGID(), $subAdminGroups, true)) {
					return new Result(null, 105, 'Cannot remove yourself from this group as you are a SubAdmin');
				}
			}
		}

		// Remove user from group
		$group->removeUser($targetUser);
		return new Result(null, 100);
	}

	/**
	 * @NoCSRFRequired
	 *
	 * Creates a subadmin
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function addSubAdmin($userId) {
		$groupId = $this->request->getParam('groupid');
		$group = $this->groupManager->get($groupId);
		$user = $this->userManager->get($userId);

		// Check if the user exists
		if ($user === null) {
			return new Result(null, 101, 'User does not exist');
		}
		// Check if group exists
		if ($group === null) {
			return new Result(null, 102, "Group:$groupId does not exist");
		}
		// Check if trying to make subadmin of admin group
		if (\strtolower($groupId) === 'admin') {
			return new Result(null, 103, 'Cannot create subadmins for admin group');
		}

		$subAdminManager = $this->groupManager->getSubAdmin();

		// We cannot be subadmin twice
		if ($subAdminManager->isSubAdminofGroup($user, $group)) {
			return new Result(null, 100);
		}
		// Go
		if ($subAdminManager->createSubAdmin($user, $group)) {
			return new Result(null, 100);
		} else {
			return new Result(null, 103, 'Unknown error occurred');
		}
	}

	/**
	 * @NoCSRFRequired
	 *
	 * Removes a subadmin from a group
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function removeSubAdmin($userId) {
		$groupId = $this->request->getParam('groupid', null);
		$group = $this->groupManager->get($groupId);
		$user = $this->userManager->get($userId);
		$subAdminManager = $this->groupManager->getSubAdmin();

		// Check if the user exists
		if ($user === null) {
			return new Result(null, 101, 'User does not exist');
		}
		// Check if the group exists
		if ($group === null) {
			return new Result(null, 101, 'Group does not exist');
		}
		// Check if they are a subadmin of this said group
		if (!$subAdminManager->isSubAdminofGroup($user, $group)) {
			return new Result(null, 102, 'User is not a subadmin of this group');
		}

		// Go
		if ($subAdminManager->deleteSubAdmin($user, $group)) {
			return new Result(null, 100);
		} else {
			return new Result(null, 103, 'Unknown error occurred');
		}
	}

	/**
	 * @NoCSRFRequired
	 *
	 * Get the groups a user is a subadmin of
	 *
	 * @param string $userId
	 * @return Result
	 */
	public function getUserSubAdminGroups($userId) {
		$user = $this->userManager->get($userId);
		// Check if the user exists
		if ($user === null) {
			return new Result(null, 101, 'User does not exist');
		}

		// Get the subadmin groups
		$groups = $this->groupManager->getSubAdmin()->getSubAdminsGroups($user);
		foreach ($groups as $key => $group) {
			$groups[$key] = $group->getGID();
		}

		if (!$groups) {
			return new Result(null, 102, 'Unknown error occurred');
		} else {
			return new Result($groups);
		}
	}

	/**
	 * @param string $userId
	 * @return array
	 * @throws \OCP\Files\NotFoundException
	 */
	protected function fillStorageInfo($userId) {
		try {
			\OC_Util::tearDownFS();
			\OC_Util::setupFS($userId);
			$storage = OC_Helper::getStorageInfo('/');
			$data = [
				'free' => $storage['free'],
				'used' => $storage['used'],
				'total' => $storage['total'],
				'relative' => $storage['relative'],
			];
		} catch (NotFoundException $ex) {
			$data = [];
		}

		return $data;
	}
}
