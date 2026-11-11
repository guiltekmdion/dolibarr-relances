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
 * \file    dunning/core/modules/societe/doc/pdf_dunningstatement.modules.php
 * \ingroup dunning
 * \brief   Statement of account of a customer: all its invoices to pay and their age
 */

dol_include_once('/dunning/core/modules/societe/doc/pdf_dunningnotice.modules.php');


/**
 * Statement of account of a customer: a document of the thirdparty (tab Documents), that a reminder level can attach to its email
 */
class pdf_dunningstatement extends pdf_dunningnotice
{
	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		parent::__construct($db);
		$this->name = 'dunningstatement';
		$this->description = $langs->trans('DunningStatementModel');
		$this->statement = true;
	}
}
