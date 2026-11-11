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
 * \file    dunning/list.php
 * \ingroup dunning
 * \brief   Customers to remind: one line per customer with unpaid invoices in the circuit of the reminders
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
dol_include_once('/dunning/class/dunning.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'companies', 'commercial', 'dunning@dunning'));

if (!isModEnabled('dunning') || !isModEnabled('invoice') || !$user->hasRight('dunning', 'read') || !$user->hasRight('facture', 'lire') || $user->socid > 0) {
	accessforbidden();
}
$permissiontowrite = $user->hasRight('dunning', 'write');

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array:int');
$limit = GETPOSTINT('limit') ?: $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'ASC';
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
$search_level = GETPOSTINT('search_level');
$search_sale = GETPOSTINT('search_sale');
$search_amount = GETPOST('search_amount', 'alpha');
$search_due = GETPOSTINT('search_due');
if (!in_array($sortfield, array('socname', 'nb', 'remain', 'late30', 'leveldate', 'promise', 'nextdate'))) {
	$sortfield = 'nextdate';
}
// Names of the buttons of Form::showFilterButtons(), tested like the lists of the core for all browsers
$removefilter = (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha'));
if ($page < 0 || $removefilter || GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
	$page = 0;
}
if ($removefilter) {
	$search_level = 0;
	$search_sale = 0;
	$search_amount = '';
	$search_due = 0;
}
// Same scope as the list of invoices, also for the actions: a sales representative only sees its customers
if (!$user->hasRight('societe', 'client', 'voir')) {
	$search_sale = $user->id;
}

$param = ($limit != $conf->liste_limit ? '&limit='.((int) $limit) : '');
$param .= ($search_level > 0 ? '&search_level='.$search_level : '').($search_sale > 0 ? '&search_sale='.$search_sale : '');
$param .= ($search_amount !== '' ? '&search_amount='.urlencode($search_amount) : '').($search_due ? '&search_due=1' : '');

$dunning = new Dunning($db);
$permissiontosend = $dunning->canSend($user);
$hookmanager->initHooks(array('dunninglist'));

$arrayofmassactions = array();
if ($permissiontosend) {
	$arrayofmassactions['preremind'] = $langs->trans('SendRemindByMail');
	$arrayofmassactions['presummary'] = $langs->trans('DunningSendSummary');
}
if ($permissiontowrite) {
	$arrayofmassactions['prepromise'] = $langs->trans('DunningPromise');
	if (isModEnabled('category') && getDolGlobalInt('DUNNING_EXCLUDED_CATEGORY') > 0 && $user->hasRight('societe', 'creer')) {
		$arrayofmassactions['predonotremind'] = $langs->trans('DunningDoNotRemind');
	}
}


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha') || !GETPOST('confirmmassaction', 'alpha') || !isset($arrayofmassactions[$massaction])) {
	$massaction = '';
}
if ($massaction && empty($toselect)) {
	setEventMessages($langs->trans('NoRecordSelected'), null, 'warnings');
	$massaction = '';
}

$parameters = array('arrayofselected' => $toselect);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $dunning, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook) && $confirm == 'yes' && $permissiontowrite && in_array($action, array('confirm_remind', 'confirm_summary', 'confirm_promise', 'confirm_donotremind')) && !empty($toselect)) {
	$rows = $dunning->getInvoices($user, dol_now(), $toselect, $search_sale);
	if (!is_array($rows)) {
		$result = -1;
	} elseif ($action == 'confirm_promise') {	// Test on permission already done
		// Noon of the day in the time zone of the server, like the dates of the reminders: the same day for every reader
		$result = $dunning->addPromise($user, $rows, GETPOSTDATE('promisedate', '12:00:00', 'tzserver'), GETPOST('promisenote', 'alphanohtml'));
		$dunning->output = $langs->trans('RecordSaved');
	} elseif ($action == 'confirm_donotremind') {	// Test on permission already done
		$result = $dunning->excludeThirdparties($user, array_column($rows, 'socid'));
		$dunning->output = $langs->trans('RecordSaved');
	} else {
		foreach ($rows as $id => $row) {
			if (!$row->due) {	// Manual levels are allowed here: this is the decision of a human
				unset($rows[$id]);
			}
		}
		$result = $dunning->send($user, $rows, ($action == 'confirm_summary' ? 1 : 0));
	}
	if ($result < 0) {
		setEventMessages($dunning->error, $dunning->errors, 'errors');
	} else {
		setEventMessages(dol_nl2br($dunning->output), $dunning->errors, ($dunning->errors ? 'warnings' : 'mesgs'));
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?'.ltrim($param, '&'));
	exit;
}


/*
 * View
 */

$form = new Form($db);
$formother = new FormOther($db);
$societe = new Societe($db);

// One line per customer with the invoices in the circuit: a level sent, due now, or promised (counted like the dashboard)
$customers = $dunning->getCustomers($user, dol_now(), $search_sale, $search_due);
if (!is_array($customers)) {
	setEventMessages($dunning->error, null, 'errors');
	$customers = array();
}
foreach ($customers as $socid => $customer) {
	if (($search_level > 0 && (!$customer['level'] || $customer['level']->rowid != $search_level))
		|| ($search_amount !== '' && $customer['remain'] < (float) price2num($search_amount))) {
		unset($customers[$socid]);
	}
}
$customers = dol_sort_array($customers, $sortfield, $sortorder);
$nbtotalofrecords = count($customers);
if ($limit * $page >= $nbtotalofrecords) {	// A filter reduced the list below the page asked
	$page = 0;
}
$customers = array_slice($customers, $limit * $page, $limit);
$num = count($customers);

$levellabels = array();
$levels = $dunning->fetchLevels();
foreach (is_array($levels) ? $levels : array() as $level) {
	$levellabels[$level->rowid] = $level->label;
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

$title = $langs->trans('Dunning');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dunning page-list');

print '<form method="POST" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dolPrintHTMLForAttribute($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dolPrintHTMLForAttribute($sortorder).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';

print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'fa-bell', 0, '', '', $limit, 0, 0, 1);

if ($massaction && $permissiontowrite) {
	$promisequestions = array(
		array('type' => 'date', 'name' => 'promisedate', 'label' => $langs->trans('Date'), 'value' => dol_now()),
		array('type' => 'text', 'name' => 'promisenote', 'label' => $langs->trans('Note')),
	);
	print $form->formconfirm($_SERVER['PHP_SELF'], $arrayofmassactions[$massaction], $langs->trans('DunningConfirmMassAction', count($toselect)), 'confirm_'.preg_replace('/^pre/', '', $massaction), ($massaction == 'prepromise' ? $promisequestions : array()), 1, 0, 200, 500, 1);
}

$moreforfilter = '<div class="divsearchfield">'.$langs->trans('DunningLevelReached').': '.$form->selectarray('search_level', $levellabels, $search_level, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth200').'</div>';
if ($user->hasRight('user', 'user', 'lire')) {
	$moreforfilter .= '<div class="divsearchfield">'.$langs->trans('SalesRepresentatives').': '.$formother->select_salesrepresentatives($search_sale, 'search_sale', $user, 0, 1, 'maxwidth200').'</div>';
}
$moreforfilter .= '<div class="divsearchfield">'.$langs->trans('RemainderToPay').' &gt;= <input type="text" class="width75" name="search_amount" value="'.dolPrintHTMLForAttribute($search_amount).'"></div>';
$moreforfilter .= '<div class="divsearchfield"><input type="checkbox" id="search_due" name="search_due" value="1"'.($search_due ? ' checked' : '').'> <label for="search_due">'.$langs->trans('DunningCustomersToRemind').'</label></div>';
print '<div class="liste_titre liste_titre_bydiv centpercent">'.$moreforfilter.'</div>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter"><td class="liste_titre" colspan="7"></td>';
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListOption', $parameters, $dunning, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td></tr>';
print '<tr class="liste_titre">';
print_liste_field_titre('Customer', $_SERVER['PHP_SELF'], 'socname', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Invoices', $_SERVER['PHP_SELF'], 'nb', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('RemainderToPay', $_SERVER['PHP_SELF'], 'remain', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('DunningLateMoreThan30Days', $_SERVER['PHP_SELF'], 'late30', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('DunningLevelReached', $_SERVER['PHP_SELF'], 'leveldate', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DunningPromise', $_SERVER['PHP_SELF'], 'promise', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('DunningNextReminder', $_SERVER['PHP_SELF'], 'nextdate', '', $param, '', $sortfield, $sortorder);
$parameters = array('param' => $param, 'sortfield' => $sortfield, 'sortorder' => $sortorder);
$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters, $dunning, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
print_liste_field_titre($massactionbutton ? $form->showCheckAddButtons('checkforselect', 1) : '', $_SERVER['PHP_SELF'], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

foreach ($customers as $customer) {
	$societe->id = $customer['socid'];
	$societe->name = $customer['socname'];
	$societe->client = 1;
	print '<tr class="oddeven">';
	print '<td class="tdoverflowmax200">'.$societe->getNomUrl(1, 'customer').'</td>';
	print '<td class="right">'.$customer['nb'].'</td>';
	print '<td class="right amount">'.price($customer['remain'], 0, $langs, 1, -1, -1, $conf->currency).'</td>';
	print '<td class="right amount">'.($customer['late30'] ? price($customer['late30'], 0, $langs, 1, -1, -1, $conf->currency) : '').'</td>';
	print '<td>'.($customer['level'] ? dolPrintLabel($customer['level']->label).' <span class="opacitymedium">'.dol_print_date($customer['leveldate'], 'day').'</span>' : '').'</td>';
	print '<td class="center">'.($customer['promise'] ? dol_print_date($customer['promise'], 'day') : '').'</td>';
	print '<td>';
	if ($customer['next']) {
		print ($customer['due'] ? img_warning().' ' : '').dolPrintLabel($customer['next']->label).' <span class="opacitymedium">'.dol_print_date($customer['nextdate'], 'day');
		print ($customer['byhand'] ? ' ('.$langs->trans('DunningByHand').')' : '').'</span>';
	}
	print '</td>';
	$parameters = array('customer' => $customer);
	$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $dunning, $action); // Note that $action and $object may have been modified by hook
	print $hookmanager->resPrint;
	print '<td class="nowrap center"><input id="cb'.$customer['socid'].'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$customer['socid'].'"'.(in_array($customer['socid'], $toselect) ? ' checked' : '').'></td>';
	print '</tr>';
}
if ($num == 0) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
