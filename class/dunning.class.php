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
 * \file    dunning/class/dunning.class.php
 * \ingroup dunning
 * \brief   Engine of the reminders of unpaid customer invoices: levels, selection, sending, promises
 */

// All includes are here: the scheduled job only loads master.inc.php
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';


/**
 * Reminders of unpaid customer invoices by levels.
 * The agenda events linked to the invoices are the memory of the reminders sent and of the payment promises.
 */
class Dunning
{
	/**
	 * Code of the event of a reminder sent, followed by the rowid of its level and CODE_SENT_SUFFIX
	 */
	const CODE_PREFIX = 'AC_DUNNING_';

	/**
	 * End of the code of the event of a reminder sent: the agenda shows it as an email
	 */
	const CODE_SENT_SUFFIX = '_SENTBYMAIL';

	/**
	 * Code of the event of a payment promise
	 */
	const CODE_PROMISE = 'AC_DUNNING_PROMISE';

	/**
	 * Code of the event recorded on an invoice when its penalty invoice is created, with the id of the penalty invoice in its extraparams
	 */
	const CODE_PENALTY_INVOICE = 'AC_DUNNING_PENALTYINV';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var int Entity, set by the scheduled job
	 */
	public $entity;

	/**
	 * @var string Error
	 */
	public $error = '';

	/**
	 * @var string[] Errors of the last sending, one per email not sent
	 */
	public $errors = array();

	/**
	 * @var string Output of the last sending
	 */
	public $output = '';

