<?php
namespace TYPO3\TYPO3\Command;

/*                                                                        *
 * This script belongs to the FLOW3 package "TYPO3".                      *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * The TYPO3 Setup
 *
 * @scope singleton
 */
class SetupCommandController extends \TYPO3\FLOW3\MVC\Controller\CommandController {

	/**
	 * @inject
	 * @var \TYPO3\FLOW3\Security\AccountRepository
	 */
	protected $accountRepository;

	/**
	 * @inject
	 * @var \TYPO3\Party\Domain\Repository\PartyRepository
	 */
	protected $partyRepository;

	/**
	 * @inject
	 * @var \TYPO3\FLOW3\Security\AccountFactory
	 */
	protected $accountFactory;

	/**
	 * Create users with the Administrator role.
	 *
	 * @param string $identifier Identifier (username) of the account to be created
	 * @param string $password Password of the account to be created
	 * @return void
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 * @author Bastian Waidelich <bastian@typo3.org>
	 */
	public function createAdministratorCommand($identifier, $password) {
		$user = new \TYPO3\TYPO3\Domain\Model\User();
		$user->getPreferences()->set('context.workspace', 'user-' . $identifier);
		$this->partyRepository->add($user);

		$account = $this->accountFactory->createAccountWithPassword($identifier, $password, array('Administrator'), 'Typo3BackendProvider');
		$account->setParty($user);
		$this->accountRepository->add($account);
		$this->outputLine('Created account "%s".', array($identifier));
	}

}
?>