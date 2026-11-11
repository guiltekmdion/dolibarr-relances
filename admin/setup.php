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
 * \file    dunning/admin/setup.php
 * \ingroup dunning
 * \brief   Setup of the reminders: excluded customers and levels
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
dol_include_once('/dunning/class/dunning.class.php');
require_once '../lib/dunning.lib.php';

/**
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'bills', 'companies', 'cron', 'dunning@dunning'));

if (!isModEnabled('dunning') || !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$error = 0;
$hookmanager->initHooks(array('dunningsetup', 'globalsetup'));

$formSetup = new FormSetup($db);
if (isModEnabled('category')) {
	$formSetup->newItem('DUNNING_EXCLUDED_CATEGORY')->setAsCategory('customer')->nameText = $langs->trans('DunningExcludedCategory');
}
// Late penalties and recovery indemnity between companies (article L441-10 of the French commercial code): shown in the reminders
$item = $formSetup->newItem('DUNNING_PENALTY_RATE');
$item->nameText = $langs->trans('DunningPenaltyRate');
$item->helpText = $langs->trans('DunningPenaltyRateHelp');
$item->cssClass = 'width75';
$item = $formSetup->newItem('DUNNING_FIXED_INDEMNITY')->setAsPrice();
$item->nameText = $langs->trans('DunningFixedIndemnity');
$item->helpText = $langs->trans('DunningFixedIndemnityHelp');
if (isModEnabled('category')) {
	$formSetup->newItem('DUNNING_NOPENALTY_CATEGORY')->setAsCategory('customer')->nameText = $langs->trans('DunningNoPenaltyCategory');
}

$dunning = new Dunning($db);
$levels = $dunning->fetchLevels();
if (!is_array($levels)) {
	dol_print_error($db, $dunning->error);
	exit;
}


/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'savelevels') {
	$labels = GETPOST('label', 'array:alphanohtml');
	$nbdays = GETPOST('nbdays', 'array:int');
	$types = GETPOST('fk_c_type_contact', 'array:int');
	$templates = GETPOST('fk_email_template', 'array:int');
	$percustomer = GETPOST('percustomer', 'array:int');
	$byhand = GETPOST('byhand', 'array:int');
	$joinnotice = GETPOST('joinnotice', 'array:int');
	$active = GETPOST('active', 'array:int');
	$db->begin();
	foreach (array_merge(array_keys($levels), array(0)) as $id) {	// The levels shown, then the new one: bounded by the levels of the setup
		if (!isset($labels[$id]) || ($id == 0 && trim($labels[0]) === '')) {
			continue;
		}
		$level = array('rowid' => $id, 'label' => trim($labels[$id]), 'nbdays' => (int) ($nbdays[$id] ?? 0), 'fk_c_type_contact' => max(0, (int) ($types[$id] ?? 0)),
			'percustomer' => (empty($percustomer[$id]) ? 0 : 1), 'byhand' => (empty($byhand[$id]) ? 0 : 1), 'joinnotice' => (empty($joinnotice[$id]) ? 0 : 1), 'fk_email_template' => (int) ($templates[$id] ?? 0), 'active' => (empty($active[$id]) ? 0 : 1));
		if ($dunning->saveLevel($user, $level) < 0) {
			setEventMessages($dunning->error, null, 'errors');
			$error++;
			break;
		}
	}
	if ($error) {
		$db->rollback();
	} else {
		$db->commit();
		setEventMessages($langs->trans('RecordSaved'), null);
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}


/*
 * View
 */

$form = new Form($db);

// External contact types of invoices, the billing one for a new level
$types = array();
$billingtype = 0;
$invoice = new Facture($db);
foreach ((array) $invoice->liste_type_contact('external', 'position', 2, 1) as $typeid => $type) {
	$types[$typeid] = $type['label'];
	$billingtype = ($type['code'] == 'BILLING' ? $typeid : $billingtype);
}
$templates = array();
$formmail = new FormMail($db);
$formmail->fetchAllEMailTemplate('facture_send', $user, null, 1);
foreach ($formmail->lines_model as $template) {
	if (empty($template->private)) {	// The scheduled job does not see the private templates of a user
		$templates[$template->id] = $langs->trans(preg_replace('/\(|\)/', '', $template->label)).($template->lang ? ' ('.$template->lang.')' : '');
	}
}

