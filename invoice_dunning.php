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
 * \file    dunning/invoice_dunning.php
 * \ingroup dunning
 * \brief   Tab Reminders of a customer invoice: state, actions and history of the reminders
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
dol_include_once('/dunning/class/dunning.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'companies', 'mails', 'dunning@dunning'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$object = new Facture($db);
if ($object->fetch($id, $ref) <= 0) {
	recordNotFound();
}
restrictedArea($user, 'facture', $object->id);
if (!isModEnabled('dunning') || !$user->hasRight('dunning', 'read') || !$user->hasRight('facture', 'lire') || $user->socid > 0) {
	accessforbidden();
}
$permissiontowrite = $user->hasRight('dunning', 'write');
$hookmanager->initHooks(array('invoicedunning', 'globalcard'));

$dunning = new Dunning($db);
$permissiontosend = $dunning->canSend($user);
$rows = $dunning->getInvoices($user, dol_now(), array(), 0, $object->id);
$row = (is_array($rows) ? ($rows[$object->id] ?? null) : null);	// None if paid, dispute open, customer excluded or out of scope


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook) && $action == 'confirm_send' && $confirm == 'yes' && $permissiontosend && $row && $row->due) {
	$rows = $dunning->getEmailGroup($user, $row);	// With a level of one email per customer, its other invoices due at this level too
	$result = (is_array($rows) ? $dunning->send($user, $rows) : -1);
	setEventMessages($result < 0 ? $dunning->error : dol_nl2br($dunning->output), $dunning->errors, ($result ? 'errors' : 'mesgs'));
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}
if (empty($reshook) && $action == 'confirm_promise' && $confirm == 'yes' && $permissiontowrite && $row) {
	// Noon of the day in the time zone of the server, like the dates of the reminders: the same day for every reader
	if ($dunning->addPromise($user, array($row), GETPOSTDATE('promisedate', '12:00:00', 'tzserver'), GETPOST('promisenote', 'alphanohtml'), (float) price2num(GETPOST('promiseamount', 'alpha'))) < 0) {
		setEventMessages($dunning->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}
if (empty($reshook) && $action == 'confirm_penaltyinvoice' && $confirm == 'yes' && $permissiontowrite && $user->hasRight('facture', 'creer') && $row) {
	$penaltyid = $dunning->createPenaltyInvoice($user, $row);
	if ($penaltyid < 0) {
		setEventMessages($dunning->error, null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	} else {
		header('Location: '.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$penaltyid);
	}
	exit;
}


/*
 * View
 */

$form = new Form($db);
$recipients = ($row ? $dunning->getRecipients(array($row)) : array());
$to = (is_array($recipients) && !empty($recipients[$object->id]) ? implode(', ', $recipients[$object->id]) : '');
$events = $dunning->getEvents(array($object->id));

llxHeader('', $langs->trans('InvoiceCustomer').' - '.$langs->trans('Dunning'), '', '', 0, 0, '', '', '', 'mod-dunning page-card');

$object->fetch_thirdparty();
$object->totalpaid = $object->getSommePaiement();	// For the status shown by the banner
$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'dunning', $langs->trans('InvoiceCustomer'), -1, $object->picto);
$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">'.$object->thirdparty->getNomUrl(1, 'customer').'</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '', 0, '', '', 1);
print dol_get_fiche_end();

if ($action == 'send' && $row && $row->due) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('SendRemindByMail'), dolPrintLabel($langs->transnoentitiesnoconv('ConfirmDunningRemindNow', $row->next->label, $to)), 'confirm_send', '', 0, 1);
}
if ($action == 'promise' && $row) {
	$formquestion = array(
		array('type' => 'date', 'name' => 'promisedate', 'label' => $langs->trans('Date'), 'value' => dol_now()),
		array('type' => 'text', 'name' => 'promiseamount', 'label' => $langs->trans('DunningPromiseAmount').' ('.$conf->currency.')', 'morecss' => 'width75'),
		array('type' => 'text', 'name' => 'promisenote', 'label' => $langs->trans('Note')),
	);
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('DunningPromise'), '', 'confirm_promise', $formquestion, 0, 1);
}
$amounts = ($row ? $dunning->getPenalties($row, dol_now()) : array('days' => 0, 'penalties' => 0.0, 'indemnity' => 0.0));
if ($action == 'penaltyinvoice' && $row) {
	$question = $langs->transnoentitiesnoconv('DunningConfirmPenaltyInvoice', price($amounts['penalties'] + $amounts['indemnity'], 0, $langs, 1, -1, -1, $conf->currency));
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('DunningCreatePenaltyInvoice'), $question, 'confirm_penaltyinvoice', '', 0, 1);
}

