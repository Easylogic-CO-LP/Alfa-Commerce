<?php
defined('_JEXEC') or die;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
extract($displayData);
if (empty($xml)) { echo '<div class="alert alert-warning">' . Text::_('COM_ALFA_LOGS_NO_SCHEMA') . '</div>'; return; }
if (empty($logData)) { echo '<div class="alert alert-info">' . Text::_('COM_ALFA_LOGS_NO_ENTRIES') . '</div>'; return; }
$mk = fn($l,$t) => (object)['label'=>Text::_($l),'type'=>$t];
$fl = [];
foreach ($xml->fields->fieldset->field as $f) $fl[(string)$f['name']] = $mk((string)$f['label'],(string)$f['mysql_type']);
$first = reset($logData);
$hdrs  = is_array($first) ? array_keys($first) : array_keys(get_object_vars($first));
$th = '';
foreach ($hdrs as $h) { if (!isset($fl[$h])) $fl[$h]=$mk(ucfirst(str_replace('_',' ',$h)),''); $th.='<th>'.$fl[$h]->label.'</th>'; }
$tb = '';
foreach ($logData as $log) {
    $tb.='<tr>';
    foreach ($hdrs as $h) {
        $v = is_array($log)?htmlspecialchars($log[$h]??''):htmlspecialchars($log->$h??'');
        $t = $fl[$h]->type??'';
        $tb.=(($t==='datetime'||$t==='date')&&!empty($v)&&$v!=='0000-00-00 00:00:00')
            ?'<td>'.HTMLHelper::_('date',$v,Text::_('DATE_FORMAT_LC6')).'</td>'
            :'<td>'.$v.'</td>';
    }
    $tb.='</tr>';
}
echo '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr>'.$th.'</tr></thead><tbody>'.$tb.'</tbody></table></div>';