llxHeader('', $langs->trans('DunningSetup'), '', '', 0, 0, '', '', '', 'mod-dunning page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($langs->trans('DunningSetup'), $linkback, 'title_setup');
$head = dunningAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('DunningSetup'), -1, 'dunning@dunning');

$job = new Cronjob($db);
$job->fetch(0, 'Dunning', 'sendReminders');
if ($job->status != Cronjob::STATUS_ENABLED) {
	print info_admin($langs->trans('JobMustBeEnabledFirst', $langs->transnoentitiesnoconv('DunningJob'), '<a href="'.DOL_URL_ROOT.'/cron/list.php">'.$langs->transnoentitiesnoconv('CronList').'</a>'), 0, 0, 'warning');
}
if ($dunning->isCoreJobEnabled()) {
	print info_admin($langs->trans('DunningCoreJobEnabled', $langs->transnoentitiesnoconv('SendEmailsRemindersOnInvoiceDueDate')), 0, 0, 'warning');
}

if (!empty($formSetup->items)) {
	print $formSetup->generateOutput(true);
}

print '<br>';
print load_fiche_titre($langs->trans('DunningLevels'), '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savelevels">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$form->textwithpicto($langs->trans('DunningNbDays'), $langs->trans('DunningNbDaysHelp')).'</td><td>'.$langs->trans('Label').'</td>';
print '<td>'.$langs->trans('DunningRecipient').'</td><td class="center">'.$langs->trans('DunningPerCustomer').'</td>';
print '<td class="center">'.$form->textwithpicto(dol_ucfirst($langs->trans('DunningByHand')), $langs->trans('DunningByHandHelp')).'</td><td class="center">'.$form->textwithpicto($langs->trans('DunningJoinNotice'), $langs->trans('DunningJoinNoticeHelp')).'</td><td>'.$langs->trans('EmailTemplate').'</td><td class="center">'.$langs->trans('Active').'</td></tr>';
$levels[0] = (object) array('label' => '', 'nbdays' => '', 'fk_c_type_contact' => $billingtype, 'percustomer' => 0, 'byhand' => 0, 'joinnotice' => 0, 'fk_email_template' => 0, 'active' => 1, 'template_label' => '');
foreach ($levels as $id => $level) {
	print '<tr class="oddeven">';
	print '<td><input type="number" class="width50" name="nbdays['.$id.']" value="'.dolPrintHTMLForAttribute((string) $level->nbdays).'"></td>';
	print '<td><input type="text" class="minwidth200" maxlength="128" name="label['.$id.']" value="'.dolPrintHTMLForAttribute($level->label).'"></td>';
	print '<td>'.$form->selectarray('fk_c_type_contact['.$id.']', $types, $level->fk_c_type_contact, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150', 0).'</td>';
	print '<td class="center"><input type="checkbox" name="percustomer['.$id.']" value="1"'.($level->percustomer ? ' checked' : '').'></td>';
	print '<td class="center"><input type="checkbox" name="byhand['.$id.']" value="1"'.($level->byhand ? ' checked' : '').'></td>';
	print '<td class="center"><input type="checkbox" name="joinnotice['.$id.']" value="1"'.($level->joinnotice ? ' checked' : '').'></td>';
	print '<td>'.$form->selectarray('fk_email_template['.$id.']', $templates, $level->fk_email_template, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 0);
	if ($id > 0 && empty($level->template_label)) {
		$langs->load('errors');
		print ' '.img_warning($langs->trans('ErrorFailedToFindEmailTemplate', (string) $level->fk_email_template));
	}
	print '</td>';
	print '<td class="center"><input type="checkbox" name="active['.$id.']" value="1"'.($level->active ? ' checked' : '').'></td>';
	print '</tr>';
}
print '</table></div>';
print '<div class="opacitymedium">'.$langs->trans('DunningKeysHelp').'</div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
