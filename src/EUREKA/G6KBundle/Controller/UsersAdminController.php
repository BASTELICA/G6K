<?php

/*
The MIT License (MIT)

Copyright (c) 2015 Jacques Archimède

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

namespace EUREKA\G6KBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use Silex\Application;
use Binfo\Silex\MobileDetectServiceProvider;

class UsersAdminController extends BaseAdminController {

	public function indexAction(Request $request, $crud = null)
	{
		$form = $request->request->all();
		$no_js = $request->query->get('no-js') || 0;
		$script = $no_js == 1 ? 0 : 1;
		
		if ($crud !== null) {
			if (! $this->get('security.context')->isGranted('ROLE_SUPER_ADMIN')) {
				return $this->errorResponse($form, "Access denied!");
			}
			switch ($crud) {
				case 'add':
					return $this->addUser ($form);
				case 'update':
					return $this->updateUser ($form);
				case 'delete':
					return $this->deleteUser ($form);
			}
		} else if (! $this->get('security.context')->isGranted('ROLE_SUPER_ADMIN')) {
			throw $this->AccessDeniedException ($this->get('translator')->trans("Access Denied!"));
		} else {
			$userManager = $this->get('fos_user.user_manager');
			$users = $userManager->findUsers();
	
		 	$hiddens = array();		
			$hiddens['script'] = $script;
			$silex = new Application();
			$silex->register(new MobileDetectServiceProvider());
			try {
				return $this->render(
					'EUREKAG6KBundle:admin/pages:users.html.twig',
					array(
						'ua' => $silex["mobile_detect"],
						'path' => $request->getScheme().'://'.$request->getHttpHost(),
						'nav' => 'users',
						'users' => $users,
						'hiddens' => $hiddens
					)
				);
			} catch (\Exception $e) {
				echo $e->getMessage();
				throw $this->createNotFoundException($this->get('translator')->trans("This template does not exist"));
			}
		}
	}

	protected function addUser ($form) {
		$userManager = $this->get('fos_user.user_manager');
		$userName = $form['userName'];
		$email = $form['email'];
		$password = $form['password'];
		$enabled = isset($form['enabled']) ? $form['enabled'] == 1 : false;
		$locked = isset($form['locked']) ? $form['locked'] == 1 : false;
		$expired = isset($form['expired']) ? $form['expired'] == 1 : false;
		$expiresAt = isset($form['expiresAt']) && $form['expiresAt'] != "" ? $this->parseDate('d/m/Y', $form['expiresAt']) : null;
		$credentialsExpired = isset($form['credentialsExpired']) ? $form['credentialsExpired'] == 1 : false;
		$credentialExpireAt = isset($form['credentialExpireAt']) && $form['credentialExpireAt'] != "" ? $this->parseDate('d/m/Y', $form['credentialExpireAt']) : null;
		$roles = isset($form['roles']) ? $form['roles'] : array() ;
		if ($userName == "" || strlen($userName)  < 3) {
			return $this->errorResponse($form, "The username field is required  (3 car .min)!");
		}
		if ($email == "") {
			return $this->errorResponse($form, "The email field is required!");
		}
		if (!preg_match("/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/", $email)) {
			return $this->errorResponse($form, "Please enter a valid email address.");
		}
		if ($password == "" || strlen($password)  < 6) {
			return $this->errorResponse($form, "The password field is required (6 car. min)!");
		}
		$user = $userManager->findUserByUsername($userName);
		if ($user !== null) {
			return $this->errorResponse($form, "This username already exists !");
		}
		$user = $userManager->findUserByEmail($email);
		if ($user !== null) {
			return $this->errorResponse($form, "This email already exists !");
		}
		$user = $userManager->createUser();
		$user->setUsername($userName);
		$user->setEmail($email);
		$user->setPlainPassword($password);
		$user->setEnabled($enabled);
		$user->setLocked($locked);
		$user->setExpired($expired);
		$user->setExpiresAt($expiresAt);
		$user->setCredentialsExpired($credentialsExpired);
		$user->setCredentialsExpireAt($credentialExpireAt);
		foreach ($roles as $role) {
			$user->addRole($role);
		}
		$userManager->updateUser($user);
		$form['id'] = $user->getId();
		$response = new Response();
		$response->setContent(json_encode($form));
		$response->headers->set('Content-Type', 'application/json');
		return $response;
	}

	protected function updateUser ($form) {
		$id = $form['id'];
		if ($id == 0) {
			return $this->addUser ($form);
		}
		$userManager = $this->get('fos_user.user_manager');
		$user = $userManager->findUserBy(array('id' => $id));
		if ($user === null) {
			return $this->errorResponse($form, "This user doesn't  exists !");
		}
		$userName = $form['userName'];
		$email = $form['email'];
		$password = $form['password'];
		$enabled = isset($form['enabled']) ? $form['enabled'] == 1 : false;
		$locked = isset($form['locked']) ? $form['locked'] == 1 : false;
		$expired = isset($form['expired']) ? $form['expired'] == 1 : false;
		$expiresAt = isset($form['expiresAt']) && $form['expiresAt'] != "" ? $this->parseDate('d/m/Y', $form['expiresAt']) : null;
		$credentialsExpired = isset($form['credentialsExpired']) ? $form['credentialsExpired'] == 1 : false;
		$credentialExpireAt = isset($form['credentialExpireAt']) && $form['credentialExpireAt'] != "" ? $this->parseDate('d/m/Y', $form['credentialExpireAt']) : null;
		$roles = isset($form['roles']) ? $form['roles'] : array() ;
		if ($userName != $user->getUserName() && ($userName== "" || strlen($userName)  < 3)) {
			return $this->errorResponse($form, "The username field is required  (3 car .min)!");
		}
		if ($email == "") {
			return $this->errorResponse($form, "The email field is required!");
		}
		if (!preg_match("/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/", $email)) {
			return $this->errorResponse($form, "Please enter a valid email address.");
		}
		if ($password == "" || strlen($password)  < 6) {
			return $this->errorResponse($form, "The password field is required (6 car. min)!");
		}
		if ($userName != $user->getUserName()) {
			$otherUser = $userManager->findUserByUsername($userName);
			if ($otherUser !== null) {
				return $this->errorResponse($form, "This username already exists !");
			}
		}
		if ($email != $user->getEmail()) {
			$otherUser = $userManager->findUserByEmail($email);
			if ($otherUser !== null) {
				return $this->errorResponse($form, "This email already exists !");
			}
		}
		$user->setUsername($userName);
		$user->setEmail($email);
		if ($password != $user->getPassword()) {
			$user->setPlainPassword($password);
		}
		$user->setEnabled($enabled);
		$user->setLocked($locked);
		$user->setExpired($expired);
		$user->setExpiresAt($expiresAt);
		$user->setCredentialsExpired($credentialsExpired);
		$user->setCredentialsExpireAt($credentialExpireAt);
		$oldRoles = $user->getRoles();
		foreach ($roles as $role) {
			if (!in_array($role, $oldRoles)) {
				$user->addRole($role);
			}
		}		
		foreach ($oldRoles as $role) {
			if (!in_array($role, $roles)) {
				$user->removeRole($role);
			}
		}		
		$userManager->updateUser($user);
		$form['password'] = $user->getPassword();
		$response = new Response();
		$response->setContent(json_encode($form));
		$response->headers->set('Content-Type', 'application/json');
		return $response;
	}

	protected function deleteUser ($form) {
		$id = $form['id'];
		$userManager = $this->get('fos_user.user_manager');
		$user = $userManager->findUserBy(array('id' => $id));
		if ($user === null) {
			return $this->errorResponse($form, "This user doesn't  exists !");
		}
		$userManager->deleteUser($user);
		$response = new Response();
		$response->setContent(json_encode($form));
		$response->headers->set('Content-Type', 'application/json');
		return $response;
	}
	
}