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
 * \file    dunning/stats.php
 * \ingroup dunning
 * \brief   Statistics of the reminders of a year: reminders sent, invoices reminded, amount paid within 30 days, days to the full payment
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
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'dunning@dunning'));

if (!isModEnabled('dunning') || !isModEnabled('invoice') || !$user->hasRight('dunning', 'read') || !$user->hasRight('facture', 'lire') || $user->socid > 0) {
	accessforbidden();
}
$year = (GETPOSTINT('year') ?: (int) dol_print_date(dol_now(), '%Y'));


/*
 * View
 */

$dunning = new Dunning($db);
$stats = $dunning->getStatistics($user, dol_mktime(0, 0, 0, 1, 1, $year), dol_mktime(23, 59, 59, 12, 31, $year));
if (!is_array($stats)) {
	setEventMessages($dunning->error, null, 'errors');
	$stats = array('months' => array(), 'levels' => array());
}

llxHeader('', $langs->trans('DunningStatistics'), '', '', 0, 0, '', '', '', 'mod-dunning page-stats');
$nav = '<a href="'.$_SERVER['PHP_SELF'].'?year='.($year - 1).'">'.img_previous().'</a> '.$year.' <a href="'.$_SERVER['PHP_SELF'].'?year='.($year + 1).'">'.img_next().'</a>';
print load_fiche_titre($langs->trans('DunningStatistics'), $nav, 'fa-bell');
print '<div class="opacitymedium">'.$langs->trans('DunningStatsHelp').'</div><br>';

$data = array();
$months = array();	// The 12 months in order, empty ones too
$total = array('reminders' => 0, 'invoices' => 0, 'paid30' => 0.0);
for ($m = 1; $m <= 12; $m++) {
	$month = $stats['months'][sprintf('%04d-%02d', $year, $m)] ?? array('reminders' => 0, 'invoices' => 0, 'paid30' => 0.0, 'delay' => 0.0, 'closed' => 0);
	$data[] = array(dol_print_date(dol_mktime(12, 0, 0, $m, 1, $year), '%b'), $month['reminders'], $month['invoices']);
	foreach ($total as $field => $value) {
		$total[$field] = $value + $month[$field];
	}
	$months[$m] = $month;
}
$graph = new DolGraph();
if (!$graph->isGraphKo()) {
	$graph->SetData($data);
	$graph->SetLegend(array($langs->trans('DunningStatsReminders'), $langs->trans('DunningStatsInvoices')));
	$graph->SetType(array('bars', 'bars'));
	$graph->SetWidth(DolGraph::getDefaultGraphSizeForStats('width'));
	$graph->SetHeight(DolGraph::getDefaultGraphSizeForStats('height'));
	$graph->SetTitle($langs->trans('DunningStatsByMonth'));
	$graph->draw('dunningstats', '');
	print $graph->show();
}

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Month').'</td><td class="right">'.$langs->trans('DunningStatsReminders').'</td><td class="right">'.$langs->trans('DunningStatsInvoices').'</td>';
print '<td class="right">'.$langs->trans('DunningStatsPaid30').'</td><td class="right">'.$langs->trans('DunningStatsDelay').'</td></tr>';
foreach ($months as $m => $month) {
	print '<tr class="oddeven"><td>'.dol_print_date(dol_mktime(12, 0, 0, $m, 1, $year), '%B %Y').'</td>';
	print '<td class="right">'.$month['reminders'].'</td><td class="right">'.$month['invoices'].'</td>';
	print '<td class="right amount">'.price($month['paid30'], 0, $langs, 1, -1, 'MT', $conf->currency).'</td>';
	print '<td class="right">'.($month['closed'] ? price($month['delay'], 0, $langs, 1, 1).' <span class="opacitymedium">('.$month['closed'].')</span>' : '').'</td></tr>';
}
print '<tr class="liste_total"><td>'.$langs->trans('Total').'</td><td class="right">'.$total['reminders'].'</td><td class="right">'.$total['invoices'].'</td>';
print '<td class="right amount">'.price($total['paid30'], 0, $langs, 1, -1, 'MT', $conf->currency).'</td><td></td></tr>';
print '</table></div><br>';

print load_fiche_titre($langs->trans('DunningStatsByLevel'), '', '');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('DunningLevelReached').'</td><td class="right">'.$langs->trans('DunningStatsReminders').'</td><td class="right">'.$langs->trans('DunningStatsPaid30').'</td></tr>';
foreach ($stats['levels'] as $level) {
	print '<tr class="oddeven"><td>'.dolPrintLabel($level['label']).'</td><td class="right">'.$level['reminders'].'</td>';
	print '<td class="right amount">'.price($level['paid30'], 0, $langs, 1, -1, 'MT', $conf->currency).'</td></tr>';
}
if (empty($stats['levels'])) {
	print '<tr><td colspan="3"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
