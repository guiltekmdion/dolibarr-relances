<?php
/* Copyright (C) 2026		Maxime DION				<m.dion@guiltek.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/**
 * \file    dunning/class/actions_dunning.class.php
 * \ingroup dunning
 * \brief   Hooks of the module Reminders: the customers to remind on the dashboard of the home page
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';


/**
 * Hooks of the module Reminders (context index)
 */
class ActionsDunning extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Line of the dashboard: customers with a reminder to send, for the users who can act on the reminders
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	stdClass			$object			Not used
	 * @param	string				$action			Not used
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int								0
	 */
	public function addOpenElementsDashboardLine($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		if (!getDolGlobalString('MAIN_DISABLE_BLOCK_CUSTOMER') && $user->hasRight('dunning', 'write') && $user->hasRight('facture', 'lire')) {
			dol_include_once('/dunning/class/dunning.class.php');
			$board = new Dunning($this->db);
			$this->results = array('dunning' => $board->load_board($user));
		}

		return 0;
	}

	/**
	 * Show the line of the dashboard in the group Invoices
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters, with dashboardgroup
	 * @param	stdClass			$object			Not used
	 * @param	string				$action			Not used
	 * @param	HookManager			$hookmanager	Hook manager
	 * @return	int								0
	 */
	public function addOpenElementsDashboardGroup($parameters, &$object, &$action, $hookmanager)
	{
		if (isset($parameters['dashboardgroup']['facture']['stats'])) {	// The whole group is returned: the caller replaces it
			$group = $parameters['dashboardgroup']['facture'];
			$group['stats'][] = 'dunning';
			$this->results = array('facture' => $group);
		}

		return 0;
	}
}
