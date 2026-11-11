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
 * \file    dunning/core/substitutions/functions_dunning.lib.php
 * \ingroup dunning
 * \brief   Keys __DUNNING_*__ in the editor of the email templates and in the email form of an invoice
 */


/**
 * Keys of the module for the email forms: declared in the editor of the templates, with their values in the email form of an invoice.
 * Nothing for the other callers (PDF, ODT, jobs): the reminders of the module set these keys themselves.
 *
 * @param	array<string,mixed>		$substitutionarray	Keys to complete
 * @param	Translate				$outputlangs		Language of the output
 * @param	?CommonObject			$object				Object of the email, null in the editor of the templates
 * @param	?array<string,mixed>	$parameters			Parameters of the caller, mode 'formemail' for the email form and the editor
 * @return	void
 */
function dunning_completesubstitutionarray(&$substitutionarray, $outputlangs, $object, $parameters = null)
{
	global $db, $user;

	if (!is_array($parameters) || ($parameters['mode'] ?? '') !== 'formemail') {
		return;
	}
	$outputlangs->load('dunning@dunning');
	$keys = array('__DUNNING_LEVEL__' => 'DunningKeyLevel', '__DUNNING_DAYS_LATE__' => 'DunningKeyDaysLate', '__DUNNING_INVOICES__' => 'DunningKeyInvoices',
		'__DUNNING_TOTAL_REMAIN__' => 'DunningKeyTotalRemain', '__DUNNING_PENALTIES__' => 'DunningKeyPenalties', '__DUNNING_INDEMNITY__' => 'DunningKeyIndemnity',
		'__DUNNING_TOTAL_WITH_PENALTIES__' => 'DunningKeyTotalWithPenalties', '__DUNNING_PENALTIES_TEXT__' => 'DunningKeyPenaltiesText');
	foreach ($keys as $key => $label) {	// Their description in the editor of the templates, else empty unless an invoice gives the values
		$substitutionarray[$key] = (is_object($object) ? '' : $outputlangs->trans($label));
	}
	if (!is_object($object) || $object->element != 'facture' || !$user->hasRight('dunning', 'read') || !$user->hasRight('facture', 'lire') || $user->socid > 0) {
		return;
	}

	// Email form of an invoice: the values of its next reminder (or of the last one sent), empty if it is not reminded
	dol_include_once('/dunning/class/dunning.class.php');
	$dunning = new Dunning($db);
	$now = dol_now();
	$rows = $dunning->getInvoices($user, $now, array(), 0, (int) $object->id);
	$row = (is_array($rows) ? ($rows[$object->id] ?? null) : null);
	$values = ($row ? $dunning->getSubstitutions(array($row), $row->next ?: ($row->last ?: (object) array('label' => '')), $outputlangs, $now) : array());
	foreach (array_keys($keys) as $key) {
		$substitutionarray[$key] = $values[$key] ?? '';
	}
}
