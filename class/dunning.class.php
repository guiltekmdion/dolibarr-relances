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
		$sql = "SELECT l.rowid, l.label, l.nbdays, l.fk_c_type_contact, l.percustomer, l.byhand, l.fk_email_template, l.active, t.label as template_label";
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
			foreach (array('rowid', 'nbdays', 'fk_c_type_contact', 'percustomer', 'byhand', 'fk_email_template', 'active') as $field) {
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
	 * @param	array{rowid:int,label:string,nbdays:int,fk_c_type_contact:int,percustomer:int,byhand?:int,fk_email_template:int,active:int}	$level	Values of the level, rowid 0 to create it
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
		global $langs;

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
		$sql .= " f.retained_warranty, f.retained_warranty_date_limit,";
		$sql .= " s.nom as socname, s.email, s.default_lang,";
		$sql .= " (SELECT SUM(pf.amount) FROM ".$this->db->prefix()."paiement_facture as pf WHERE pf.fk_facture = f.rowid) as paid,";
		$sql .= " (SELECT SUM(rc.amount_ttc) FROM ".$this->db->prefix()."societe_remise_except as rc, ".$this->db->prefix()."facture as fs";
		$sql .= " WHERE rc.fk_facture_source = fs.rowid AND rc.fk_facture = f.rowid AND fs.type IN (".$this->db->sanitize(Facture::TYPE_STANDARD.", ".Facture::TYPE_CREDIT_NOTE.", ".Facture::TYPE_SITUATION).")) as creditnotes,";
		$sql .= " (SELECT SUM(rc.amount_ttc) FROM ".$this->db->prefix()."societe_remise_except as rc, ".$this->db->prefix()."facture as fs";
		$sql .= " WHERE rc.fk_facture_source = fs.rowid AND rc.fk_facture = f.rowid AND fs.type = ".((int) Facture::TYPE_DEPOSIT).") as deposits";
		$sql .= " FROM ".$this->db->prefix()."facture as f";
		$sql .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = f.fk_soc";
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
		while ($obj = $this->db->fetch_object($resql)) {
			$paid = (float) price2num((float) $obj->paid + (float) $obj->creditnotes + (float) $obj->deposits, 'MT');
			$remain = (float) price2num((float) $obj->total_ttc - $paid, 'MT');
			$refdate = (int) $this->db->jdate($obj->refdate);
			if ($remain > 0 && (float) $obj->retained_warranty > 0 && $obj->retained_warranty_date_limit) {
				// Like Facture::hasDelay(), the retained warranty is due at its own date: the rest is reminded first, then the warranty from its date
				$warranty = $this->getRetainedWarranty($obj);
				$warrantydate = (int) $this->db->jdate($obj->retained_warranty_date_limit);
				if ($warranty > 0 && $remain <= $warranty) {
					$refdate = max($refdate, $warrantydate);
				} elseif ($warranty > 0 && $warrantydate > $today) {
					$remain = (float) price2num($remain - $warranty, 'MT');
				}
			}
			if ($remain > 0) {
				$rows[(int) $obj->rowid] = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'socid' => (int) $obj->fk_soc, 'socname' => $obj->socname,
					'email' => (string) $obj->email, 'default_lang' => (string) $obj->default_lang, 'fk_projet' => (int) $obj->fk_projet,
					'last_main_doc' => (string) $obj->last_main_doc, 'refdate' => $refdate, 'total_ttc' => (float) $obj->total_ttc, 'paid' => $paid, 'remain' => $remain);
			}
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
		foreach ($events as $event) {	// In the order they were recorded: the last one wins
			$reg = array();
			if ($event->code == self::CODE_PROMISE) {
				$promise[$event->fk_element] = $event->datep;
			} elseif (preg_match('/^'.self::CODE_PREFIX.'(\d+)'.self::CODE_SENT_SUFFIX.'$/', $event->code, $reg)) {
				$sent[$event->fk_element][(int) $reg[1]] = $event->datep;
			}
		}
		$result = array();
		foreach ($rows as $id => $row) {
			$state = $this->computeState($levels, $row['refdate'], $sent[$id] ?? array(), $promise[$id] ?? 0, $startdate, $today);
			$result[$id] = (object) array_merge($row, (array) $state, array('promise' => $promise[$id] ?? 0, 'due' => ($state->next && $state->nextdate <= $today)));
		}

		return $result;
	}

	/**
	 * Customers in the circuit of the reminders: one line per customer with an invoice whose level was sent, is due now, or is promised.
	 * The list of the customers to remind and the dashboard both count with it.
	 *
	 * @param	User	$user		User reading, see getInvoices()
	 * @param	int		$now		Date of the reading
	 * @param	int		$saleid		Only the customers of this sales representative
	 * @param	int		$dueonly	1 = only the customers with a reminder to send
	 * @return	array<int,array{socid:int,socname:string,nb:int,remain:float,late30:float,level:?stdClass,leveldate:int,promise:int,next:?stdClass,nextdate:int,due:bool,byhand:bool}>|int<-1,-1>	Customers by id, -1 if error
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
				'level' => null, 'leveldate' => 0, 'promise' => 0, 'next' => null, 'nextdate' => 0, 'due' => false, 'byhand' => false);
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
		$sql = "SELECT a.id, a.fk_element, a.code, a.label, a.datep, a.email_to";
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
	 * @return	CEmailTemplate|ModelMail|int<-1,-1>	Template, -1 if not found (never the generic text of the core)
	 */
	public function getTemplate(stdClass $level, Translate $outputlangs, User $user)
	{
		$key = $level->rowid.'_'.$outputlangs->defaultlang;
		if (!isset($this->templates[$key])) {
			$formmail = new FormMail($this->db);
			$tpl = $formmail->getEMailTemplate($this->db, 'facture_send', $user, $outputlangs, -2, 1, $level->template_label);
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
		global $conf;

		$total = 0;
		$late = 0;
		$table = '<table border="1" cellpadding="4" style="border-collapse: collapse"><tr><th>'.$outputlangs->trans('Ref').'</th><th>'.$outputlangs->trans('DateDue').'</th>';
		$table .= '<th>'.$outputlangs->trans('Late').'</th><th>'.$outputlangs->trans('AmountTTC').'</th><th>'.$outputlangs->trans('AlreadyPaid').'</th><th>'.$outputlangs->trans('RemainderToPay').'</th></tr>';
		foreach ($group as $row) {
			$total += $row->remain;
			$late = max($late, $this->daysLate($row->refdate, $now));
			$table .= '<tr><td><a href="'.dolPrintHTMLForAttributeUrl(getOnlinePaymentUrl(0, 'invoice', $row->ref)).'">'.dolPrintLabel($row->ref).'</a></td>';
			$table .= '<td>'.dol_print_date($row->refdate, 'day', 'tzserver', $outputlangs).'</td><td style="text-align: right">'.$this->daysLate($row->refdate, $now).'</td>';
			$table .= '<td style="text-align: right">'.price($row->total_ttc, 0, $outputlangs, 1, -1, -1, $conf->currency).'</td>';
			$table .= '<td style="text-align: right">'.price($row->paid, 0, $outputlangs, 1, -1, -1, $conf->currency).'</td>';	// Payments, credit notes and deposits used
			$table .= '<td style="text-align: right">'.price($row->remain, 0, $outputlangs, 1, -1, -1, $conf->currency).'</td></tr>';
		}
		$formatted = price($total, 0, $outputlangs, 1, -1, -1, $conf->currency);

		return array(
			'__DUNNING_LEVEL__' => $level->label,
			'__DUNNING_DAYS_LATE__' => (string) $late,
			'__DUNNING_INVOICES__' => $table.'</table>',
			'__DUNNING_TOTAL_REMAIN__' => $formatted,
			'__AMOUNT_REMAIN__' => (string) price2num($total, 'MT'),
			'__AMOUNT_REMAIN_FORMATTED__' => $formatted,
			'__AMOUNT_REMAIN_FORMATED__' => $formatted,	// Old spelling kept by the core
		);
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
			$groups[($row->next->percustomer || $forcegroup) ? $row->socid.'_'.$row->next->rowid : 'i'.$row->id][] = $row;
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
			$outputlangs->loadLangs(array('main', 'bills'));
		}

		$tpl = $this->getTemplate($level, $outputlangs, $user);
		if (!is_object($tpl)) {
			$langs->load('errors');
			$this->errors[] = $refs.': '.$langs->trans('ErrorFailedToFindEmailTemplate', $level->template_label);
			return 0;
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
	 * @return	int					Number of events created, <0 if KO
	 */
	public function addPromise(User $user, array $rows, $date, $note)
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