print '<div class="fichecenter"><table class="border tableforfield centpercent">';
if (!$row) {
	print '<tr><td><span class="opacitymedium">'.$langs->trans('DunningNotReminded').'</span></td></tr>';
} else {
	print '<tr><td class="titlefield">'.$langs->trans('DunningLevelReached').'</td><td>';
	print ($row->last ? dolPrintLabel($row->last->label).' <span class="opacitymedium">'.dol_print_date($row->lastdate, 'dayhour').'</span>' : '').'</td></tr>';
	print '<tr><td>'.$langs->trans('DunningNextReminder').'</td><td>';
	if ($row->next) {
		print ($row->due ? img_warning().' ' : '').dolPrintLabel($row->next->label).' <span class="opacitymedium">'.dol_print_date($row->nextdate, 'day');
		print ' ('.($row->auto ? $langs->trans('Automatic') : $langs->trans('DunningByHand')).')</span>';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('DunningPromise').'</td><td>'.($row->promise ? dol_print_date($row->promise, 'day') : '');
	if ($row->promiseamount > 0) {
		print ' &nbsp; '.price($row->promiseamount, 0, $langs, 1, -1, -1, $conf->currency).' <span class="opacitymedium">('.$langs->trans('DunningPromise'.dol_ucfirst($row->promisestatus)).')</span>';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('MailTo').'</td><td>'.($to ? dolPrintLabel($to) : '<span class="opacitymedium">'.dolPrintLabel($langs->transnoentitiesnoconv('NoRecipientEmail', $object->thirdparty->name)).'</span>').'</td></tr>';
	if ($amounts['penalties'] + $amounts['indemnity'] > 0 || $row->penaltyinvoice) {
		print '<tr><td>'.$langs->trans('DunningPenalties').'</td><td>'.price($amounts['penalties'], 0, $langs, 1, -1, -1, $conf->currency);
		print ' <span class="opacitymedium">('.$langs->trans('DunningPenaltiesDays', $amounts['days']).')</span> + '.$langs->trans('DunningIndemnity').' '.price($amounts['indemnity'], 0, $langs, 1, -1, -1, $conf->currency);
		if ($row->penaltyinvoice) {
			$penaltyinvoice = new Facture($db);
			if ($penaltyinvoice->fetch($row->penaltyinvoice) > 0) {
				print ' &nbsp; '.$penaltyinvoice->getNomUrl(1);
			}
		}
		print '</td></tr>';
	}
}
print '</table></div>';

print '<div class="tabsAction">';
if ($row && $row->next) {
	$params = ($row->due ? array() : array('attr' => array('title' => $langs->trans('DunningNextReminder').' '.dol_print_date($row->nextdate, 'day'))));
	print dolGetButtonAction('', $langs->trans('SendRemindByMail'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=send&token='.newToken(), '', $permissiontosend && $row->due, $params);
}
if ($row) {
	print dolGetButtonAction('', $langs->trans('DunningPromise'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=promise&token='.newToken(), '', $permissiontowrite);
}
if ($row && !$row->penaltyinvoice && $amounts['penalties'] + $amounts['indemnity'] > 0) {
	print dolGetButtonAction('', $langs->trans('DunningCreatePenaltyInvoice'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=penaltyinvoice&token='.newToken(), '', $permissiontowrite && $user->hasRight('facture', 'creer'));
}
if ($object->dispute_status != 1) {
	print dolGetButtonAction('', $langs->trans('DunningDoNotRemind'), 'default', DOL_URL_ROOT.'/compta/facture/card.php?facid='.$object->id.'&action=editdispute_status&token='.newToken(), '', $user->hasRight('facture', 'creer'));
}
print '</div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Event').'</td><td>'.$langs->trans('MailTo').'</td></tr>';
foreach (is_array($events) ? array_reverse($events) : array() as $event) {
	print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($event->datep, 'dayhour').'</td>';
	print '<td><a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $event->id).'">'.img_picto('', 'action', 'class="pictofixedwidth"').dolPrintLabel($event->label).'</a></td>';
	print '<td>'.dolPrintLabel((string) $event->email_to).'</td></tr>';
}
if (empty($events)) {
	print '<tr><td colspan="3"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