	/**
	 * @var array<string,CEmailTemplate|ModelMail|int> Email templates by level and language, searched once per run
	 */
	private $templates = array();


	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Load the levels, active or not (a disabled level already sent keeps its rank)
	 *
	 * @return	array<int,stdClass>|int<-1,-1>	Levels by rowid sorted by number of days (template_label is empty if the template was deleted), -1 if error
	 */
	public function fetchLevels()
	{
		$sql = "SELECT l.rowid, l.label, l.nbdays, l.fk_c_type_contact, l.percustomer, l.byhand, l.joinnotice, l.fk_email_template, l.active, t.label as template_label";
		$sql .= " FROM ".$this->db->prefix()."dunning_level as l";
		$sql .= " LEFT JOIN ".$this->db->prefix()."c_email_templates as t ON t.rowid = l.fk_email_template";
		$sql .= " WHERE l.entity IN (".getEntity('dunning').")";
		$sql .= $this->db->order('l.nbdays', 'ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		$levels = array();
		while ($obj = $this->db->fetch_object($resql)) {
			foreach (array('rowid', 'nbdays', 'fk_c_type_contact', 'percustomer', 'byhand', 'joinnotice', 'fk_email_template', 'active') as $field) {
				$obj->$field = (int) $obj->$field;
			}
			$obj->template_label = (string) $obj->template_label;
			$levels[$obj->rowid] = $obj;
		}

		return $levels;
	}

	/**
	 * Create or update a level of the invoices. A level is never deleted but disabled: the events of its reminders keep its rowid.
	 *
	 * @param	User	$user		User saving, must be an administrator
	 * @param	array{rowid:int,label:string,nbdays:int,fk_c_type_contact:int,percustomer:int,byhand?:int,joinnotice?:int,fk_email_template:int,active:int}	$level	Values of the level, rowid 0 to create it
	 * @return	int		Rowid of the level if OK, <0 if KO
	 * @see fetchLevels()
	 */
	public function saveLevel(User $user, array $level)
	{
		global $conf, $langs;

		$langs->loadLangs(array('admin', 'bills', 'dunning@dunning'));
		if (!$user->admin) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		if ($level['label'] === '' || $level['fk_email_template'] <= 0) {
			$this->error = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv($level['label'] === '' ? 'Label' : 'EmailTemplate'));
			return -1;
		}

		$values = array(
			'label' => "'".$this->db->escape(dol_trunc($level['label'], 128, 'right', 'UTF-8', 1))."'",
			'nbdays' => (int) $level['nbdays'],
			'fk_c_type_contact' => (int) $level['fk_c_type_contact'],
			'percustomer' => (int) $level['percustomer'],
			'byhand' => (int) ($level['byhand'] ?? 0),
			'joinnotice' => (int) ($level['joinnotice'] ?? 0),
			'fk_email_template' => (int) $level['fk_email_template'],
			'active' => (int) $level['active'],
		);
		// The same days as another level: refused before the unique key, whose error would abort the transaction with PostgreSQL
		$sql = "SELECT rowid FROM ".$this->db->prefix()."dunning_level WHERE entity = ".((int) $conf->entity);
		$sql .= " AND nbdays = ".((int) $level['nbdays'])." AND rowid <> ".((int) $level['rowid']);
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql)) {
			$this->error = ($resql ? $langs->trans('DunningErrorSameDays') : $this->db->lasterror());
			return -1;
		}
		if ($level['rowid'] > 0) {
			$sets = array();
			foreach ($values as $field => $value) {
				$sets[] = $field." = ".$value;
			}
			$sql = "UPDATE ".$this->db->prefix()."dunning_level SET ".implode(', ', $sets)." WHERE rowid = ".((int) $level['rowid'])." AND entity = ".((int) $conf->entity);
		} else {
			$sql = "INSERT INTO ".$this->db->prefix()."dunning_level (entity, ".implode(', ', array_keys($values)).")";
			$sql .= " VALUES (".((int) $conf->entity).", ".implode(', ', $values).")";
		}
		if (!$this->db->query($sql)) {
			$this->error = ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS' ? $langs->trans('DunningErrorSameDays') : $this->db->lasterror());
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		return ($level['rowid'] > 0 ? (int) $level['rowid'] : $this->db->last_insert_id($this->db->prefix().'dunning_level'));
	}

	/**
	 * State of the reminders of one invoice. No access to the database.
	 * The next level is the first active level after the most advanced level sent: never a jump back, never two levels the same day,
	 * never a level before the due date once the invoice is due.
	 *
	 * @param	array<int,stdClass>	$levels		Levels by rowid sorted by number of days, as returned by fetchLevels()
	 * @param	int					$refdate	Reference date of the invoice (due date)
	 * @param	array<int,int>		$sent		Date of the reminder sent for each level, by rowid of level
	 * @param	int					$promise	Date of the payment promise, 0 if none
	 * @param	int					$startdate	Levels reached before this date are not sent automatically
	 * @param	int					$today		First hour of the day of the reading
	 * @return	stdClass			Object with last (level), lastdate, next (level), nextdate and auto (next level can be sent by the job: reached after the start date and not by hand)
	 */
	public function computeState(array $levels, $refdate, array $sent, $promise, $startdate, $today)
	{
		$state = (object) array('last' => null, 'lastdate' => 0, 'next' => null, 'nextdate' => 0, 'auto' => false);
		foreach ($sent as $levelid => $datep) {
			if (isset($levels[$levelid]) && (!$state->last || $levels[$levelid]->nbdays > $state->last->nbdays)) {
				$state->last = $levels[$levelid];
				$state->lastdate = $datep;
			}
		}
		foreach ($levels as $level) {
			if ($level->active && (!$state->last || $level->nbdays > $state->last->nbdays) && ($level->nbdays >= 0 || $refdate > $today)) {
				$state->next = $level;
				break;
			}
		}
		if ($state->next) {
			$leveldate = dol_time_plus_duree($refdate, $state->next->nbdays, 'd', 0, 'tzserver');
			$state->nextdate = $leveldate;
			if ($state->last) {	// Wait for the gap between the two levels
				$state->nextdate = max($state->nextdate, dol_time_plus_duree(dol_get_first_hour($state->lastdate), $state->next->nbdays - $state->last->nbdays, 'd', 0, 'tzserver'));
			}
			if ($promise) {		// Nothing up to the promised day
				$state->nextdate = max($state->nextdate, dol_time_plus_duree(dol_get_first_hour($promise), 1, 'd', 0, 'tzserver'));
			}
			$state->auto = ($leveldate >= $startdate && empty($state->next->byhand));
		}

		return $state;
	}

	/**
	 * Unpaid customer invoices in the circuit of the reminders, with the state of their reminders.
	 * A missed day is caught up: an invoice is due when the date of its next level is today or before.
	 *
	 * @param	User		$user		User reading, a user without the right to see all customers only gets its own customers
	 * @param	int			$now		Date of the reading
	 * @param	int[]		$socids		Only the invoices of these thirdparties, all if empty
	 * @param	int			$saleid		Only the customers of this sales representative
	 * @param	int			$invoiceid	Only this invoice, even if no level is reached yet
	 * @return	array<int,stdClass>|int<-1,-1>	Invoices by id (see computeState() for the state, plus promise and due), -1 if error
	 */
	public function getInvoices(User $user, $now, array $socids = array(), $saleid = 0, $invoiceid = 0)
	{
		global $conf, $langs;

		if (!$user->hasRight('dunning', 'read') || !$user->hasRight('facture', 'lire') || $user->socid > 0) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		if (!$user->hasRight('societe', 'client', 'voir')) {
			$saleid = $user->id;
		}
		$levels = $this->fetchLevels();
		if (!is_array($levels)) {
			return -1;
		}
		$first = reset($levels);	// Fewest days, active or not: computeState() chooses the next level
		if (!$first) {
			return array();
		}
		$today = dol_get_first_hour($now);
		$startdate = dol_get_first_hour(getDolGlobalInt('DUNNING_START_DATE') ?: $now);

		// Amounts paid, credit notes and deposits used, like Societe::getOutstandingBills()
		$sql = "SELECT f.rowid, f.ref, f.type, f.fk_soc, f.fk_projet, COALESCE(f.date_lim_reglement, f.datef) as refdate, f.total_ttc, f.last_main_doc,";
		$sql .= " f.multicurrency_code, f.multicurrency_tx, f.multicurrency_total_ttc,";
		$sql .= " f.retained_warranty, f.retained_warranty_date_limit,";
		$sql .= " s.nom as socname, s.email, s.default_lang, s.tva_intra, s.siren, s.siret, s.ape, s.idprof4, s.idprof5, s.idprof6, s.euid, te.code as typent_code,";
		if (getDolGlobalInt('DUNNING_NOPENALTY_CATEGORY') > 0) {	// Customers who never pay penalties
			$sql .= " (SELECT COUNT(cs.fk_soc) FROM ".$this->db->prefix()."categorie_societe as cs WHERE cs.fk_soc = f.fk_soc AND cs.fk_categorie = ".getDolGlobalInt('DUNNING_NOPENALTY_CATEGORY').") as nopenalty,";
		} else {
			$sql .= " 0 as nopenalty,";
		}
		$sql .= " (SELECT SUM(pf.amount) FROM ".$this->db->prefix()."paiement_facture as pf WHERE pf.fk_facture = f.rowid) as paid,";
		$sql .= " (SELECT SUM(rc.amount_ttc) FROM ".$this->db->prefix()."societe_remise_except as rc, ".$this->db->prefix()."facture as fs";
		$sql .= " WHERE rc.fk_facture_source = fs.rowid AND rc.fk_facture = f.rowid AND fs.type IN (".$this->db->sanitize(Facture::TYPE_STANDARD.", ".Facture::TYPE_CREDIT_NOTE.", ".Facture::TYPE_SITUATION).")) as creditnotes,";
		$sql .= " (SELECT SUM(rc.amount_ttc) FROM ".$this->db->prefix()."societe_remise_except as rc, ".$this->db->prefix()."facture as fs";
		$sql .= " WHERE rc.fk_facture_source = fs.rowid AND rc.fk_facture = f.rowid AND fs.type = ".((int) Facture::TYPE_DEPOSIT).") as deposits";
		$sql .= " FROM ".$this->db->prefix()."facture as f";
		$sql .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = f.fk_soc";
		$sql .= " LEFT JOIN ".$this->db->prefix()."c_typent as te ON te.id = s.fk_typent";
		$sql .= " WHERE f.entity IN (".getEntity('invoice', 0).")";	// One entity per run, like the reminder job of the core
		$sql .= " AND f.fk_statut = ".((int) Facture::STATUS_VALIDATED)." AND f.paye = 0";
		$sql .= " AND f.type IN (".$this->db->sanitize(Facture::TYPE_STANDARD.", ".Facture::TYPE_REPLACEMENT.", ".Facture::TYPE_DEPOSIT.", ".Facture::TYPE_SITUATION).")";
		$sql .= " AND COALESCE(f.dispute_status, 0) <> 1";
		if (getDolGlobalInt('DUNNING_EXCLUDED_CATEGORY') > 0) {	// Also when the module Tags is disabled later
			$sql .= " AND NOT EXISTS (SELECT cs.fk_soc FROM ".$this->db->prefix()."categorie_societe as cs";
			$sql .= " WHERE cs.fk_soc = f.fk_soc AND cs.fk_categorie = ".getDolGlobalInt('DUNNING_EXCLUDED_CATEGORY').")";
		}
		if ($invoiceid > 0) {
			$sql .= " AND f.rowid = ".((int) $invoiceid);
		} else {
			$sql .= " AND COALESCE(f.date_lim_reglement, f.datef) <= '".$this->db->idate(dol_time_plus_duree($today, -$first->nbdays, 'd', 0, 'tzserver'))."'";
		}
		if (!empty($socids)) {
			$sql .= " AND f.fk_soc IN (".$this->db->sanitize(implode(',', array_map('intval', $socids))).")";
		}
		if ($saleid > 0) {
			$sql .= " AND EXISTS (SELECT sc.fk_soc FROM ".$this->db->prefix()."societe_commerciaux as sc WHERE sc.fk_soc = f.fk_soc AND sc.fk_user = ".((int) $saleid).")";
		}
		$sql .= $this->db->order('refdate,f.rowid', 'ASC,ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		$rows = array();
		$companies = array();	// One answer per customer: the object of the core is heavy to build
		$decimals = getDolGlobalInt('MAIN_MAX_DECIMALS_TOT', 2);	// Rounding of price2num(..., 'MT'), which reads the language at each call: too slow for many invoices
		while ($obj = $this->db->fetch_object($resql)) {
			$paid = round((float) $obj->paid + (float) $obj->creditnotes + (float) $obj->deposits, $decimals);
			$remain = round((float) $obj->total_ttc - $paid, $decimals);
			$refdate = (int) $this->db->jdate($obj->refdate);
			if ($remain > 0 && (float) $obj->retained_warranty > 0 && $obj->retained_warranty_date_limit) {
				// Like Facture::hasDelay(), the retained warranty is due at its own date: the rest is reminded first, then the warranty from its date
				$warranty = $this->getRetainedWarranty($obj);
				$warrantydate = (int) $this->db->jdate($obj->retained_warranty_date_limit);
				if ($warranty > 0 && $remain <= $warranty) {
					$refdate = max($refdate, $warrantydate);
				} elseif ($warranty > 0 && $warrantydate > $today) {
					$remain = round($remain - $warranty, $decimals);
				}
			}
			if ($remain > 0) {
				$rows[(int) $obj->rowid] = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'socid' => (int) $obj->fk_soc, 'socname' => $obj->socname,
					'email' => (string) $obj->email, 'default_lang' => (string) $obj->default_lang, 'fk_projet' => (int) $obj->fk_projet,
					'last_main_doc' => (string) $obj->last_main_doc, 'refdate' => $refdate, 'total_ttc' => (float) $obj->total_ttc, 'paid' => $paid, 'remain' => $remain,
					'penaltysubject' => (!$obj->nopenalty && ($companies[$obj->fk_soc] = ($companies[$obj->fk_soc] ?? $this->isACompany($obj)))), 'penaltyinvoice' => 0,
					'currency' => $conf->currency, 'mc_rate' => 1.0, 'mc_total' => (float) $obj->total_ttc, 'mc_paid' => $paid, 'mc_remain' => $remain);
				if ($obj->multicurrency_code && $obj->multicurrency_code != $conf->currency && (float) $obj->multicurrency_tx > 0) {	// Amounts in the currency of the invoice
					$rows[(int) $obj->rowid] = array_merge($rows[(int) $obj->rowid], array('currency' => $obj->multicurrency_code, 'mc_rate' => (float) $obj->multicurrency_tx,
						'mc_total' => (float) $obj->multicurrency_total_ttc, 'mc_remainbefore' => round((float) $obj->total_ttc - $paid, $decimals)));
				}
			}
		}
		if ($this->setForeignAmounts($rows, $decimals) < 0) {
			return -1;
		}
		if (empty($rows)) {
			return array();
		}

		$events = $this->getEvents(array_keys($rows));
		if (!is_array($events)) {
			return -1;
		}
		$sent = array();
		$promise = array();
		$promiseamount = array();
		foreach ($events as $event) {	// In the order they were recorded: the last one wins
			$reg = array();
			if ($event->code == self::CODE_PROMISE) {
				$promise[$event->fk_element] = $event->datep;
				$promiseamount[$event->fk_element] = array((float) (json_decode((string) $event->extraparams, true)['amount'] ?? 0), $event->datec);
			} elseif ($event->code == self::CODE_PENALTY_INVOICE) {
				$rows[$event->fk_element]['penaltyinvoice'] = (int) (json_decode((string) $event->extraparams, true)['invoice'] ?? 0);
			} elseif (preg_match('/^'.self::CODE_PREFIX.'(\d+)'.self::CODE_SENT_SUFFIX.'$/', $event->code, $reg)) {
				$sent[$event->fk_element][(int) $reg[1]] = $event->datep;
			}
		}
		if ($this->forgetDeletedPenaltyInvoices($rows) < 0) {
			return -1;
		}
		$promisestatus = $this->getPromiseStatus($promise, $promiseamount, $today);
		if (!is_array($promisestatus)) {
			return -1;
		}
		$result = array();
		foreach ($rows as $id => $row) {
			$state = $this->computeState($levels, $row['refdate'], $sent[$id] ?? array(), $promise[$id] ?? 0, $startdate, $today);
			$result[$id] = (object) array_merge($row, (array) $state, array('promise' => $promise[$id] ?? 0, 'due' => ($state->next && $state->nextdate <= $today),
				'promiseamount' => ($promiseamount[$id][0] ?? 0.0), 'promisestatus' => ($promisestatus[$id] ?? '')));
		}

		return $result;
	}

	/**
	 * Paid and remaining amounts in the currency of the invoices in a foreign currency, like CommonInvoice::getRemainToPay(1):
	 * payments, credit notes and deposits in this currency. One query, only when such invoices are reminded.
	 *
	 * @param	array<int,array<string,mixed>>	$rows		Invoices being read by getInvoices(), changed in place
	 * @param	int								$decimals	Decimals of the totals
	 * @return	int											0 if OK, -1 if error
	 */
	private function setForeignAmounts(array &$rows, $decimals)
	{
		$ids = array();
		foreach ($rows as $id => $row) {
			if (isset($row['mc_remainbefore'])) {
				$ids[] = $id;
			}
		}
		if (empty($ids)) {
			return 0;
		}
		$sql = "SELECT f.rowid, (SELECT SUM(pf.multicurrency_amount) FROM ".$this->db->prefix()."paiement_facture as pf WHERE pf.fk_facture = f.rowid) as paid,";
		$sql .= " (SELECT SUM(rc.multicurrency_amount_ttc) FROM ".$this->db->prefix()."societe_remise_except as rc, ".$this->db->prefix()."facture as fs";	// Credit notes and deposits used
		$sql .= " WHERE rc.fk_facture_source = fs.rowid AND rc.fk_facture = f.rowid";
		$sql .= " AND fs.type IN (".$this->db->sanitize(Facture::TYPE_STANDARD.", ".Facture::TYPE_CREDIT_NOTE.", ".Facture::TYPE_SITUATION.", ".Facture::TYPE_DEPOSIT).")) as discounts";
		$sql .= " FROM ".$this->db->prefix()."facture as f WHERE f.rowid IN (".$this->db->sanitize(implode(',', $ids)).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$row = &$rows[(int) $obj->rowid];
			$row['mc_paid'] = round((float) $obj->paid + (float) $obj->discounts, $decimals);
			$remain = round($row['mc_total'] - $row['mc_paid'], $decimals);
			// A retained warranty not due yet lowers the rest in the currency of the company: the same share in the currency of the invoice
			$row['mc_remain'] = ($row['mc_remainbefore'] > 0 && $row['remain'] < $row['mc_remainbefore'] ? round($remain * $row['remain'] / $row['mc_remainbefore'], $decimals) : $remain);
			unset($row['mc_remainbefore'], $row);
		}

		return 0;
	}

	/**
	 * A penalty invoice deleted by a user (a draft) can be created again: its event stays in the agenda, but the invoice is gone
	 *
	 * @param	array<int,array<string,mixed>>	$rows	Invoices being read by getInvoices(), changed in place
	 * @return	int										0 if OK, -1 if error
	 */
	private function forgetDeletedPenaltyInvoices(array &$rows)
	{
		$ids = array_filter(array_column($rows, 'penaltyinvoice'));
		if (empty($ids)) {
			return 0;
		}
		$resql = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."facture WHERE rowid IN (".$this->db->sanitize(implode(',', array_map('intval', $ids))).")");
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$existing = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$existing[(int) $obj->rowid] = 1;
		}
		foreach ($rows as $id => $row) {
			if ($row['penaltyinvoice'] && empty($existing[$row['penaltyinvoice']])) {
				$rows[$id]['penaltyinvoice'] = 0;
			}
		}

		return 0;
	}

	/**
	 * State of the promises with an amount: kept when the payments received between the promise and the end of the promised day reach it,
	 * not kept when the promised day is over without them, pending before
	 *
	 * @param	array<int,int>					$promise		Promised day by invoice
	 * @param	array<int,array{0:float,1:int}>	$promiseamount	Amount promised and date of the promise by invoice
	 * @param	int								$today			First hour of the day of the reading
	 * @return	array<int,string>|int<-1,-1>	'kept', 'notkept' or 'pending' by invoice with a promised amount, -1 if error
	 */
	private function getPromiseStatus(array $promise, array $promiseamount, $today)
	{
		$ids = array();
		foreach ($promiseamount as $id => $info) {
			if ($info[0] > 0) {
				$ids[] = $id;
			}
		}
		if (empty($ids)) {
			return array();
		}
		$sql = "SELECT pf.fk_facture, p.datep, pf.amount FROM ".$this->db->prefix()."paiement_facture as pf";
		$sql .= " INNER JOIN ".$this->db->prefix()."paiement as p ON p.rowid = pf.fk_paiement";
		$sql .= " WHERE pf.fk_facture IN (".$this->db->sanitize(implode(',', $ids)).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$paid = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$id = (int) $obj->fk_facture;
			$datep = (int) $this->db->jdate($obj->datep);
			$end = dol_time_plus_duree(dol_get_first_hour($promise[$id]), 1, 'd', 0, 'tzserver');
			if ($datep >= dol_get_first_hour($promiseamount[$id][1]) && $datep < $end) {
				$paid[$id] = ($paid[$id] ?? 0) + (float) $obj->amount;
			}
		}
		$status = array();
		foreach ($ids as $id) {
			$kept = ((float) price2num($paid[$id] ?? 0, 'MT') >= $promiseamount[$id][0]);
			$status[$id] = ($kept ? 'kept' : ($today > dol_get_first_hour($promise[$id]) ? 'notkept' : 'pending'));
		}

		return $status;
	}

	/**
	 * Customers in the circuit of the reminders: one line per customer with an invoice whose level was sent, is due now, or is promised.
	 * The list of the customers to remind and the dashboard both count with it.
	 *
	 * @param	User	$user		User reading, see getInvoices()
	 * @param	int		$now		Date of the reading
	 * @param	int		$saleid		Only the customers of this sales representative
	 * @param	int		$dueonly	1 = only the customers with a reminder to send
	 * @return	array<int,array{socid:int,socname:string,nb:int,remain:float,late30:float,level:?stdClass,leveldate:int,promise:int,promisestatus:string,next:?stdClass,nextdate:int,due:bool,byhand:bool}>|int<-1,-1>	Customers by id, -1 if error
	 */
	public function getCustomers(User $user, $now, $saleid = 0, $dueonly = 0)
	{
		$rows = $this->getInvoices($user, $now, array(), $saleid);
		if (!is_array($rows)) {
			return -1;
		}
		$late30 = dol_time_plus_duree(dol_get_first_hour($now), -30, 'd', 0, 'tzserver');
		$customers = array();
		foreach ($rows as $row) {
			if (!$row->last && !$row->due && !$row->promise) {
				continue;
			}
			$customer = $customers[$row->socid] ?? array('socid' => $row->socid, 'socname' => $row->socname, 'nb' => 0, 'remain' => 0.0, 'late30' => 0.0,
				'level' => null, 'leveldate' => 0, 'promise' => 0, 'promisestatus' => '', 'next' => null, 'nextdate' => 0, 'due' => false, 'byhand' => false);
			$customer['nb']++;
			$customer['remain'] += $row->remain;
			$customer['late30'] += ($row->refdate < $late30 ? $row->remain : 0);
			if ($row->last && (!$customer['level'] || $row->last->nbdays > $customer['level']->nbdays)) {
				$customer['level'] = $row->last;
				$customer['leveldate'] = $row->lastdate;
			}
			if ($row->next && (!$customer['next'] || $row->nextdate < $customer['nextdate'])) {
				$customer['next'] = $row->next;
				$customer['nextdate'] = $row->nextdate;
			}
			$customer['promise'] = max($customer['promise'], $row->promise);
			foreach (array('notkept', 'pending', 'kept') as $status) {	// The worst state of the promises of the customer
				if ($row->promisestatus == $status || $customer['promisestatus'] == $status) {
					$customer['promisestatus'] = $status;
					break;
				}
			}
			$customer['due'] = ($customer['due'] || $row->due);
			$customer['byhand'] = ($customer['byhand'] || ($row->due && !$row->auto));
			$customers[$row->socid] = $customer;
		}
		foreach ($customers as $socid => $customer) {
			if ($dueonly && !$customer['due']) {
				unset($customers[$socid]);
			}
		}

		return $customers;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Indicator of the dashboard: customers with a reminder to send, late when it waits since before today (a level by hand, or an error)
	 *
	 * @param	User	$user	User reading, who must be allowed to act on the reminders
	 * @return	WorkboardResponse|int<-1,-1>	Indicator, -1 if KO
	 * @see getCustomers()
	 */
	public function load_board(User $user)
	{
		// phpcs:enable
		global $langs;

		if (!$user->hasRight('dunning', 'write')) {
			return -1;
		}
		$now = dol_now();
		$customers = $this->getCustomers($user, $now, 0, 1);
		if (!is_array($customers)) {
			return -1;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/workboardresponse.class.php';
		$langs->load('dunning@dunning');
		$response = new WorkboardResponse();
		$response->warning_delay = 0;
		$response->label = $langs->trans('DunningCustomersToRemind');
		$response->url = dol_buildpath('/dunning/list.php', 1).'?search_due=1&mainmenu=billing&leftmenu=dunning';
		$response->url_late = $response->url;
		$response->img = img_object('', 'bill');
		$response->nbtodo = count($customers);
		$today = dol_get_first_hour($now);
		foreach ($customers as $customer) {
			$response->nbtodolate += ($customer['nextdate'] < $today ? 1 : 0);
		}

		return $response;
	}

	/**
	 * Events of the reminders and promises of invoices: memory of the engine and history of the tab of the invoice
	 *
	 * @param	int[]	$invoiceids		Ids of invoices
	 * @return	array<int,stdClass>|int<-1,-1>	Events in the order they were recorded (id, fk_element, code, label, datep, email_to), -1 if error
	 */
	public function getEvents(array $invoiceids)
	{
		if (empty($invoiceids)) {
			return array();
		}
		$sql = "SELECT a.id, a.fk_element, a.code, a.label, a.datep, a.datec, a.email_to, a.extraparams";
		$sql .= " FROM ".$this->db->prefix()."actioncomm as a";
		$sql .= " WHERE a.entity IN (".getEntity('agenda').") AND a.elementtype = 'invoice'";
		$sql .= " AND a.fk_element IN (".$this->db->sanitize(implode(',', array_map('intval', $invoiceids))).")";
		$sql .= " AND a.code LIKE '".$this->db->escape($this->db->escapeforlike(self::CODE_PREFIX))."%'";
		$sql .= $this->db->order('a.id', 'ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		$events = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$obj->fk_element = (int) $obj->fk_element;
			$obj->datep = (int) $this->db->jdate($obj->datep);
			$obj->datec = (int) $this->db->jdate($obj->datec);
			$events[] = $obj;
		}

		return $events;
	}

	/**
	 * Recipients of the next level of invoices: contacts of the invoice of the type of the level,
	 * else default contacts of the thirdparty of this type, else email of the thirdparty
	 *
	 * @param	stdClass[]	$rows	Invoices as returned by getInvoices(), whatever the keys
	 * @return	array<int,string[]>|int<-1,-1>	Emails by id of invoice (empty if none), -1 if error
	 */
	public function getRecipients(array $rows)
	{
		$types = array();
		foreach ($rows as $row) {
			if ($row->next && $row->next->fk_c_type_contact > 0) {
				$types[] = $row->next->fk_c_type_contact;
			}
		}
		$emails = array();
		if (!empty($types)) {
			$sql = "SELECT 'i' as src, ec.element_id as id, ec.fk_c_type_contact as type, sp.email";
			$sql .= " FROM ".$this->db->prefix()."element_contact as ec";
			$sql .= " INNER JOIN ".$this->db->prefix()."socpeople as sp ON sp.rowid = ec.fk_socpeople";
			$sql .= " WHERE ec.element_id IN (".$this->db->sanitize(implode(',', array_map('intval', array_column($rows, 'id')))).")";
			$sql .= " AND ec.fk_c_type_contact IN (".$this->db->sanitize(implode(',', array_map('intval', $types))).")";
			$sql .= " AND sp.statut = 1 AND sp.email <> ''";
			$sql .= " UNION ALL SELECT 's', sc.fk_soc, sc.fk_c_type_contact, sp.email";
			$sql .= " FROM ".$this->db->prefix()."societe_contacts as sc";
			$sql .= " INNER JOIN ".$this->db->prefix()."socpeople as sp ON sp.rowid = sc.fk_socpeople";
			$sql .= " WHERE sc.entity IN (".getEntity('societe').") AND sc.fk_soc IN (".$this->db->sanitize(implode(',', array_map('intval', array_column($rows, 'socid')))).")";
			$sql .= " AND sc.fk_c_type_contact IN (".$this->db->sanitize(implode(',', array_map('intval', $types))).")";
			$sql .= " AND sp.statut = 1 AND sp.email <> ''";
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
				return -1;
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$emails[$obj->src][(int) $obj->id][(int) $obj->type][] = $obj->email;
			}
		}
		$recipients = array();
		foreach ($rows as $row) {
			$type = ($row->next ? $row->next->fk_c_type_contact : 0);
			if (!empty($emails['i'][$row->id][$type])) {
				$recipients[$row->id] = $emails['i'][$row->id][$type];
			} elseif (!empty($emails['s'][$row->socid][$type])) {
				$recipients[$row->id] = $emails['s'][$row->socid][$type];
			} else {
				$recipients[$row->id] = ($row->email ? array($row->email) : array());
			}
		}

		return $recipients;
	}

	/**
	 * Email template of a level in the language of the thirdparty: the template with the same label in this language, else the template of the level
	 *
	 * @param	stdClass	$level			Level
	 * @param	Translate	$outputlangs	Language of the thirdparty
	 * @param	User		$user			User sending (private templates)
	 * @param	bool		$bylang			False for a customer without language: the template chosen for the level, in its own language
	 * @return	CEmailTemplate|ModelMail|int<-1,-1>	Template, -1 if not found (never the generic text of the core)
	 */
	public function getTemplate(stdClass $level, Translate $outputlangs, User $user, $bylang = true)
	{
		$key = $level->rowid.'_'.($bylang ? $outputlangs->defaultlang : 'level');
		if (!isset($this->templates[$key])) {
			$formmail = new FormMail($this->db);
			$tpl = ($bylang ? $formmail->getEMailTemplate($this->db, 'facture_send', $user, $outputlangs, -2, 1, $level->template_label) : -1);
			if (!is_object($tpl)) {
				$tpl = $formmail->getEMailTemplate($this->db, 'facture_send', $user, $outputlangs, $level->fk_email_template);
			}
			$this->templates[$key] = ((is_object($tpl) && $tpl->id > 0) ? $tpl : -1);	// An unknown id gives the generic text "send invoice" without id
		}

		return $this->templates[$key];
	}

	/**
	 * Substitution keys of the module for one email, and the usual keys of the remain set to the total of its invoices
	 *
	 * @param	stdClass[]	$group			Invoices of the email, the oldest first
	 * @param	stdClass	$level			Level sent
	 * @param	Translate	$outputlangs	Language of the email
	 * @param	int			$now			Date of the sending
	 * @return	array<string,string>		Substitution keys
	 */
	public function getSubstitutions(array $group, stdClass $level, Translate $outputlangs, $now)
	{
		$late = 0;
		$amounts = array();
		$totals = array();	// By currency: rest to pay, penalties, indemnity
		foreach ($group as $key => $row) {
			$amounts[$key] = $this->getPenalties($row, $now);	// In the currency of the company: converted at the rate of the invoice
			$late = max($late, $amounts[$key]['days']);
			$total = $totals[$row->currency] ?? array('remain' => 0.0, 'penalties' => 0.0, 'indemnity' => 0.0);
			$total['remain'] += $row->mc_remain;
			$total['penalties'] += $amounts[$key]['penalties'] * $row->mc_rate;
			$total['indemnity'] += $amounts[$key]['indemnity'] * $row->mc_rate;
			$totals[$row->currency] = $total;
		}
		$withpenalties = ($this->formatTotals($totals, array('penalties', 'indemnity'), $outputlangs) !== '');	// A column only when something is due
		$table = '<table border="1" cellpadding="4" style="border-collapse: collapse"><tr><th>'.$outputlangs->trans('Ref').'</th><th>'.$outputlangs->trans('DateDue').'</th>';
		$table .= '<th>'.$outputlangs->trans('Late').'</th><th>'.$outputlangs->trans('AmountTTC').'</th><th>'.$outputlangs->trans('AlreadyPaid').'</th><th>'.$outputlangs->trans('RemainderToPay').'</th>';
		$table .= ($withpenalties ? '<th>'.$outputlangs->trans('DunningPenaltiesColumn').'</th>' : '').'</tr>';
		foreach ($group as $key => $row) {
			$table .= '<tr><td><a href="'.dolPrintHTMLForAttributeUrl(getOnlinePaymentUrl(0, 'invoice', $row->ref)).'">'.dolPrintLabel($row->ref).'</a></td>';
			$table .= '<td>'.dol_print_date($row->refdate, 'day', 'tzserver', $outputlangs).'</td><td style="text-align: right">'.$amounts[$key]['days'].'</td>';
			$table .= '<td style="text-align: right">'.price($row->mc_total, 0, $outputlangs, 1, -1, -1, $row->currency).'</td>';
			$table .= '<td style="text-align: right">'.price($row->mc_paid, 0, $outputlangs, 1, -1, -1, $row->currency).'</td>';	// Payments, credit notes and deposits used
			$table .= '<td style="text-align: right">'.price($row->mc_remain, 0, $outputlangs, 1, -1, -1, $row->currency).'</td>';
			$table .= ($withpenalties ? '<td style="text-align: right">'.price(($amounts[$key]['penalties'] + $amounts[$key]['indemnity']) * $row->mc_rate, 0, $outputlangs, 1, -1, -1, $row->currency).'</td>' : '').'</tr>';
		}
		$formatted = $this->formatTotals($totals, array('remain'), $outputlangs);
		$withall = $this->formatTotals($totals, array('remain', 'penalties', 'indemnity'), $outputlangs);
		$parts = array();
		if ($this->formatTotals($totals, array('penalties'), $outputlangs) !== '') {
			$parts[] = $outputlangs->transnoentitiesnoconv('DunningPenaltiesTextPenalties', $this->formatTotals($totals, array('penalties'), $outputlangs), price($this->getSetupNumber('DUNNING_PENALTY_RATE'), 0, $outputlangs));
		}
		if ($this->formatTotals($totals, array('indemnity'), $outputlangs) !== '') {
			$parts[] = $outputlangs->transnoentitiesnoconv('DunningPenaltiesTextIndemnity', $this->formatTotals($totals, array('indemnity'), $outputlangs));
		}
		$plural = ($this->formatTotals($totals, array('penalties'), $outputlangs) !== '');	// The indemnity alone "is due"
		$text = ($parts ? $outputlangs->transnoentitiesnoconv($plural ? 'DunningPenaltiesText' : 'DunningPenaltiesTextOne', implode(' '.$outputlangs->transnoentitiesnoconv('and').' ', $parts), $withall) : '');
		$single = (count($totals) == 1 ? reset($totals)['remain'] : array_sum(array_column($group, 'remain')));	// Else in the currency of the company

		return array(
			'__DUNNING_LEVEL__' => $level->label,
			'__DUNNING_DAYS_LATE__' => (string) $late,
			'__DUNNING_INVOICES__' => $table.'</table>',
			'__DUNNING_TOTAL_REMAIN__' => $formatted,
			'__DUNNING_PENALTIES__' => $this->formatTotals($totals, array('penalties'), $outputlangs, true),
			'__DUNNING_INDEMNITY__' => $this->formatTotals($totals, array('indemnity'), $outputlangs, true),
			'__DUNNING_TOTAL_WITH_PENALTIES__' => $withall,
			'__DUNNING_PENALTIES_TEXT__' => $text,
			'__AMOUNT_REMAIN__' => (string) price2num($single, 'MT'),
			'__AMOUNT_REMAIN_FORMATTED__' => $formatted,
			'__AMOUNT_REMAIN_FORMATED__' => $formatted,	// Old spelling kept by the core
		);
	}

	/**
	 * Amounts of several currencies in one text: "1 200,00 € + 300,00 US$"
	 *
	 * @param	array<string,array{remain:float,penalties:float,indemnity:float}>	$totals		Amounts by currency
	 * @param	string[]															$fields		Fields added for each currency
	 * @param	Translate															$outputlangs	Language
	 * @param	bool																$zero		True to show a zero amount, else an empty text when everything is zero
	 * @return	string																			Text
	 */
	private function formatTotals(array $totals, array $fields, Translate $outputlangs, $zero = false)
	{
		$texts = array();
		foreach ($totals as $currency => $total) {
			$amount = 0.0;
			foreach ($fields as $field) {
				$amount += $total[$field];
			}
			if ($zero || (float) price2num($amount, 'MT') != 0) {
				$texts[] = price($amount, 0, $outputlangs, 1, -1, 'MT', (string) $currency);
			}
		}

		return implode(' + ', $texts);
	}

	/**
	 * Days late of an invoice, 0 before its reference date. Rounded, as a day lasts 23 or 25 hours at a clock change.
	 *
	 * @param	int		$refdate	Reference date of the invoice (first hour of the day)
	 * @param	int		$now		Date of the reading
	 * @return	int					Days late
	 */
	private function daysLate($refdate, $now)
	{
		return max(0, (int) round((dol_get_first_hour($now) - $refdate) / 86400));
	}

	/**
	 * Retained warranty of an invoice, computed by the core
	 *
	 * @param	stdClass	$obj	Invoice as read by getInvoices(): rowid, type, total_ttc, retained_warranty
	 * @return	float				Amount of the retained warranty, <= 0 if none
	 */
	private function getRetainedWarranty(stdClass $obj)
	{
		$invoice = new Facture($this->db);
		if ((int) $obj->type == Facture::TYPE_SITUATION && getDolGlobalString('INVOICE_RETAINED_WARRANTY_LIMITED_TO_FINAL_SITUATION')) {
			$invoice->fetch((int) $obj->rowid);	// The warranty of a final situation depends on the previous ones: one fetch per such invoice
		} else {
			$invoice->total_ttc = (float) $obj->total_ttc;
			$invoice->retained_warranty = (float) $obj->retained_warranty;
		}

		return (float) $invoice->getRetainedWarrantyAmount();
	}

	/**
	 * Tell if the customer of an invoice is a company, by the rules of the core (Societe::isACompany()): only companies owe late penalties
	 *
	 * @param	stdClass	$obj	Invoice as read by getInvoices(): typent_code, tva_intra and the professional ids of the customer
	 * @return	bool				True if a company
	 */
	private function isACompany(stdClass $obj)
	{
		$soc = new Societe($this->db);
		$soc->typent_code = (string) $obj->typent_code;
		$soc->tva_intra = (string) $obj->tva_intra;
		$soc->euid = (string) $obj->euid;
		foreach (array('idprof1' => 'siren', 'idprof2' => 'siret', 'idprof3' => 'ape', 'idprof4' => 'idprof4', 'idprof5' => 'idprof5', 'idprof6' => 'idprof6') as $property => $field) {
			$soc->$property = (string) $obj->$field;
		}

		return $soc->isACompany() > 0;
	}

	/**
	 * Late penalties and fixed recovery indemnity of an invoice at a date, between companies (article L441-10 of the French commercial code):
	 * rest to pay x annual rate x days late / 365, and the indemnity once per late invoice. Nothing if the rates are not set up.
	 *
	 * @param	stdClass	$row	Invoice as returned by getInvoices()
	 * @param	int			$now	Date of the computation
	 * @return	array{days:int,penalties:float,indemnity:float}	Days late, penalties and indemnity
	 */
	public function getPenalties(stdClass $row, $now)
	{
		$days = $this->daysLate($row->refdate, $now);
		$result = array('days' => $days, 'penalties' => 0.0, 'indemnity' => 0.0);
		if ($days > 0 && $row->penaltysubject) {
			$result['penalties'] = (float) price2num($row->remain * $this->getSetupNumber('DUNNING_PENALTY_RATE') / 100 * $days / 365, 'MT');
			$result['indemnity'] = (float) price2num($this->getSetupNumber('DUNNING_FIXED_INDEMNITY'), 'MT');
		}

		return $result;
	}

	/**
	 * Number of the setup typed with a comma or a dot as decimal separator: read the same whatever the language of the reader (a job, a user)
	 *
	 * @param	string	$name	Name of the constant
	 * @return	float			Value, 0 if empty
	 */
	private function getSetupNumber($name)
	{
		return (float) str_replace(array(' ', ','), array('', '.'), getDolGlobalString($name));
	}

	/**
	 * Tell if a user can send reminders: the right of the module and, with the advanced permissions, the right to send invoices by email
	 *
	 * @param	User	$user	User
	 * @return	bool			True if allowed
	 */
	public function canSend(User $user)
	{
		return $user->hasRight('dunning', 'write') && (!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') || $user->hasRight('facture', 'invoice_advance', 'send'));
	}

	/**
	 * Invoices sent in the same email as one invoice at its next level: the due invoices of its customer at this level if the level is per customer
	 *
	 * @param	User		$user	User reading
	 * @param	stdClass	$row	Invoice as returned by getInvoices(), with a next level
	 * @return	array<int,stdClass>|int<-1,-1>	Invoices by id, -1 if error
	 */
	public function getEmailGroup(User $user, stdClass $row)
	{
		if (!$row->next->percustomer) {
			return array($row->id => $row);
		}
		$rows = $this->getInvoices($user, dol_now(), array($row->socid));
		if (!is_array($rows)) {
			return -1;
		}
		foreach ($rows as $id => $other) {
			if (!$other->due || $other->next->rowid != $row->next->rowid) {
				unset($rows[$id]);
			}
		}

		return array($row->id => $row) + $rows;
	}

	/**
	 * Send the next level of invoices: one email per invoice, or per customer and level if the level asks for it (or $forcegroup).
	 * An event is recorded on each invoice of an email sent.
	 *
	 * @param	User				$user		User sending
	 * @param	array<int,stdClass>	$rows		Invoices with a next level, as returned by getInvoices()
	 * @param	int					$forcegroup	1 = one email per customer and level whatever the level
	 * @return	int					Number of emails not sent (see errors), 0 if all sent, -1 if not allowed
	 */
	public function send(User $user, array $rows, $forcegroup = 0)
	{
		global $langs;

		$langs->loadLangs(array('mails', 'bills', 'dunning@dunning'));
		if (!$this->canSend($user)) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		$recipients = $this->getRecipients($rows);
		if (!is_array($recipients)) {
			return -1;
		}
		$groups = array();
		foreach ($rows as $row) {
			$groups[($row->next->percustomer || $forcegroup) ? $row->socid.'_'.$row->next->rowid.'_'.$row->currency : 'i'.$row->id][] = $row;	// One email per currency
		}

		$this->output = '';
		$this->errors = array();
		$nbemails = 0;
		$nbinvoices = 0;
		foreach ($groups as $group) {	// Its queries (events read again, fetch, events recorded) are bounded by the emails of the day
			$nb = $this->sendGroup($user, $group, $recipients);
			if ($nb > 0) {
				$nbemails++;
				$nbinvoices += $nb;
			}
		}
		$this->output = $langs->trans('DunningEmailsSent', $nbemails, $nbinvoices).$this->output;

		return count($this->errors);
	}

	/**
	 * Send one email of reminder, then record an event on each of its invoices (like the reminder job of the core)
	 *
	 * @param	User					$user			User sending
	 * @param	stdClass[]				$group			Invoices of the email, of one customer and one level, the oldest first
	 * @param	array<int,string[]>		$recipients		Emails by id of invoice
	 * @return	int						Number of invoices of the email sent, 0 if not sent (error added to errors, or level already sent)
	 */
	private function sendGroup(User $user, array $group, array $recipients)
	{
		global $conf, $langs;

		$level = $group[0]->next;
		$sentcode = self::CODE_PREFIX.$level->rowid.self::CODE_SENT_SUFFIX;
		// Events read again just before sending: another run or user may have sent this level since the invoices were read
		$events = $this->getEvents(array_column($group, 'id'));
		if (!is_array($events)) {
			$this->errors[] = implode(', ', array_column($group, 'ref')).': '.$this->error;
			return 0;
		}
		$alreadysent = array();
		foreach ($events as $event) {
			if ($event->code == $sentcode) {
				$alreadysent[$event->fk_element] = 1;
			}
		}
		$tosend = array();
		foreach ($group as $row) {
			if (empty($alreadysent[$row->id])) {
				$tosend[] = $row;
			}
		}
		if (empty($tosend)) {
			return 0;
		}
		$group = $tosend;
		$first = $group[0];
		$refs = implode(', ', array_column($group, 'ref'));

		$invoice = new Facture($this->db);
		if ($invoice->fetch($first->id) <= 0) {
			$this->errors[] = $refs.': '.$invoice->error;
			return 0;
		}
		$invoice->fetch_thirdparty();

		$outputlangs = $langs;
		if ($first->default_lang) {
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang($first->default_lang);
			$outputlangs->loadLangs(array('main', 'bills', 'dunning@dunning'));
		}

		// A customer without language gets the template of the level, not the language of the reader (a job runs in the default language)
		$tpl = $this->getTemplate($level, $outputlangs, $user, (bool) $first->default_lang);
		if (!is_object($tpl)) {
			$langs->load('errors');
			$this->errors[] = $refs.': '.$langs->trans('ErrorFailedToFindEmailTemplate', $level->template_label);
			return 0;
		}
		if (!$first->default_lang && !empty($tpl->lang) && $tpl->lang != $outputlangs->defaultlang) {	// The table of the invoices in the language of the template
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang($tpl->lang);
			$outputlangs->loadLangs(array('main', 'bills', 'dunning@dunning'));
		}
		$to = array();
		foreach ($group as $row) {
			$to = array_merge($to, $recipients[$row->id]);
		}
		$to = implode(',', array_unique($to));
		if (empty($to)) {
			$this->errors[] = $refs.': '.dolPrintLabel($langs->transnoentitiesnoconv('NoRecipientEmail', $first->socname));
			return 0;
		}
		$from = ($tpl->email_from ?: getDolGlobalString('MAIN_MAIL_EMAIL_FROM'));
		if (empty($from)) {
			$langs->load('errors');
			$this->errors[] = $refs.': '.$langs->trans('ErrorSetupOfEmailsNotComplete');
			return 0;
		}

		$subst = getCommonSubstitutionArray($outputlangs, 0, null, $invoice);
		complete_substitutions_array($subst, $outputlangs, $invoice);
		if (count($group) > 1) {	// Amounts and dates of one invoice are blank, the payment links are in __DUNNING_INVOICES__
			$subst = array_merge($subst, array_fill_keys(array_keys(getCommonSubstitutionArray($outputlangs, 0, null, $invoice, array('objectamount'))), ''));
			$subst['__REF__'] = $refs;
			$subst['__REF_CLIENT__'] = '';
			$subst['__ID__'] = implode(', ', array_column($group, 'id'));
			$subst['__ONLINE_PAYMENT_URL__'] = '';
			$subst['__ONLINE_PAYMENT_TEXT_AND_URL__'] = '';
		}
		$subst = array_merge($subst, $this->getSubstitutions($group, $level, $outputlangs, dol_now()));
		$content = $outputlangs->transnoentitiesnoconv($tpl->content);
		if (!dol_textishtml($content)) {	// The email is sent as HTML: keep the lines of a text template
			$content = dol_htmlentitiesbr($content);
		}
		$content = make_substitutions($content, $subst, $outputlangs, 1);
		$topic = make_substitutions($tpl->topic ?: $outputlangs->transnoentitiesnoconv('InformationMessage'), $subst, $outputlangs, 1);

		$files = array();
		$mimes = array();
		$names = array();
		if ($tpl->joinfiles) {
			foreach ($group as $row) {
				$file = DOL_DATA_ROOT.'/'.$row->last_main_doc;
				if ($row->last_main_doc && dol_is_file($file)) {
					$files[] = $file;
					$mimes[] = dol_mimetype($file);
					$names[] = dol_basename($file);
				} else {
					$langs->load('errors');
					$this->output .= "\n".$row->ref.': '.$langs->trans('ErrorFileNotFound', $row->last_main_doc);
				}
			}
		}

		if ($level->joinnotice) {	// The formal notice of the customer, generated now with its late invoices
			dol_include_once('/dunning/core/modules/societe/doc/pdf_dunningnotice.modules.php');
			$notice = new pdf_dunningnotice($this->db);
			if ($notice->write_file($invoice->thirdparty, $outputlangs) <= 0) {
				$this->errors[] = $refs.': '.$notice->error;
				return 0;
			}
			$files[] = $notice->result['fullpath'];
			$mimes[] = 'application/pdf';
			$names[] = dol_basename($notice->result['fullpath']);
		}

		$errorsto = getDolGlobalString('MAIN_MAIL_ERRORS_TO');
		$trackid = (count($group) > 1 ? 'thi'.$first->socid : 'inv'.$first->id);
		$mail = new CMailFile($topic, $to, $from, $content, $files, $mimes, $names, $tpl->email_tocc, $tpl->email_tobcc, 0, 1, $errorsto, '', $trackid, '', 'standard');

		if (!$mail->sendfile()) {
			$this->errors[] = $refs.': '.$mail->error;
			dol_syslog(__METHOD__.' '.$refs.' '.$mail->error, LOG_WARNING);
			return 0;
		}

		// Then the memory of the reminder: one event per invoice of the email
		$this->db->begin();
		foreach ($group as $row) {
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH_AUTO';
			$event->code = $sentcode;
			$event->label = $langs->transnoentities('DunningEventLabel', $level->label);
			$event->note_private = $content;
			$event->socid = $row->socid;
			$event->fk_project = $row->fk_projet;
			$event->elementtype = 'invoice';
			$event->elementid = $row->id;
			$event->datep = dol_now();
			$event->datef = $event->datep;
			$event->percentage = -1;
			$event->authorid = $user->id;
			$event->userownerid = $user->id;
			$event->email_msgid = $mail->msgid;
			$event->email_subject = dol_trunc($topic, 250, 'right', 'UTF-8', 1);	// varchar(255)
			$event->email_from = $from;
			$event->email_to = dol_trunc($to, 250, 'right', 'UTF-8', 1);
			$event->errors_to = $errorsto;
			if ($event->create($user) < 0) {
				$this->db->rollback();
				$this->errors[] = $refs.': '.$langs->trans('DunningErrorNotRecorded', $event->error);
				dol_syslog(__METHOD__.' '.$refs.' sent but not recorded: '.$event->error, LOG_ERR);
				return count($group);
			}
		}
		$this->db->commit();

		return count($group);
	}

	/**
	 * Record a payment promise on invoices: no reminder up to the promised day, then the next level
	 *
	 * @param	User				$user	User recording
	 * @param	array<int,stdClass>	$rows	Invoices as returned by getInvoices()
	 * @param	int					$date	Promised day
	 * @param	string				$note	Note of the promise
	 * @param	float				$amount	Amount promised for one invoice, 0 if none: the promise is then kept or not by the payments
	 * @return	int					Number of events created, <0 if KO
	 */
	public function addPromise(User $user, array $rows, $date, $note, $amount = 0.0)
	{
		global $langs;

		$langs->load('dunning@dunning');
		if (!$user->hasRight('dunning', 'write')) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		if (!$date || dol_get_first_hour((int) $date) < dol_get_first_hour(dol_now())) {	// No date, or a day before today
			$langs->load('errors');
			$this->error = $langs->trans('ErrorDateMustBeInFuture');
			return -1;
		}
		$this->db->begin();
		foreach ($rows as $row) {	// Bounded by the invoices of the customers selected on one page
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH';
			$event->code = self::CODE_PROMISE;
			$event->label = $langs->transnoentities('DunningPromiseLabel', dol_print_date($date, 'day'));
			$event->note_private = $note;
			if ($amount > 0) {
				$event->extraparams = array('amount' => (string) price2num($amount, 'MT'));
			}
			$event->datep = $date;
			$event->datef = $date;
			$event->fulldayevent = 1;
			$event->percentage = -1;
			$event->socid = $row->socid;
			$event->elementtype = 'invoice';
			$event->elementid = $row->id;
			$event->authorid = $user->id;
			$event->userownerid = $user->id;
			if ($event->create($user) < 0) {
				$this->db->rollback();
				$this->error = $event->error;
				return -1;
			}
		}
		$this->db->commit();

		return count($rows);
	}

	/**
	 * Stop the reminders of thirdparties: add them to the excluded customer category of the setup
	 *
	 * @param	User	$user		User excluding
	 * @param	int[]	$socids		Ids of thirdparties
	 * @return	int		Number of thirdparties excluded, 0 if no category is set up, <0 if KO
	 */
	public function excludeThirdparties(User $user, array $socids)
	{
		global $langs;

		if (!$user->hasRight('dunning', 'write') || !$user->hasRight('societe', 'creer')) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		if (!isModEnabled('category') || getDolGlobalInt('DUNNING_EXCLUDED_CATEGORY') <= 0) {
			return 0;
		}
		$category = new Categorie($this->db);
		if ($category->fetch(getDolGlobalInt('DUNNING_EXCLUDED_CATEGORY')) <= 0) {
			$this->error = $category->error;
			return -1;
		}
		$socids = array_unique(array_map('intval', $socids));
		if (empty($socids)) {
			return 0;
		}
		// The customers already excluded are skipped before the unique key, whose error would abort the transaction with PostgreSQL
		$sql = "SELECT fk_soc FROM ".$this->db->prefix()."categorie_societe WHERE fk_categorie = ".((int) $category->id);
		$sql .= " AND fk_soc IN (".$this->db->sanitize(implode(',', $socids)).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$excluded = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$excluded[] = (int) $obj->fk_soc;
		}
		foreach (array_diff($socids, $excluded) as $socid) {	// Bounded by the customers selected on one page
			$soc = new Societe($this->db);
			$soc->id = $socid;	// add_type() only needs the id
			if ($category->add_type($soc, Categorie::TYPE_CUSTOMER) < 0) {
				$this->error = $category->error;
				return -1;
			}
		}

		return count($socids);
	}

	/**
	 * Create a draft invoice of the late penalties and the recovery indemnity of an invoice, once: an event on the invoice keeps its id.
	 * The invoice of origin is not changed (a validated invoice can not be), the user checks and validates the new one.
	 *
	 * @param	User		$user	User creating
	 * @param	stdClass	$row	Invoice as returned by getInvoices()
	 * @return	int					Id of the penalty invoice, <0 if KO
	 */
	public function createPenaltyInvoice(User $user, stdClass $row)
	{
		global $langs;

		$langs->loadLangs(array('bills', 'dunning@dunning'));
		if (!$user->hasRight('dunning', 'write') || !$user->hasRight('facture', 'creer')) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		$now = dol_now();
		$amounts = $this->getPenalties($row, $now);
		if ($row->penaltyinvoice || $amounts['penalties'] + $amounts['indemnity'] <= 0) {
			$this->error = $langs->trans($row->penaltyinvoice ? 'DunningPenaltyInvoiceExists' : 'DunningNoPenalty');
			return -1;
		}

		$this->db->begin();
		$invoice = new Facture($this->db);
		$invoice->socid = $row->socid;
		$invoice->date = $now;
		$invoice->fk_project = $row->fk_projet;
		$invoice->linked_objects = array('facture' => $row->id);
		$id = $invoice->create($user);
		$lines = array(
			array($langs->transnoentities('DunningPenaltyLine', $row->ref, $amounts['days'], price($this->getSetupNumber('DUNNING_PENALTY_RATE'))), $amounts['penalties']),
			array($langs->transnoentities('DunningIndemnityLine', $row->ref), $amounts['indemnity']),
		);
		foreach ($lines as $line) {	// Service lines without VAT: late penalties and recovery indemnity are out of its scope
			if ($id > 0 && $line[1] > 0 && $invoice->addline($line[0], $line[1], 1, 0, 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 1) < 0) {
				$id = -1;
			}
		}
		if ($id > 0) {
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH_AUTO';
			$event->code = self::CODE_PENALTY_INVOICE;
			$event->label = $langs->transnoentities('DunningPenaltyInvoiceEvent', $invoice->ref);
			$event->extraparams = array('invoice' => (string) $id);
			$event->socid = $row->socid;
			$event->elementtype = 'invoice';
			$event->elementid = $row->id;
			$event->datep = $now;
			$event->datef = $now;
			$event->percentage = -1;
			$event->authorid = $user->id;
			$event->userownerid = $user->id;
			$id = ($event->create($user) > 0 ? $id : -1);
		}
		if ($id <= 0) {
			$this->error = ($invoice->error ?: $this->db->lasterror());
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return $id;
	}

	/**
	 * Email templates of the module, French and English versions with the same label: the module sends the version in the language of the customer
	 *
	 * @return array<array{0:string,1:string,2:int,3:string,4:string}>	Label, language, position, topic and content of each template
	 */
	private function getDefaultTemplates()
	{
		return array(
			array('Relances - 1 Courtoisie / Courtesy', 'fr_FR', 10, 'Rappel : facture(s) bientôt à échéance',
				'Bonjour,<br><br>Pour rappel, la ou les factures suivantes arrivent bientôt à échéance :<br>__DUNNING_INVOICES__<br>Total restant à payer : __DUNNING_TOTAL_REMAIN__. Vous pouvez régler en ligne depuis les liens du tableau.<br><br>Si votre règlement est déjà parti, merci de ne pas tenir compte de ce message.<br><br>Cordialement,<br>__MYCOMPANY_NAME__'),
			array('Relances - 1 Courtoisie / Courtesy', 'en_US', 10, 'Reminder: invoice(s) due soon',
				'Hello,<br><br>As a reminder, the following invoice(s) will soon be due:<br>__DUNNING_INVOICES__<br>Total remaining to pay: __DUNNING_TOTAL_REMAIN__. You can pay online from the links of the table.<br><br>If you have already paid, please ignore this message.<br><br>Best regards,<br>__MYCOMPANY_NAME__'),
			array('Relances - 2 Relance / Reminder', 'fr_FR', 20, 'Relance : factures impayées (__DUNNING_TOTAL_REMAIN__)',
				'Bonjour,<br><br>Sauf erreur de notre part, les factures suivantes restent impayées à ce jour :<br>__DUNNING_INVOICES__<br>Total restant dû : __DUNNING_TOTAL_REMAIN__.<br><br>Merci de procéder à leur règlement dans les meilleurs délais, ou de nous indiquer la date à laquelle il interviendra. Si votre règlement est déjà parti, merci de ne pas tenir compte de ce message.<br>__DUNNING_PENALTIES_TEXT__<br><br>Cordialement,<br>__MYCOMPANY_NAME__'),
			array('Relances - 2 Relance / Reminder', 'en_US', 20, 'Reminder: unpaid invoices (__DUNNING_TOTAL_REMAIN__)',
				'Hello,<br><br>Unless we are mistaken, the following invoices are still unpaid:<br>__DUNNING_INVOICES__<br>Total remaining due: __DUNNING_TOTAL_REMAIN__.<br><br>Please pay them as soon as possible, or let us know when you will. If you have already paid, please ignore this message.<br>__DUNNING_PENALTIES_TEXT__<br><br>Best regards,<br>__MYCOMPANY_NAME__'),
			array('Relances - 3 Mise en demeure / Formal notice', 'fr_FR', 30, 'Mise en demeure de payer : __DUNNING_TOTAL_REMAIN__',
				'Madame, Monsieur,<br><br>Malgré nos précédentes relances, les factures suivantes restent impayées :<br>__DUNNING_INVOICES__<br>Total restant dû : __DUNNING_TOTAL_REMAIN__.<br>__DUNNING_PENALTIES_TEXT__<br><br>Par la présente, nous vous mettons en demeure de régler cette somme sous huit jours. À défaut, nous serons contraints d\'engager une procédure de recouvrement.<br><br>Veuillez agréer, Madame, Monsieur, nos salutations distinguées.<br>__MYCOMPANY_NAME__'),
			array('Relances - 3 Mise en demeure / Formal notice', 'en_US', 30, 'Formal notice to pay: __DUNNING_TOTAL_REMAIN__',
				'Dear Sir or Madam,<br><br>Despite our previous reminders, the following invoices are still unpaid:<br>__DUNNING_INVOICES__<br>Total remaining due: __DUNNING_TOTAL_REMAIN__.<br>__DUNNING_PENALTIES_TEXT__<br><br>We hereby give you formal notice to pay this amount within eight days. Failing that, we will have to start a debt recovery procedure.<br><br>Yours faithfully,<br>__MYCOMPANY_NAME__'),
		);
	}

	/**
	 * Create the email templates of the module that are not there yet, at each activation: a template already there is kept as the user changed it.
	 * Checked by a query first, not by the error of the unique key (entity, label, lang), which PostgreSQL reports too late inside a transaction.
	 *
	 * @return	int		Number of templates created, <0 if KO
	 */
	public function initTemplates()
	{
		global $conf;

		$sql = "SELECT label, lang FROM ".$this->db->prefix()."c_email_templates WHERE entity = ".((int) $conf->entity);
		$sql .= " AND label LIKE '".$this->db->escape($this->db->escapeforlike('Relances - '))."%'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$existing = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$existing[$obj->label.'|'.$obj->lang] = 1;
		}
		$nb = 0;
		foreach ($this->getDefaultTemplates() as $template) {	// Bounded by the 6 templates of the module
			if (isset($existing[$template[0].'|'.$template[1]])) {
				continue;
			}
			$sql = "INSERT INTO ".$this->db->prefix()."c_email_templates (entity, module, type_template, lang, private, label, position, enabled, active, topic, joinfiles, content)";
			$sql .= " VALUES (".((int) $conf->entity).", 'dunning', 'facture_send', '".$this->db->escape($template[1])."', 0, '".$this->db->escape($template[0])."', ".((int) $template[2]);
			$sql .= ", '1', 1, '".$this->db->escape($template[3])."', 1, '".$this->db->escape($template[4])."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$nb++;
		}

		return $nb;
	}

	/**
	 * Create the default levels on the first activation of the module, with the email templates of initTemplates(): nothing if a level exists
	 *
	 * @param	User	$user	Administrator enabling the module
	 * @return	int				Number of levels created, <0 if KO
	 */
	public function initDefaultLevels(User $user)
	{
		global $conf, $langs;

		$levels = $this->fetchLevels();
		if (!is_array($levels)) {
			return -1;
		}
		if (!empty($levels) || !$user->admin) {
			return 0;
		}
		$langs->load('dunning@dunning');
		$lang = (strpos((string) $langs->defaultlang, 'fr') === 0 ? 'fr_FR' : 'en_US');
		$sql = "SELECT t.rowid, t.label, (SELECT ct.rowid FROM ".$this->db->prefix()."c_type_contact as ct";
		$sql .= " WHERE ct.element = 'facture' AND ct.source = 'external' AND ct.code = 'BILLING' AND ct.active = 1) as billing";
		$sql .= " FROM ".$this->db->prefix()."c_email_templates as t";
		$sql .= " WHERE t.entity = ".((int) $conf->entity)." AND t.module = 'dunning' AND t.lang = '".$this->db->escape($lang)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$templates = array();
		$billing = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$templates[$obj->label] = (int) $obj->rowid;
			$billing = (int) $obj->billing;
		}
		$defaults = array(
			array('Relances - 1 Courtoisie / Courtesy', -3, 'DunningDefaultLevelCourtesy', 0, 0, 0),
			array('Relances - 2 Relance / Reminder', 15, 'DunningDefaultLevelReminder', 1, 0, 0),
			array('Relances - 3 Mise en demeure / Formal notice', 30, 'DunningDefaultLevelNotice', 1, 1, 1),
		);
		$nb = 0;
		foreach ($defaults as $default) {
			if (empty($templates[$default[0]])) {	// Template deleted by the user
				continue;
			}
			$level = array('rowid' => 0, 'label' => $langs->transnoentitiesnoconv($default[2]), 'nbdays' => $default[1], 'fk_c_type_contact' => $billing,
				'percustomer' => $default[3], 'byhand' => $default[4], 'joinnotice' => $default[5], 'fk_email_template' => $templates[$default[0]], 'active' => 1);
			if ($this->saveLevel($user, $level) < 0) {
				return -1;
			}
			$nb++;
		}

		return $nb;
	}

	/**
	 * Statistics of the reminders sent between two dates, by month of the reminder and by level: reminders, invoices reminded, amount paid
	 * within 30 days after a reminder (each payment counted once, for the last reminder before it), and average days from the first reminder
	 * of an invoice to its full payment. Computed in PHP from 4 queries, the same with MySQL and PostgreSQL.
	 *
	 * @param	User	$user	User reading
	 * @param	int		$from	Start of the period
	 * @param	int		$to		End of the period
	 * @return	array{months:array<string,array{reminders:int,invoices:int,paid30:float,delay:float,closed:int}>,levels:array<int,array{label:string,reminders:int,paid30:float}>}|int<-1,-1>	Statistics, -1 if error
	 */
	public function getStatistics(User $user, $from, $to)
	{
		global $langs;

		if (!$user->hasRight('dunning', 'read') || !$user->hasRight('facture', 'lire') || $user->socid > 0) {
			$this->error = $langs->trans('NotEnoughPermissions');
			return -1;
		}
		$levels = $this->fetchLevels();
		if (!is_array($levels)) {
			return -1;
		}
		$where = " FROM ".$this->db->prefix()."actioncomm as a WHERE a.entity IN (".getEntity('agenda').") AND a.elementtype = 'invoice'";
		if (!$user->hasRight('societe', 'client', 'voir')) {	// Like getInvoices(): a sales representative only counts its customers
			$where .= " AND EXISTS (SELECT sc.fk_soc FROM ".$this->db->prefix()."societe_commerciaux as sc WHERE sc.fk_soc = a.fk_soc AND sc.fk_user = ".((int) $user->id).")";
		}
		$where .= " AND a.code LIKE '".$this->db->escape($this->db->escapeforlike(self::CODE_PREFIX))."%".$this->db->escape($this->db->escapeforlike(self::CODE_SENT_SUFFIX))."'";
		$where .= " AND a.datep >= '".$this->db->idate($from)."' AND a.datep <= '".$this->db->idate($to)."'";
		$queries = array(
			'reminders' => "SELECT a.fk_element as id, a.code, a.datep as date".$where." ORDER BY a.datep",
			'payments' => "SELECT pf.fk_facture as id, p.datep as date, pf.amount FROM ".$this->db->prefix()."paiement_facture as pf INNER JOIN ".$this->db->prefix()."paiement as p ON p.rowid = pf.fk_paiement WHERE pf.fk_facture IN (SELECT a.fk_element".$where.")",
			'closed' => "SELECT f.rowid as id, f.date_closing as date FROM ".$this->db->prefix()."facture as f WHERE f.paye = 1 AND f.entity IN (".getEntity('invoice').") AND f.rowid IN (SELECT a.fk_element".$where.")",
		);
		$data = array();
		foreach ($queries as $name => $sql) {	// 3 queries
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$data[$name] = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$obj->id = (int) $obj->id;
				$obj->date = (int) $this->db->jdate($obj->date);
				$data[$name][] = $obj;
			}
		}

		$result = array('months' => array(), 'levels' => array());
		$byinvoice = array();
		$month = array('reminders' => 0, 'invoices' => 0, 'paid30' => 0.0, 'delay' => 0.0, 'closed' => 0);
		foreach ($data['reminders'] as $reminder) {
			$key = dol_print_date($reminder->date, '%Y-%m', 'tzserver');
			$reg = array();
			$levelid = (preg_match('/^'.self::CODE_PREFIX.'(\d+)'.self::CODE_SENT_SUFFIX.'$/', $reminder->code, $reg) ? (int) $reg[1] : 0);
			$result['months'][$key] = $result['months'][$key] ?? $month;
			$result['months'][$key]['reminders']++;
			$result['months'][$key]['invoices'] += (isset($byinvoice[$reminder->id]) ? 0 : 1);	// Counted in the month of its first reminder
			$result['levels'][$levelid] = $result['levels'][$levelid] ?? array('label' => (isset($levels[$levelid]) ? $levels[$levelid]->label : (string) $levelid), 'reminders' => 0, 'paid30' => 0.0);
			$result['levels'][$levelid]['reminders']++;
			$byinvoice[$reminder->id][] = array($reminder->date, $key, $levelid);
		}
		foreach ($data['payments'] as $payment) {	// To the last reminder before the payment, if it is at most 30 days before
			$last = null;
			foreach ($byinvoice[$payment->id] ?? array() as $reminder) {
				$last = ($reminder[0] <= $payment->date ? $reminder : $last);
			}
			if ($last && $payment->date <= dol_time_plus_duree($last[0], 30, 'd', 0, 'tzserver')) {
				$result['months'][$last[1]]['paid30'] += (float) $payment->amount;
				$result['levels'][$last[2]]['paid30'] += (float) $payment->amount;
			}
		}
		$delays = array();
		foreach ($data['closed'] as $invoice) {	// Days from the first reminder of the invoice to its full payment
			$first = $byinvoice[$invoice->id][0];
			if ($invoice->date >= $first[0]) {
				$delays[$first[1]][] = (int) round(($invoice->date - $first[0]) / 86400);
			}
		}
		foreach ($delays as $key => $days) {
			$result['months'][$key]['delay'] = round(array_sum($days) / count($days), 1);
			$result['months'][$key]['closed'] = count($days);
		}
		ksort($result['months']);

		return $result;
	}

	/**
	 * Tell if the reminder job of the core, or one of its clones, is enabled: it would send reminders twice
	 *
	 * @return	bool	True if enabled
	 */
	public function isCoreJobEnabled()
	{
		$sql = "SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."cronjob";
		$sql .= " WHERE entity IN (0, ".getEntity('cron').") AND objectname = 'Facture' AND methodename = 'sendEmailsRemindersOnInvoiceDueDate'";
		$sql .= " AND status = ".((int) Cronjob::STATUS_ENABLED);
		$resql = $this->db->query($sql);
		if (!$resql) {	// Unknown: the job stops rather than risking reminders sent twice
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return true;
		}
		$obj = $this->db->fetch_object($resql);

		return ($obj && $obj->nb > 0);
	}

	/**
	 * Send the next reminder level of unpaid customer invoices that are due and reached after the start date.
	 * CAN BE A CRON TASK. The status of this job is the switch of the reminders.
	 *
	 * @param	int		$now	Date of the run, now if 0
	 * @return	int		0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function sendReminders($now = 0)
	{
		global $conf, $langs, $user;

		$now = ($now ?: dol_now());
		$langs->loadLangs(array('bills', 'dunning@dunning'));
		$this->error = '';
		$this->errors = array();
		if ($this->isCoreJobEnabled()) {
			$this->error = ($this->error ?: $langs->trans('DunningCoreJobEnabled', $langs->transnoentitiesnoconv('SendEmailsRemindersOnInvoiceDueDate')));
			return 1;
		}
		if (!$user->hasRight('societe', 'client', 'voir')) {	// A run limited to some customers would look complete
			$this->error = $langs->trans('NotEnoughPermissions');
			return 1;
		}
		if (!getDolGlobalInt('DUNNING_START_DATE')) {	// First run: nothing is sent for the past
			dolibarr_set_const($this->db, 'DUNNING_START_DATE', (string) $now, 'chaine', 0, '', $conf->entity);
		}
		$rows = $this->getInvoices($user, $now);
		if (!is_array($rows)) {
			return 1;
		}
		foreach ($rows as $id => $row) {
			if (!$row->due || !$row->auto) {
				unset($rows[$id]);
			}
		}
		$result = ($rows ? $this->send($user, $rows) : 0);
		dol_syslog(__METHOD__.' '.count($rows).' invoice(s) to remind, '.count($this->errors).' error(s)', LOG_INFO);

		return $result;
	}
}
